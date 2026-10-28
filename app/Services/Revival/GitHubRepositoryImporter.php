<?php

namespace App\Services\Revival;

use App\Models\Repository;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubException;
use App\Services\GitHub\GitHubTimeoutException;
use App\Services\GitHub\RateLimitedException;

/**
 * Orchestrates one bounded repository import in two stages:
 *
 * 1. Inventory — discover and classify every blob at HEAD (metadata only).
 * 2. Evidence  — score, plan, download and store a budgeted evidence set.
 *
 * Inventory never depends on API traversal order, and evidence selection
 * never depends on filenames alone: both stages are deterministic.
 *
 * Metric semantics (all scoped to the repository's current commit SHA):
 * - discovered/blobs: inventory rows recorded for the commit.
 * - eligible: inventory rows eligible for evidence.
 * - downloaded: files fetched from the API during the latest import pass
 *   (same-commit re-imports reuse stored bytes without re-downloading).
 * - stored/files: current evidence rows for the commit. The stored set is
 *   a monotonic union across re-imports: tighter budgets never delete
 *   evidence, they only stop adding to it.
 * - skipped / "Excluded files (N)": inventory paths without stored
 *   evidence, each listed exactly once with its current reason. A stored
 *   path is never listed as skipped.
 * - excluded_by_reason: tally of skipped; always sums to N.
 * - missing_relevant: top fetchable skipped paths by relevance score.
 *
 * Re-importing the same commit with unchanged budgets is a no-op; with
 * changed budgets it imports incrementally (union, deterministic).
 */
class GitHubRepositoryImporter
{
    use PersistsEvidence;

    public function __construct(
        private readonly GitHubClient $client,
        private readonly EvidenceManifestBuilder $manifest,
    ) {}

    public static function fromConfig(): self
    {
        return new self(GitHubClient::fromConfig(), new EvidenceManifestBuilder);
    }

    /**
     * @return array{up_to_date: bool, manifest: list<array<string, mixed>>, total_bytes: int, approx_tokens: int, skipped: list<array{path: string, reason: string}>, warnings: list<string>, inventory: array<string, mixed>}
     */
    public function import(Repository $repository, ?string $branch = null): array
    {
        $config = config('revival.github');
        $warnings = [];

        $meta = $this->client->getRepo($repository->owner, $repository->name);
        $defaultBranch = $meta['default_branch'] ?? 'main';

        $repository->forceFill([
            'default_branch' => $defaultBranch,
            'meta' => [
                'description' => $meta['description'] ?? null,
                'language' => $meta['language'] ?? null,
                'size_kb' => $meta['size'] ?? null,
                'stars' => $meta['stargazers_count'] ?? null,
                'forks' => $meta['forks_count'] ?? null,
                'open_issues' => $meta['open_issues_count'] ?? null,
                'pushed_at' => $meta['pushed_at'] ?? null,
            ],
        ])->save();

        $sha = $this->client->getBranchSha($repository->owner, $repository->name, $branch ?? $defaultBranch);

        if ($repository->last_commit_sha === $sha
            && $repository->currentEvidence()->exists()
            && $repository->currentInventory()->exists()
            && ($repository->last_import_summary['budgets'] ?? null) == self::budgetSnapshot($config)) {
            $repository->forceFill(['last_synced_at' => now(), 'import_status' => 'ready', 'import_started_at' => null, 'next_retry_at' => null, 'import_stage' => null])->save();

            return [
                'up_to_date' => true,
                'manifest' => [],
                'total_bytes' => 0,
                'approx_tokens' => 0,
                'skipped' => [],
                'warnings' => [],
                'inventory' => $this->storedInventorySummary($repository),
            ];
        }

        // Same-commit re-import (e.g. after a budget change): identical blobs,
        // so stored bytes are reused without re-downloading and the stored
        // set only grows. New commits start from an empty set.
        $existingEvidence = $repository->last_commit_sha === $sha
            ? $repository->currentEvidence()->get()->keyBy('path')
            : collect();

        // Legacy imports (evidence but no inventory rows) fall through to a
        // full re-import so inventory and evidence stay consistent.
        $tree = $this->fetchTree($repository, $sha, $config, $warnings);

        $inventory = InventoryBuilder::build($tree, $config);

        if ($inventory['capped']) {
            $warnings[] = sprintf(
                'Inventory capped at %d entries; %d further paths were not recorded.',
                count($inventory['entries']),
                $inventory['over_cap']
            );
        }

        $this->storeInventory($repository, $sha, $inventory['entries']);

        $profile = RelevanceScorer::profile($inventory['entries']);
        $readmeRefs = $this->collectReadmeRefs($repository, $sha, $inventory['entries']);

        $scored = [];

        foreach ($inventory['entries'] as $entry) {
            if (! $entry['eligible']) {
                continue;
            }

            $scored[] = $entry + RelevanceScorer::score($entry, $profile, $readmeRefs, $config);
        }

        $this->storeScores($repository, $sha, $scored);

        ['queue' => $queue, 'skipped' => $plannedSkips] = EvidencePlanner::plan(
            array_map(fn ($s) => [
                'path' => $s['path'],
                'size_bytes' => $s['size_bytes'],
                'score' => $s['score'],
                'subsystem' => $s['subsystem'],
                'overview' => $s['overview'],
            ], $scored),
            $config
        );

        $byPath = collect($scored)->keyBy('path');
        $skipped = [];
        $downloaded = 0;

        foreach ($inventory['entries'] as $entry) {
            if (! $entry['eligible']) {
                $skipped[] = ['path' => $entry['path'], 'reason' => $entry['skip_reason'] ?? 'unsupported format'];
            }
        }

        foreach ($plannedSkips as $skip) {
            $skipped[] = $skip;
        }

        // Stored evidence wins: a path already imported for this commit is
        // kept (reused below), never re-listed as an exclusion.
        if ($existingEvidence->isNotEmpty()) {
            $skipped = array_values(array_filter(
                $skipped,
                fn ($s) => ! $existingEvidence->has($s['path'])
            ));
        }

        // Ineligible rows already carry status=skipped from storeInventory.
        $this->markInventory($repository, $sha, $queue, $plannedSkips);

        if ($queue === []) {
            $warnings[] = 'No importable files found within the selection rules.';
        }

        $files = [];
        $tokenBudget = (int) $config['token_budget'];
        $maxExtraction = (int) ($config['max_extraction_bytes'] ?? $config['max_file_bytes']);
        $maxChunk = (int) ($config['max_chunk_bytes'] ?? 20000);
        $approxTokens = 0;

        foreach ($queue as $item) {
            $entry = $byPath[$item['path']];

            $reused = $existingEvidence->get($item['path']);

            if ($reused !== null) {
                // Identical blob: reuse stored bytes without another download.
                // Always kept — a stored file is never budget-skipped twice —
                // but still counted so later files face the true remainder.
                $approxTokens += (int) ceil(strlen((string) $reused->content) / 4);
                $files[] = $this->reuseFileArray($reused, $entry['score']);

                continue;
            }

            try {
                $fetched = $this->client->getFileContent(
                    $repository->owner, $repository->name, $item['path'], $sha
                );
            } catch (RateLimitedException|GitHubTimeoutException $e) {
                // Retryable at the job level with backoff; never half-import.
                throw $e;
            } catch (GitHubException $e) {
                $skipped[] = ['path' => $item['path'], 'reason' => 'download failed'];
                $this->setInventoryStatus($repository, $sha, $item['path'], 'failed', 'download failed');

                continue;
            }

            $content = $fetched['content'];

            if (! mb_check_encoding($content, 'UTF-8')) {
                $skipped[] = ['path' => $item['path'], 'reason' => 'not UTF-8 text'];
                $this->setInventoryStatus($repository, $sha, $item['path'], 'skipped', 'not UTF-8 text');

                continue;
            }

            if (str_contains($content, "\0")) {
                $skipped[] = ['path' => $item['path'], 'reason' => 'binary content'];
                $this->setInventoryStatus($repository, $sha, $item['path'], 'skipped', 'binary content');

                continue;
            }

            if ($content === '') {
                $skipped[] = ['path' => $item['path'], 'reason' => 'empty file'];
                $this->setInventoryStatus($repository, $sha, $item['path'], 'skipped', 'empty file');

                continue;
            }

            $downloaded++;
            $this->setInventoryStatus($repository, $sha, $item['path'], 'downloaded');

            $extracted = FileExtractor::extract($item['path'], $content, $maxExtraction, $maxChunk);
            $entryTokens = (int) ceil(strlen($extracted['content']) / 4);

            if ($approxTokens + $entryTokens > $tokenBudget) {
                $skipped[] = ['path' => $item['path'], 'reason' => 'evidence token budget exhausted'];
                $this->setInventoryStatus($repository, $sha, $item['path'], 'skipped', 'evidence token budget exhausted');

                continue;
            }

            $approxTokens += $entryTokens;
            $files[] = [
                'path' => $item['path'],
                'url' => $fetched['html_url'],
                'content' => $extracted['content'],
                'size' => $fetched['size'],
                'is_partial' => ! $extracted['complete'],
                'line_ranges' => $extracted['ranges'],
                'original_bytes' => $extracted['original_bytes'],
                'original_lines' => $extracted['original_lines'],
                'relevance_score' => $entry['score'],
                'language' => $entry['language'],
                'blob_sha' => $entry['blob_sha'],
                'extraction_note' => $extracted['note'],
            ];
        }

        // Previously stored but no longer queued (e.g. tighter budgets):
        // retained and grandfathered — never deleted, never re-skipped.
        $filePaths = array_flip(array_column($files, 'path'));
        $retained = 0;

        foreach ($existingEvidence as $path => $existing) {
            if (isset($filePaths[$path])) {
                continue;
            }

            $retained++;
            $files[] = $this->reuseFileArray($existing, $byPath->get($path)['score'] ?? $existing->relevance_score);
        }

        if ($retained > 0) {
            $warnings[] = sprintf(
                'Budgets tightened since the previous import; %d previously stored file(s) were retained and are grandfathered outside the current budgets.',
                $retained
            );
        }

        // Defense in depth: a stored path is never an exclusion.
        $storedPaths = array_flip(array_column($files, 'path'));
        $skipped = array_values(array_filter($skipped, fn ($s) => ! isset($storedPaths[$s['path']])));

        // Deterministic skip order for stable summaries.
        usort($skipped, fn ($a, $b) => [$a['path'], $a['reason']] <=> [$b['path'], $b['reason']]);

        $partial = count(array_filter($files, fn ($f) => $f['is_partial']));

        $subsystems = collect($inventory['entries'])->mapWithKeys(fn ($e) => [$e['path'] => $e['subsystem']]);

        $result = $this->manifest->persist($repository, $sha, $files, $skipped, $warnings, [
            'inventory' => [
                'discovered' => $inventory['discovered'],
                'blobs' => $inventory['blobs'],
                'eligible' => count($scored),
                'downloaded' => $downloaded,
                'stored' => count($files),
                'partial' => $partial,
                'capped' => $inventory['capped'],
                'over_cap' => $inventory['over_cap'],
            ],
            'coverage' => $this->coverage($files, $subsystems),
            'excluded_by_reason' => $this->tallyReasons($skipped),
            'missing_relevant' => $this->missingRelevant($skipped, $byPath),
            'budgets' => self::budgetSnapshot($config),
        ]);

        $this->linkStored($repository, $sha, $result['manifest']);

        return [
            'up_to_date' => false,
            'manifest' => $result['manifest'],
            'total_bytes' => $result['total_bytes'],
            'approx_tokens' => $result['approx_tokens'],
            'skipped' => $skipped,
            'warnings' => $warnings,
            'inventory' => $repository->fresh()->last_import_summary['inventory'] ?? [],
        ];
    }

    /**
     * Second retrieval pass: fetch specific inventory paths into evidence.
     *
     * Only paths recorded as eligible in the current inventory may be
     * retrieved, and only when they are not stored yet. Bounded and
     * idempotent: re-fetching a stored path is a no-op skip.
     *
     * @param  list<string>  $paths
     * @return array{stored: list<string>, skipped: list<array{path: string, reason: string}>}
     */
    public function fetchAdditionalPaths(Repository $repository, array $paths, int $max = 5): array
    {
        $sha = $repository->last_commit_sha;

        if ($sha === null || ! $repository->isReady()) {
            return ['stored' => [], 'skipped' => []];
        }

        $config = config('revival.github');
        $maxExtraction = (int) ($config['max_extraction_bytes'] ?? $config['max_file_bytes']);
        $maxChunk = (int) ($config['max_chunk_bytes'] ?? 20000);

        $stored = [];
        $skipped = [];
        $files = [];
        $paths = array_values(array_unique($paths));
        $fetchedCount = 0;

        foreach (array_slice($paths, 0, max(1, $max)) as $path) {
            $row = $repository->inventoryFiles()->where('commit_sha', $sha)->where('path', $path)->first();

            if ($row === null) {
                $skipped[] = ['path' => $path, 'reason' => 'not in inventory'];

                continue;
            }

            if ($row->evidence_source_id !== null) {
                $skipped[] = ['path' => $path, 'reason' => 'already imported'];

                continue;
            }

            if (! $row->eligible) {
                $skipped[] = ['path' => $path, 'reason' => $row->skip_reason ?? 'unsupported format'];

                continue;
            }

            try {
                $fetched = $this->client->getFileContent($repository->owner, $repository->name, $path, $sha);
            } catch (GitHubException $e) {
                $skipped[] = ['path' => $path, 'reason' => 'download failed'];

                continue;
            }

            $fetchedCount++;
            $content = $fetched['content'];

            if (! mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0") || $content === '') {
                $reason = $content === '' ? 'empty file'
                    : (! mb_check_encoding($content, 'UTF-8') ? 'not UTF-8 text' : 'binary content');
                $skipped[] = ['path' => $path, 'reason' => $reason];

                continue;
            }

            $extracted = FileExtractor::extract($path, $content, $maxExtraction, $maxChunk);
            $files[] = [
                'path' => $path,
                'url' => $fetched['html_url'],
                'content' => $extracted['content'],
                'size' => $fetched['size'],
                'is_partial' => ! $extracted['complete'],
                'line_ranges' => $extracted['ranges'],
                'original_bytes' => $extracted['original_bytes'],
                'original_lines' => $extracted['original_lines'],
                'relevance_score' => $row->relevance_score,
                'language' => $row->language,
                'blob_sha' => $row->blob_sha,
                'extraction_note' => $extracted['note'],
            ];
            $stored[] = $path;
        }

        $this->mergeSecondPass($repository, $sha, $files, $stored, $skipped, $fetchedCount, $config);

        return ['stored' => $stored, 'skipped' => $skipped];
    }

    /**
     * Merge a second-pass fetch into the stored summary without breaking its
     * invariants: the manifest stays the union of all current evidence (so
     * files/bytes/tokens are totals), skipped drops newly stored paths and
     * absorbs fresh fetch outcomes, and every derived tally is recomputed.
     *
     * @param  list<array<string, mixed>>  $files
     * @param  list<string>  $stored
     * @param  list<array{path: string, reason: string}>  $skipped
     * @param  array<string, mixed>  $config
     */
    private function mergeSecondPass(
        Repository $repository,
        string $sha,
        array $files,
        array $stored,
        array $skipped,
        int $fetchedCount,
        array $config,
    ): void {
        $summary = $repository->last_import_summary ?? [];
        $priorSkipped = $summary['skipped'] ?? [];

        // Drop newly stored paths; merge fresh fetch outcomes (latest reason
        // wins per path). Non-inventory pseudo-skips are call-site feedback
        // only and must never enter the exclusion list.
        $byPath = [];

        foreach ($priorSkipped as $entry) {
            if (isset($entry['path']) && ! in_array($entry['path'], $stored, true)) {
                $byPath[$entry['path']] = $entry['reason'] ?? 'unsupported format';
            }
        }

        foreach ($skipped as $entry) {
            if (in_array($entry['reason'], ['not in inventory', 'already imported'], true)) {
                continue;
            }

            $byPath[$entry['path']] = $entry['reason'];
        }

        foreach ($repository->currentEvidence()->pluck('path')->all() as $storedPath) {
            unset($byPath[$storedPath]);
        }

        $finalSkipped = [];

        foreach ($byPath as $path => $reason) {
            $finalSkipped[] = ['path' => $path, 'reason' => $reason];
        }

        usort($finalSkipped, fn ($a, $b) => [$a['path'], $a['reason']] <=> [$b['path'], $b['reason']]);

        if ($files === []) {
            if ($finalSkipped !== $priorSkipped) {
                $repository->forceFill(['last_import_summary' => array_merge($summary, [
                    'skipped' => $finalSkipped,
                    'excluded_by_reason' => $this->tallyReasons($finalSkipped),
                ])])->save();
            }

            return;
        }

        // Union manifest: existing rows (from the database) plus the newly
        // downloaded files, deduplicated by path (stored bytes win).
        $unionByPath = [];

        foreach ($repository->currentEvidence()->get() as $row) {
            $unionByPath[$row->path] = $this->reuseFileArray($row, $row->relevance_score);
        }

        foreach ($files as $file) {
            $unionByPath[$file['path']] ??= $file;
        }

        $union = array_values($unionByPath);

        $inventory = $summary['inventory'] ?? [];
        $inventory['downloaded'] = ($inventory['downloaded'] ?? 0) + $fetchedCount;
        $inventory['stored'] = count($union);
        $inventory['partial'] = count(array_filter($union, fn ($f) => (bool) ($f['is_partial'] ?? false)));

        $coverage = $summary['coverage'] ?? [];
        $subsystems = $repository->inventoryFiles()
            ->where('commit_sha', $sha)
            ->whereIn('path', $stored)
            ->pluck('subsystem', 'path');

        foreach ($stored as $path) {
            $subsystem = $subsystems[$path] ?? '(root)';
            $coverage[$subsystem] = ($coverage[$subsystem] ?? 0) + 1;
        }

        ksort($coverage);

        $result = $this->manifest->persist($repository, $sha, $union, $finalSkipped, $summary['warnings'] ?? [], [
            'inventory' => $inventory,
            'coverage' => $coverage,
            'excluded_by_reason' => $this->tallyReasons($finalSkipped),
            'missing_relevant' => array_values(array_filter(
                $summary['missing_relevant'] ?? [],
                fn ($m) => ! in_array($m['path'] ?? null, $stored, true)
            )),
            'imported_at' => $summary['imported_at'] ?? now()->toIso8601String(),
            'budgets' => $summary['budgets'] ?? self::budgetSnapshot($config),
        ]);

        $this->linkStored($repository, $sha, $result['manifest']);
    }

    /**
     * Fetch the recursive tree, falling back to bounded per-subtree fetches
     * when the API truncates the response.
     *
     * @param  list<string>  $warnings
     * @return array<int, array<string, mixed>>
     */
    private function fetchTree(Repository $repository, string $sha, array $config, array &$warnings): array
    {
        $tree = $this->client->getTree($repository->owner, $repository->name, $sha);

        if (! $tree['truncated']) {
            return $tree['tree'];
        }

        $warnings[] = 'Repository tree was truncated by the API; attempting bounded per-directory recovery.';

        $root = $this->client->getTree($repository->owner, $repository->name, $sha, false);
        $merged = [];
        $fetches = 0;
        $maxFetches = max(0, (int) ($config['max_subtree_fetches'] ?? 20));

        $dirs = array_values(array_filter(
            $root['tree'],
            fn ($e) => ($e['type'] ?? '') === 'tree' && isset($e['path'], $e['sha'])
        ));
        usort($dirs, fn ($a, $b) => $a['path'] <=> $b['path']);

        foreach ($root['tree'] as $entry) {
            if (($entry['type'] ?? '') === 'blob') {
                $merged[] = $entry;
            }
        }

        foreach ($dirs as $dir) {
            if ($fetches >= $maxFetches) {
                $warnings[] = 'Subtree recovery hit its fetch budget; inventory covers part of the repository.';

                break;
            }

            try {
                $sub = $this->client->getTree($repository->owner, $repository->name, $dir['sha']);
                $fetches++;
            } catch (RateLimitedException|GitHubTimeoutException $e) {
                throw $e;
            } catch (GitHubException) {
                $warnings[] = "Could not recover directory '{$dir['path']}'; it is missing from the inventory.";

                continue;
            }

            foreach ($sub['tree'] as $entry) {
                if (($entry['type'] ?? '') !== 'blob' || ! isset($entry['path'])) {
                    continue;
                }

                $entry['path'] = $dir['path'].'/'.$entry['path'];
                $merged[] = $entry;
            }

            if ($sub['truncated']) {
                $warnings[] = "Directory '{$dir['path']}' was truncated by the API; its inventory is partial.";
            }
        }

        return $merged;
    }

    /**
     * Fetch overview files first so references from README/docs can boost
     * the relevance of the files they point at.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<string>
     */
    private function collectReadmeRefs(Repository $repository, string $sha, array $entries): array
    {
        $overviews = array_values(array_filter(
            $entries,
            fn ($e) => $e['eligible'] && $e['overview'] && $e['size_bytes'] <= 100000
        ));
        usort($overviews, fn ($a, $b) => $a['path'] <=> $b['path']);

        $paths = array_column($entries, 'path');
        $refs = [];

        foreach (array_slice($overviews, 0, 3) as $overview) {
            try {
                $fetched = $this->client->getFileContent(
                    $repository->owner, $repository->name, $overview['path'], $sha
                );
            } catch (RateLimitedException|GitHubTimeoutException $e) {
                throw $e;
            } catch (GitHubException) {
                continue;
            }

            if (! mb_check_encoding($fetched['content'], 'UTF-8') || str_contains($fetched['content'], "\0")) {
                continue;
            }

            foreach (RelevanceScorer::extractReferences($fetched['content'], $paths) as $ref) {
                $refs[$ref] = true;
            }
        }

        $refs = array_keys($refs);
        sort($refs);

        return $refs;
    }
}
