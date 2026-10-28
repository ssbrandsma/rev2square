<?php

namespace App\Services\Revival;

use App\Models\EvidenceSource;
use App\Models\Repository;
use App\Models\RepositoryFile;
use Illuminate\Support\Collection;

/**
 * Shared evidence persistence for the REST and archive importers: inventory
 * and score storage, inventory status transitions, evidence links, and the
 * summary helpers. Selection rules live here once so both retrieval paths
 * produce identical snapshots from identical bytes.
 */
trait PersistsEvidence
{
    /**
     * Selection-affecting budgets snapshotted into every import summary.
     * A same-commit re-import with a different snapshot re-runs instead of
     * short-circuiting as up-to-date, so budget increases take effect.
     */
    public const BUDGET_KEYS = [
        'max_files',
        'max_evidence_bytes',
        'max_download_bytes',
        'max_download_file_bytes',
        'max_extraction_bytes',
        'max_chunk_bytes',
        'token_budget',
        'max_inventory_entries',
    ];

    /** @param  array<string, mixed>  $config */
    public static function budgetSnapshot(array $config): array
    {
        $snapshot = [];

        foreach (self::BUDGET_KEYS as $key) {
            $snapshot[$key] = (int) ($config[$key] ?? 0);
        }

        return $snapshot;
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
                // Stored implies eligible: clears stale ineligibility when a
                // retained file no longer passes the current classifier.
                'eligible' => true,
                'evidence_source_id' => $item['source_id'],
            ]);
        }
    }

    /**
     * Manifest file array rebuilt from a stored evidence row (same-commit
     * re-import or second-pass union). persist() resolves it to the existing
     * row by identity hash, so stored bytes and the source id are preserved.
     */
    private function reuseFileArray(EvidenceSource $existing, mixed $score): array
    {
        $content = (string) $existing->content;

        return [
            'path' => $existing->path,
            'url' => $existing->url,
            'content' => $content,
            'size' => strlen($content),
            'is_partial' => (bool) $existing->is_partial,
            'line_ranges' => $existing->line_ranges,
            'original_bytes' => $existing->original_bytes ?? strlen($content),
            'original_lines' => $existing->original_lines,
            'relevance_score' => is_int($score) ? $score : $existing->relevance_score,
            'language' => $existing->language,
            'blob_sha' => $existing->blob_sha,
            'extraction_note' => $existing->extraction_note,
        ];
    }

    /**
     * @param  list<array{path: string}>  $files
     * @param  Collection<string, string>  $subsystems  path => subsystem
     * @return array<string, int> subsystem => stored files
     */
    private function coverage(array $files, Collection $subsystems): array
    {
        $coverage = [];

        foreach ($files as $file) {
            $subsystem = $subsystems->get($file['path'], '(root)');
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

            if (! in_array($skip['reason'], ['file count limit reached', 'download budget exhausted', 'evidence byte budget exhausted', 'evidence token budget exhausted', 'download failed'], true)) {
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
