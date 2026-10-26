<?php

namespace App\Services\Revival;

use App\Models\Repository;

/**
 * Compact, structured evidence package for the WP3 AI Revival Investigator.
 * Citation source IDs are stable evidence_sources row IDs; WP3 validates
 * every citation against these rows.
 */
class EvidencePackage
{
    /** @return array<string, mixed> */
    public static function forRepository(Repository $repository): array
    {
        $sources = $repository->currentEvidence()->get();

        return [
            'repository' => [
                'canonical_url' => $repository->canonical_url,
                'owner' => $repository->owner,
                'name' => $repository->name,
                'commit_sha' => $repository->last_commit_sha,
                'synced_at' => $repository->last_synced_at?->toIso8601String(),
            ],
            'sources' => $sources->map(fn ($row) => [
                'source_id' => $row->id,
                'path' => $row->path,
                'url' => $row->url,
                'commit_sha' => $row->commit_sha,
                'content_hash' => $row->content_hash,
                'content' => $row->content,
            ])->all(),
        ];
    }
}
