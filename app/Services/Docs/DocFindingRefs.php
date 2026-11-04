<?php

namespace App\Services\Docs;

use App\Models\DocPage;
use App\Models\EvidenceSource;
use App\Models\Project;
use App\Models\ProjectArtifact;
use App\Models\ProjectMedia;
use App\Models\User;

/**
 * Renders one cleaned finding reference as escaped HTML: an
 * authorized internal link, or explicit unavailable text. Unknown
 * shapes render as unavailable — never as raw output.
 *
 * Image placements resolve against the given document (the pinned
 * revision body in history views, the live body otherwise), so a
 * placement that no longer matches renders unavailable instead of
 * pointing at the wrong photo.
 */
final class DocFindingRefs
{
    /**
     * @param  array<string, mixed>  $ref
     * @param  array<string, mixed>|null  $document
     */
    public static function render(mixed $ref, Project $project, ?User $viewer, ?DocPage $contextPage = null, ?array $document = null, ?int $rev = null): string
    {
        if (! is_array($ref)) {
            return e('[Reference unavailable]');
        }

        $canSee = static fn (): bool => $viewer !== null && $viewer->can('view', $project);

        switch ($ref['kind'] ?? null) {
            case 'page':
                $target = isset($ref['doc_id']) ? DocPage::find($ref['doc_id']) : null;
                if ($target === null || $target->project_id !== $project->id || $target->trashed()) {
                    return e('[Documentation unavailable]');
                }
                if (! $target->isPublished() && ! ($viewer?->can('update', $project) ?? false)) {
                    return e('[Documentation unavailable]');
                }
                $url = route('projects.docs.show', [$project, $target]);
                if (isset($ref['anchor'])) {
                    $url .= '#'.$ref['anchor'];
                }

                return '<a href="'.e($url).'" class="font-semibold text-emerald-700 hover:text-emerald-600">'.e($target->title).'</a>';

            case 'artifact':
                $artifact = isset($ref['artifact_id']) ? ProjectArtifact::find($ref['artifact_id']) : null;
                if ($artifact === null || $artifact->project_id !== $project->id || ! $canSee()) {
                    return e('[Artifact unavailable]');
                }

                return '<a href="'.e(route('projects.artifacts.show', [$project, $artifact])).'" class="font-semibold text-emerald-700 hover:text-emerald-600">'.e($artifact->original_name).'</a>';

            case 'evidence':
                $source = isset($ref['source_id']) ? EvidenceSource::find($ref['source_id']) : null;
                if ($source === null || ! $canSee()) {
                    return e('[Evidence unavailable]');
                }
                if (! $project->repositories()->whereKey($source->repository_id)->exists()) {
                    return e('[Evidence unavailable]');
                }

                return '<a href="'.e(route('projects.evidence.show', [$project, $source])).'" class="font-semibold text-emerald-700 hover:text-emerald-600">'.e($source->path).'</a>';

            case 'image':
                $media = isset($ref['media_id']) ? ProjectMedia::find($ref['media_id']) : null;
                if ($media === null || $media->project_id !== $project->id || ! $media->isImage() || ! $canSee()) {
                    return e('[Image unavailable]');
                }
                $label = $media->displayTitle().(isset($ref['marker']) ? ' (marker '.$ref['marker'].')' : '');

                // Without a placement the reference names the photo
                // only; with one it must resolve to that exact
                // image block (and marker) or say so explicitly.
                if (! isset($ref['placement']) || $contextPage === null) {
                    return e($label);
                }
                $anchor = self::placementAnchor(
                    $document ?? (is_array($contextPage->body) ? $contextPage->body : null),
                    (int) $ref['media_id'],
                    (int) $ref['placement'],
                    isset($ref['marker']) ? (int) $ref['marker'] : null
                );
                if ($anchor === null) {
                    return e('[Image unavailable]');
                }
                // Preserve the pinned revision: the placement was
                // validated against that body, so the link must show
                // the same body — never the reordered live page.
                $url = $rev !== null
                    ? route('projects.docs.show', [$project, $contextPage, 'rev' => $rev]).'#'.$anchor
                    : route('projects.docs.show', [$project, $contextPage]).'#'.$anchor;

                return '<a href="'.e($url).'" class="font-semibold text-emerald-700 hover:text-emerald-600">'.e($label).'</a>';

            case 'url':
                $url = $ref['url'] ?? null;
                if (! is_string($url)) {
                    return e('[Reference unavailable]');
                }

                return '<a href="'.e($url).'" rel="noopener nofollow" class="font-semibold text-emerald-700 hover:text-emerald-600">'.e($url).'</a>';

            default:
                return e('[Reference unavailable]');
        }
    }

    /**
     * Resolve a placement to its in-page anchor: the Nth image
     * block must hold this media id, and the marker must exist on
     * it. Anything else is a stale reference, not a guess.
     *
     * @param  array<string, mixed>|null  $document
     */
    private static function placementAnchor(?array $document, int $mediaId, int $placement, ?int $marker): ?string
    {
        $images = [];
        foreach ($document['blocks'] ?? [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'image') {
                $images[] = $block;
            }
        }

        $block = $images[$placement - 1] ?? null;
        if (! is_array($block) || (int) ($block['media_id'] ?? 0) !== $mediaId) {
            return null;
        }

        if ($marker !== null) {
            $markers = is_array($block['markers'] ?? null) ? array_values($block['markers']) : [];
            if (! isset($markers[$marker - 1]) || ! is_array($markers[$marker - 1])) {
                return null;
            }

            return "img-{$placement}-marker-{$marker}";
        }

        return "img-{$placement}";
    }
}
