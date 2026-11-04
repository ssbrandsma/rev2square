<?php

namespace App\Services\Docs;

use App\Models\DocFinding;
use App\Models\DocFindingEvent;
use App\Models\DocPage;
use App\Models\DocRevision;

/**
 * Historical docs-media usage: revision bodies (including soft-
 * deleted pages, whose revisions are retained) plus live and
 * historical finding image references. Anything referenced here
 * pins the bytes: deletion would silently destroy cited evidence.
 */
final class DocMediaUsage
{
    public static function inHistory(int $projectId, int $mediaId): bool
    {
        $pageIds = DocPage::withTrashed()
            ->where('project_id', $projectId)
            ->pluck('id');

        if ($pageIds->isEmpty()) {
            return false;
        }

        $bodies = DocRevision::whereIn('doc_page_id', $pageIds)->pluck('body');
        foreach ($bodies as $body) {
            if (in_array($mediaId, DocDocument::mediaIds(is_array($body) ? $body : null), true)) {
                return true;
            }
        }

        $refSets = DocFinding::whereIn('doc_page_id', $pageIds)->pluck('refs')
            ->merge(DocFindingEvent::whereIn('doc_page_id', $pageIds)->pluck('refs'));
        foreach ($refSets as $refs) {
            if (! is_array($refs)) {
                continue;
            }
            foreach ($refs as $ref) {
                if (is_array($ref) && ($ref['kind'] ?? null) === 'image' && (int) ($ref['media_id'] ?? 0) === $mediaId) {
                    return true;
                }
            }
        }

        return false;
    }
}
