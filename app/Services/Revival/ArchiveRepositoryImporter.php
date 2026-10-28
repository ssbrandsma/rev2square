<?php

namespace App\Services\Revival;

use App\Models\Repository;
use App\Services\GitHub\ArchiveDownloader;
use App\Services\GitHub\GitHubClient;

/**
 * Archive-first repository import: resolve one immutable revision, download
 * one source archive, and run the standard inventory/selection pipeline
 * over its local bytes. After revision resolution this path makes no
 * per-file GitHub requests; with a known SHA and a cached archive it makes
 * none at all.
 *
 * Selection, budgets, and persistence are shared with the REST importer via
 * PersistsEvidence, so identical bytes produce identical snapshots. Metric
 * semantics match the REST importer except that "downloaded" counts files
 * read from the archive rather than fetched from the API; see the
 * retrieval block in the import summary for how bytes were obtained.
 */
class ArchiveRepositoryImporter
{
    use PersistsEvidence;

    public function __construct(
        private readonly GitHubClient $client,
        private readonly ArchiveDownloader $archives,
        private readonly EvidenceManifestBuilder $manifest,
    ) {}

    /**
     * @return array{up_to_date: bool, manifest: list<array<string, mixed>>, total_bytes: int, approx_tokens: int, skipped: list<array{path: string, reason: string}>, warnings: list<string>, inventory: array<string, mixed>}
     */
    public function import(Repository $repository, ?string $branch = null): array
    {
        $config = config('revival.github');
        $warnings = [];
        $oldSha = $repository->last_commit_sha;

        $this->stage($repository, 'resolving revision');
        [$sha, $resolution, $requestedRef] = $this->resolve($repository, $branch);

        if ($oldSha === $sha
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

        $this->stage($repository, 'downloading archive');
        $archive = $this->archives->get($repository->owner, $repository->name, $sha);

        $this->stage($repository, 'inspecting archive');
        $inspected = ArchiveInspector::inspect($archive['path'], $config);
        $warnings = array_merge($warnings, $inspected['warnings']);

        $dupes = [];

        foreach ($inspected['explicit'] as $row) {
            if ($row['skip_reason'] === 'duplicate archive entry') {
                $dupes[] = $row['path'];
            }
        }

        if ($dupes !== []) {
            sort($dupes);
            $warnings[] = 'Duplicate archive entries ignored (first occurrence kept): '.implode(', ', array_slice($dupes, 0, 10));
        }

        $inventory = InventoryBuilder::build($inspected['tree'], $config);

        if ($inventory['capped']) {
            $warnings[] = sprintf(
                'Inventory capped at %d entries; %d further paths were not recorded.',
                count($inventory['entries']),
                $inventory['over_cap']
            );
        }

        $entries = array_merge($inventory['entries'], $inspected['explicit']);
        usort($entries, fn ($a, $b) => $a['path'] <=> $b['path']);

        $this->storeInventory($repository, $sha, $entries);

        $indexByPath = [];

        foreach ($inspected['tree'] as $node) {
            $indexByPath[$node['path']] = $node['index'];
        }

        $profile = RelevanceScorer::profile($entries);
        $readmeRefs = $this->collectReadmeRefs($archive['path'], $indexByPath, $entries);

        $scored = [];

        foreach ($entries as $entry) {
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

        $this->stage($repository, 'selecting evidence');

        // Same-commit re-import reuses stored bytes without re-reading them;
        // new commits start from an empty set while old rows are kept.
        $existingEvidence = $oldSha === $sha
            ? $repository->currentEvidence()->get()->keyBy('path')
            : collect();

        $byPath = collect($scored)->keyBy('path');
        $skipped = [];
        $downloaded = 0;

        foreach ($entries as $entry) {
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
        $maxRead = (int) ($config['max_download_file_bytes'] ?? 1000000);
        $approxTokens = 0;

        foreach ($queue as $item) {
            $entry = $byPath[$item['path']];

            $reused = $existingEvidence->get($item['path']);

            if ($reused !== null) {
                // Identical blob: reuse stored bytes without another read.
                // Always kept — a stored file is never budget-skipped twice —
                // but still counted so later files face the true remainder.
                $approxTokens += (int) ceil(strlen((string) $reused->content) / 4);
                $files[] = $this->reuseFileArray($reused, $entry['score']);

                continue;
            }

            $content = isset($indexByPath[$item['path']])
                ? ArchiveInspector::readEntry($archive['path'], $indexByPath[$item['path']], $maxRead)
                : null;

            if ($content === null) {
                $skipped[] = ['path' => $item['path'], 'reason' => 'download failed'];
                $this->setInventoryStatus($repository, $sha, $item['path'], 'failed', 'download failed');

                continue;
            }

            if (self::isLfsPointer($content)) {
                $skipped[] = ['path' => $item['path'], 'reason' => 'lfs pointer content not stored'];
                $this->setInventoryStatus($repository, $sha, $item['path'], 'skipped', 'lfs pointer content not stored');

                continue;
            }

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
                'url' => null,
                'content' => $extracted['content'],
                'size' => strlen($content),
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

        $subsystems = collect($entries)->mapWithKeys(fn ($e) => [$e['path'] => $e['subsystem']]);

        $storedHashes = [];

        foreach ($files as $file) {
            $storedHashes[$file['path']] = hash('sha256', $file['content']);
        }

        $result = $this->manifest->persist($repository, $sha, $files, $skipped, $warnings, [
            'inventory' => [
                'discovered' => $inspected['entry_count'],
                'blobs' => count($entries),
                'eligible' => count($scored),
                'downloaded' => $downloaded,
                'stored' => count($files),
                'partial' => $partial,
                'capped' => $inventory['capped'] || $inspected['capped'],
                'over_cap' => $inventory['over_cap'],
            ],
            'coverage' => $this->coverage($files, $subsystems),
            'excluded_by_reason' => $this->tallyReasons($skipped),
            'missing_relevant' => $this->missingRelevant($skipped, $byPath),
            'budgets' => self::budgetSnapshot($config),
            'retrieval' => [
                'method' => 'archive',
                'requested_ref' => $requestedRef,
                'resolution' => $resolution,
                'resolved_sha' => $sha,
                'archive_sha256' => $archive['sha256'],
                'archive_bytes' => $archive['bytes'],
                'archive_cached' => $archive['cached'],
                'downloaded_at' => $archive['downloaded_at'],
                'source_url' => $archive['source_url'],
                'expanded_bytes' => $inspected['expanded_bytes'],
            ],
            'diff' => $this->snapshotDiff($repository, $oldSha, $sha, $storedHashes),
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
     * Resolve the requested ref to a full immutable SHA. A 40-hex input is
     * used verbatim (zero metadata requests); otherwise the branch HEAD is
     * resolved through the API. The full SHA is never inferred from archive
     * contents or entry names.
     *
     * @return array{string, string, string} sha, resolution method, requested ref
     */
    private function resolve(Repository $repository, ?string $branch): array
    {
        if (is_string($branch) && preg_match('/^[0-9a-f]{40}$/', $branch)) {
            return [$branch, 'provided-sha', $branch];
        }

        if ($branch === null) {
            $meta = $this->client->getRepo($repository->owner, $repository->name);
            $ref = $meta['default_branch'] ?? 'main';

            $repository->forceFill([
                'default_branch' => $ref,
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
        } else {
            $ref = $branch;
        }

        $sha = $this->client->getBranchSha($repository->owner, $repository->name, $ref);

        return [$sha, 'branch-head', $ref];
    }

    /**
     * Read overview files locally so references from README/docs can boost
     * the relevance of the files they point at. Local reads are free, so
     * unlike the REST path this never spends API quota on scoring.
     *
     * @param  array<string, int>  $indexByPath  path => zip entry index
     * @param  list<array<string, mixed>>  $entries
     * @return list<string>
     */
    private function collectReadmeRefs(string $zipPath, array $indexByPath, array $entries): array
    {
        $overviews = array_values(array_filter(
            $entries,
            fn ($e) => $e['eligible'] && $e['overview'] && $e['size_bytes'] <= 100000
        ));
        usort($overviews, fn ($a, $b) => $a['path'] <=> $b['path']);

        $paths = array_column($entries, 'path');
        $refs = [];

        foreach (array_slice($overviews, 0, 3) as $overview) {
            $content = isset($indexByPath[$overview['path']])
                ? ArchiveInspector::readEntry($zipPath, $indexByPath[$overview['path']], 100000)
                : null;

            if ($content === null || self::isLfsPointer($content)) {
                continue;
            }

            if (! mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
                continue;
            }

            foreach (RelevanceScorer::extractReferences($content, $paths) as $ref) {
                $refs[$ref] = true;
            }
        }

        $refs = array_keys($refs);
        sort($refs);

        return $refs;
    }

    /**
     * Added/changed/deleted paths against the previous snapshot. Changed
     * covers paths stored in both snapshots with different bytes; anything
     * never extracted cannot be compared and stays out of the verdict.
     *
     * @param  array<string, string>  $storedHashes  path => sha256 of stored bytes
     */
    private function snapshotDiff(Repository $repository, ?string $oldSha, string $newSha, array $storedHashes): ?array
    {
        if ($oldSha === null || $oldSha === $newSha) {
            return null;
        }

        $oldPaths = $repository->inventoryFiles()->where('commit_sha', $oldSha)->pluck('path')->all();
        $newPaths = $repository->inventoryFiles()->where('commit_sha', $newSha)->pluck('path')->all();
        $oldHashes = $repository->evidenceSources()->where('commit_sha', $oldSha)->pluck('content_hash', 'path')->all();

        $added = array_values(array_diff($newPaths, $oldPaths));
        $deleted = array_values(array_diff($oldPaths, $newPaths));
        sort($added);
        sort($deleted);

        $changed = [];

        foreach ($storedHashes as $path => $hash) {
            if (isset($oldHashes[$path]) && $oldHashes[$path] !== $hash) {
                $changed[] = $path;
            }
        }

        sort($changed);

        return [
            'old_sha' => $oldSha,
            'new_sha' => $newSha,
            'added_count' => count($added),
            'changed_count' => count($changed),
            'deleted_count' => count($deleted),
            'added' => array_slice($added, 0, 100),
            'changed' => array_slice($changed, 0, 100),
            'deleted' => array_slice($deleted, 0, 100),
        ];
    }

    private static function isLfsPointer(string $content): bool
    {
        return str_starts_with($content, "version https://git-lfs.github.com/spec/v1\n")
            && str_contains(substr($content, 0, 1024), "\noid sha256:");
    }

    private function stage(Repository $repository, string $stage): void
    {
        $repository->forceFill(['import_stage' => $stage])->save();
    }
}
