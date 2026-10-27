<?php

namespace App\Services\Revival;

use App\Models\Repository;
use App\Models\RepositoryFile;
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
 */
class GitHubRepositoryImporter
{
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
            && $repository->currentInventory()->exists()) {
            $repository->forceFill(['last_synced_at' => now(), 'import_status' => 'ready'])->save();

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
                $skipped[] = ['path' => $item['path'], 'reason' => 'evidence budget exhausted'];
                $this->setInventoryStatus($repository, $sha, $item['path'], 'skipped', 'evidence budget exhausted');

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

        // Deterministic skip order for stable summaries.
        usort($skipped, fn ($a, $b) => [$a['path'], $a['reason']] <=> [$b['path'], $b['reason']]);

        $partial = count(array_filter($files, fn ($f) => $f['is_partial']));

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
            'coverage' => $this->coverage($files, $byPath),
            'excluded_by_reason' => $this->tallyReasons($skipped),
            'missing_relevant' => $this->missingRelevant($skipped, $byPath),
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

        if ($files !== []) {
            $summary = $repository->last_import_summary ?? [];
            $priorSkipped = $summary['skipped'] ?? [];
            $priorSkipped = array_values(array_filter(
                $priorSkipped,
                fn ($s) => ! in_array($s['path'] ?? null, $stored, true)
            ));

            $result = $this->manifest->persist($repository, $sha, $files, $priorSkipped, $summary['warnings'] ?? [], [
                'inventory' => $summary['inventory'] ?? [],
                'coverage' => $summary['coverage'] ?? [],
                'excluded_by_reason' => $summary['excluded_by_reason'] ?? [],
                'missing_relevant' => array_values(array_filter(
                    $summary['missing_relevant'] ?? [],
                    fn ($m) => ! in_array($m['path'] ?? null, $stored, true)
                )),
            ]);

            $this->linkStored($repository, $sha, $result['manifest']);
        }

        return ['stored' => $stored, 'skipped' => $skipped];
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

    /** @param  list<array<string, mixed>>  $entries */
    private function storeInventory(Repository $repository, string $sha, array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($entries as $entry) {
            $rows[] = [
                'entry_hash' => RepositoryFile::entryHash($repository->id, $entry['path'], $sha),
                'repository_id' => $repository->id,
                'commit_sha' => $sha,
                'path' => $entry['path'],
                'extension' => $entry['extension'],
                'language' => $entry['language'],
                'kind' => $entry['kind'],
                'size_bytes' => $entry['size_bytes'],
                'blob_sha' => $entry['blob_sha'],
                'subsystem' => $entry['subsystem'],
                'eligible' => $entry['eligible'],
                'skip_reason' => $entry['eligible'] ? null : $entry['skip_reason'],
                'status' => $entry['eligible'] ? 'pending' : 'skipped',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Single batched write; on retry the classification refreshes while
        // evidence links (evidence_source_id) are preserved.
        RepositoryFile::upsert($rows, 'entry_hash', [
            'extension', 'language', 'kind', 'size_bytes', 'blob_sha', 'subsystem',
            'eligible', 'skip_reason', 'status', 'updated_at',
        ]);
    }

    /** @param  list<array<string, mixed>>  $scored */
    private function storeScores(Repository $repository, string $sha, array $scored): void
    {
        if ($scored === []) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($scored as $entry) {
            // Full rows: the upsert insert branch must satisfy NOT NULL
            // columns even though these rows always exist already.
            $rows[] = [
                'entry_hash' => RepositoryFile::entryHash($repository->id, $entry['path'], $sha),
                'repository_id' => $repository->id,
                'commit_sha' => $sha,
                'path' => $entry['path'],
                'extension' => $entry['extension'],
                'language' => $entry['language'],
                'kind' => $entry['kind'],
                'size_bytes' => $entry['size_bytes'],
                'blob_sha' => $entry['blob_sha'],
                'subsystem' => $entry['subsystem'],
                'eligible' => true,
                'skip_reason' => null,
                'status' => 'pending',
                'relevance_score' => $entry['score'],
                'score_reasons' => json_encode($entry['reasons']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        RepositoryFile::upsert($rows, 'entry_hash', ['relevance_score', 'score_reasons', 'updated_at']);
    }

    /**
     * @param  list<array{path: string}>  $queue
     * @param  list<array{path: string, reason: string}>  $skipped
     */
    private function markInventory(Repository $repository, string $sha, array $queue, array $skipped): void
    {
        foreach ($queue as $item) {
            $this->setInventoryStatus($repository, $sha, $item['path'], 'selected');
        }

        foreach ($skipped as $skip) {
            $this->setInventoryStatus($repository, $sha, $skip['path'], 'skipped', $skip['reason']);
        }
    }

    private function setInventoryStatus(Repository $repository, string $sha, string $path, string $status, ?string $reason = null): void
    {
        $values = ['status' => $status];

        if ($reason !== null) {
            $values['skip_reason'] = $reason;
        }

        RepositoryFile::where(
            'entry_hash',
            RepositoryFile::entryHash($repository->id, $path, $sha)
        )->update($values);
    }

    /** @param  list<array<string, mixed>>  $manifest */
    private function linkStored(Repository $repository, string $sha, array $manifest): void
    {
        foreach ($manifest as $item) {
            RepositoryFile::where(
                'entry_hash',
                RepositoryFile::entryHash($repository->id, $item['path'], $sha)
            )->update([
                'status' => 'stored',
                'skip_reason' => null,
                'evidence_source_id' => $item['source_id'],
            ]);
        }
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

    /**
     * @param  list<array{path: string}>  $files
     * @return array<string, int> subsystem => stored files
     */
    private function coverage(array $files, mixed $byPath): array
    {
        $coverage = [];

        foreach ($files as $file) {
            $subsystem = $byPath[$file['path']]['subsystem'] ?? '(root)';
            $coverage[$subsystem] = ($coverage[$subsystem] ?? 0) + 1;
        }

        ksort($coverage);

        return $coverage;
    }

    /** @param  list<array{path: string, reason: string}>  $skipped */
    private function tallyReasons(array $skipped): array
    {
        $tally = [];

        foreach ($skipped as $skip) {
            $tally[$skip['reason']] = ($tally[$skip['reason']] ?? 0) + 1;
        }

        ksort($tally);

        return $tally;
    }

    /**
     * Highest-scoring eligible files that were not stored (budget or fetch
     * outcomes), for the UI and the second retrieval pass.
     *
     * @param  list<array{path: string, reason: string}>  $skipped
     * @return list<array{path: string, reason: string, score: int}>
     */
    private function missingRelevant(array $skipped, mixed $byPath): array
    {
        $candidates = [];

        foreach ($skipped as $skip) {
            $entry = $byPath[$skip['path']] ?? null;

            if ($entry === null) {
                continue;
            }

            if (! in_array($skip['reason'], ['low relevance', 'evidence budget exhausted', 'download failed'], true)) {
                continue;
            }

            $candidates[] = [
                'path' => $skip['path'],
                'reason' => $skip['reason'],
                'score' => $entry['score'],
            ];
        }

        usort($candidates, fn ($a, $b) => [$b['score'], $a['path']] <=> [$a['score'], $b['path']]);

        return array_slice($candidates, 0, 10);
    }

    /** @return array<string, mixed> */
    private function storedInventorySummary(Repository $repository): array
    {
        return $repository->last_import_summary['inventory'] ?? [];
    }
}
