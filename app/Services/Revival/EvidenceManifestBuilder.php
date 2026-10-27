<?php

namespace App\Services\Revival;

use App\Models\EvidenceSource;
use App\Models\Repository;

/**
 * Persists immutable evidence rows and builds the import manifest.
 * Idempotent: rows are keyed by identity_hash (repository|path|commit).
 */
class EvidenceManifestBuilder
{
    /**
     * @param  list<array{path: string, url: ?string, content: string, size: int, is_partial?: bool, line_ranges?: list<array{start: int, end: int}>, original_bytes?: int, original_lines?: int, relevance_score?: int, language?: ?string, blob_sha?: ?string, extraction_note?: ?string}>  $files
     * @param  list<array{path: string, reason: string}>  $skipped
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $extraSummary  Merged into last_import_summary.
     * @return array{manifest: list<array<string, mixed>>, total_bytes: int, approx_tokens: int}
     */
    public function persist(
        Repository $repository,
        string $commitSha,
        array $files,
        array $skipped,
        array $warnings = [],
        array $extraSummary = []
    ): array {
        $manifest = [];
        $totalBytes = 0;
        $now = now();

        foreach ($files as $file) {
            $bytes = strlen($file['content']);
            $totalBytes += $bytes;

            $row = EvidenceSource::firstOrCreate(
                [
                    'identity_hash' => EvidenceSource::identityHash(
                        $repository->id, $file['path'], $commitSha
                    ),
                ],
                [
                    'project_id' => $repository->project_id,
                    'repository_id' => $repository->id,
                    'source_type' => 'github_file',
                    'url' => $file['url'] ?? $this->humanUrl($repository, $file['path'], $commitSha),
                    'path' => $file['path'],
                    'commit_sha' => $commitSha,
                    'content_hash' => hash('sha256', $file['content']),
                    'content' => $file['content'],
                    'is_partial' => $file['is_partial'] ?? false,
                    'line_ranges' => $file['line_ranges'] ?? null,
                    'original_bytes' => $file['original_bytes'] ?? $bytes,
                    'original_lines' => $file['original_lines'] ?? null,
                    'relevance_score' => $file['relevance_score'] ?? null,
                    'language' => $file['language'] ?? null,
                    'blob_sha' => $file['blob_sha'] ?? null,
                    'extraction_note' => $file['extraction_note'] ?? null,
                    'visibility' => 'public',
                    'fetched_at' => $now,
                    'ingestion_status' => 'ok',
                ]
            );

            // firstOrCreate returns the pre-existing row on retry; its stored
            // bytes (not the re-fetched ones) define the manifest entry.
            $storedBytes = strlen((string) $row->content);

            $manifest[] = [
                'source_id' => $row->id,
                'path' => $row->path,
                'url' => $row->url,
                'commit_sha' => $row->commit_sha,
                'content_hash' => $row->content_hash,
                'bytes' => $storedBytes,
                'is_partial' => (bool) $row->is_partial,
                'line_ranges' => $row->line_ranges,
                'original_bytes' => $row->original_bytes ?? $storedBytes,
                'original_lines' => $row->original_lines,
                'relevance_score' => $row->relevance_score,
                'language' => $row->language,
                'blob_sha' => $row->blob_sha,
            ];
        }

        usort($manifest, fn ($a, $b) => $a['path'] <=> $b['path']);

        $repository->forceFill([
            'last_commit_sha' => $commitSha,
            'import_status' => 'ready',
            'import_error' => null,
            'last_import_summary' => array_merge([
                'files' => count($manifest),
                'total_bytes' => $totalBytes,
                'approx_tokens' => (int) ceil($totalBytes / 4),
                'skipped' => $skipped,
                'warnings' => $warnings,
                'imported_at' => $now->toIso8601String(),
            ], $extraSummary),
            'last_synced_at' => $now,
        ])->save();

        return [
            'manifest' => $manifest,
            'total_bytes' => $totalBytes,
            'approx_tokens' => (int) ceil($totalBytes / 4),
        ];
    }

    private function humanUrl(Repository $repository, string $path, string $sha): string
    {
        return rtrim($repository->canonical_url, '/').'/blob/'.$sha.'/'.$path;
    }
}
