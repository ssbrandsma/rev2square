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
     * @param  list<array{path: string, url: ?string, content: string, size: int}>  $files
     * @param  list<array{path: string, reason: string}>  $skipped
     * @param  list<string>  $warnings
     * @return array{manifest: list<array<string, mixed>>, total_bytes: int, approx_tokens: int}
     */
    public function persist(
        Repository $repository,
        string $commitSha,
        array $files,
        array $skipped,
        array $warnings = []
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
                    'visibility' => 'public',
                    'fetched_at' => $now,
                    'ingestion_status' => 'ok',
                ]
            );

            $manifest[] = [
                'source_id' => $row->id,
                'path' => $row->path,
                'url' => $row->url,
                'commit_sha' => $row->commit_sha,
                'content_hash' => $row->content_hash,
                'bytes' => $bytes,
            ];
        }

        usort($manifest, fn ($a, $b) => $a['path'] <=> $b['path']);

        $repository->forceFill([
            'last_commit_sha' => $commitSha,
            'import_status' => 'ready',
            'import_error' => null,
            'last_import_summary' => [
                'files' => count($manifest),
                'total_bytes' => $totalBytes,
                'approx_tokens' => (int) ceil($totalBytes / 4),
                'skipped' => $skipped,
                'warnings' => $warnings,
                'imported_at' => $now->toIso8601String(),
            ],
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
