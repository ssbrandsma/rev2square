<?php

namespace App\Services\Docs;

use App\Models\DocPage;
use App\Models\Project;
use App\Models\ProjectMedia;
use App\Models\User;
use App\Services\Media\StoryRenderer;
use Illuminate\Support\Collection;

/**
 * Safe documentation renderer. Inline syntax, escaping rules, and
 * media delivery match the project story (StoryRenderer::inline and
 * the authorized media routes); docs add heading anchors, procedural
 * steps, internal page links, and annotated images. Output is escaped
 * HTML built from the validated document — editor JSON is never
 * rendered directly and no raw markup survives.
 */
final class DocRenderer
{
    /**
     * Render to already-escaped HTML (safe for {!! !!}).
     *
     * @param  array<string, mixed>|null  $document
     */
    public static function toHtml(?array $document, Project $project, ?User $viewer): string
    {
        $media = ProjectMedia::where('project_id', $project->id)
            ->where('kind', ProjectMedia::KIND_IMAGE)
            ->whereIn('id', DocDocument::mediaIds($document))
            ->get()
            ->keyBy('id');

        $targets = DocPage::where('project_id', $project->id)
            ->whereIn('id', DocDocument::docIds($document))
            ->get()
            ->keyBy('id');

        $anchors = [];
        foreach (DocDocument::anchors($document) as $anchor) {
            $anchors[$anchor['kind']][] = $anchor;
        }
        $take = function (string $kind) use (&$anchors): ?array {
            if (empty($anchors[$kind])) {
                return null;
            }

            return array_shift($anchors[$kind]);
        };

        $html = [];
        foreach ($document['blocks'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }
            $html[] = match ($block['type'] ?? '') {
                'paragraph' => '<p>'.StoryRenderer::inline((string) ($block['text'] ?? '')).'</p>',
                'heading' => self::renderHeading($block, (string) ($take('section')['id'] ?? '')),
                'quote' => '<blockquote>'.StoryRenderer::inline((string) ($block['text'] ?? '')).'</blockquote>',
                'code' => '<pre><code>'.e((string) ($block['text'] ?? '')).'</code></pre>',
                'list' => self::renderList($block),
                'image' => self::renderImage($block, $media, $project, $take('image')['id'] ?? ''),
                'step' => self::renderStep($block, $media, $project, (string) ($take('step')['id'] ?? '')),
                'doc_link' => self::renderDocLink($block, $targets, $project, $viewer),
                default => '',
            };
        }

        return implode("\n", array_filter($html));
    }

    /** @param  array<string, mixed>  $block */
    private static function renderHeading(array $block, string $id): string
    {
        $inner = StoryRenderer::inline((string) ($block['text'] ?? ''));
        $attr = $id !== '' ? ' id="'.e($id).'"' : '';

        return ($block['level'] ?? 2) === 3
            ? "<h4{$attr}>{$inner}</h4>"
            : "<h3{$attr}>{$inner}</h3>";
    }

    /** @param  array<string, mixed>  $block */
    private static function renderList(array $block): string
    {
        $items = '';
        foreach ($block['items'] ?? [] as $item) {
            $items .= '<li>'.StoryRenderer::inline((string) $item).'</li>';
        }
        $tag = ! empty($block['ordered']) ? 'ol' : 'ul';

        return "<{$tag}>{$items}</{$tag}>";
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  Collection<int, ProjectMedia>  $media
     */
    private static function renderImage(array $block, $media, Project $project, string $anchorId): string
    {
        $item = $media->get((int) ($block['media_id'] ?? 0));
        if ($item === null) {
            return '<figure class="story-media-missing"><p>Image unavailable.</p></figure>';
        }
        if (! $item->isReady()) {
            return '<figure><p>Image is still processing…</p></figure>';
        }

        $wide = ($block['layout'] ?? 'normal') === 'wide' ? ' story-media-wide' : '';
        $alt = trim((string) ($block['alt'] ?? '')) !== '' ? (string) $block['alt'] : $item->altText();
        $caption = array_key_exists('caption', $block) ? (string) $block['caption'] : (string) ($item->caption ?? '');
        $credit = $item->credit ? 'Credit: '.$item->credit.(($item->license) ? ' ('.$item->license.')' : '') : null;

        $src = route('projects.media.show', [$project, $item, 'variant' => 'card']);
        $srcset = route('projects.media.show', [$project, $item, 'variant' => 'thumb']).' 400w, '
            .route('projects.media.show', [$project, $item, 'variant' => 'card']).' 800w';
        $full = route('projects.media.show', [$project, $item, 'variant' => 'wide']);

        $markers = [];
        foreach (array_values($block['markers'] ?? []) as $n => $marker) {
            if (! is_array($marker) || ! isset($marker['x'], $marker['y'])) {
                continue;
            }
            $markers[] = $marker + ['number' => $n + 1];
        }

        $idAttr = $anchorId !== '' ? ' id="'.e($anchorId).'"' : '';
        $out = '<figure class="story-media doc-media'.$wide.'"'.$idAttr.'>'
            .'<div class="relative">'
            .'<button type="button" data-gallery-open data-full="'.e($full).'" data-alt="'.e($alt).'" data-caption="'.e($caption).'" class="block w-full cursor-zoom-in" aria-label="Enlarge image: '.e($alt).'">'
            .'<img src="'.e($src).'" srcset="'.e($srcset).'" sizes="(max-width: 720px) 100vw, 720px" alt="'.e($alt).'" loading="lazy" />'
            .'</button>';

        foreach ($markers as $marker) {
            $x = min(100, max(0, (float) $marker['x'] * 100));
            $y = min(100, max(0, (float) $marker['y'] * 100));
            $markerLabel = trim((string) ($marker['label'] ?? ''));
            $out .= '<a href="#'.e($anchorId).'-marker-'.$marker['number'].'" class="doc-marker" style="left: '.$x.'%; top: '.$y.'%;" aria-label="Marker '.$marker['number'].($markerLabel !== '' ? ': '.e($markerLabel) : '').'">'
                .e((string) $marker['number']).'</a>';
        }
        $out .= '</div>';

        if ($caption !== '') {
            $out .= '<figcaption>'.e($caption).'</figcaption>';
        }
        if ($credit !== null) {
            $out .= '<p class="story-credit">'.e($credit).'</p>';
        }
        if ($markers !== []) {
            $out .= '<ol class="doc-legend">';
            foreach ($markers as $marker) {
                $label = trim((string) ($marker['label'] ?? ''));
                $note = trim((string) ($marker['note'] ?? ''));
                $out .= '<li id="'.e($anchorId).'-marker-'.$marker['number'].'">'
                    .'<span class="doc-legend-number">'.e((string) $marker['number']).'</span> '
                    .($label !== '' ? '<strong>'.e($label).'</strong>' : 'Marker '.$marker['number'])
                    .($note !== '' ? ' — '.e($note) : '')
                    .'</li>';
            }
            $out .= '</ol>';
        }

        return $out.'</figure>';
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  Collection<int, ProjectMedia>  $media
     */
    private static function renderStep(array $block, $media, Project $project, string $anchorId): string
    {
        $number = preg_match('/^step-(\d+)$/', $anchorId, $m) ? (int) $m[1] : 0;
        $title = trim((string) ($block['title'] ?? ''));
        $idAttr = $anchorId !== '' ? ' id="'.e($anchorId).'"' : '';

        $out = '<section class="doc-step"'.$idAttr.'>'
            .'<h4>'.e($number > 0 ? "Step {$number}" : 'Step').($title !== '' ? ': '.StoryRenderer::inline($title) : '').'</h4>'
            .'<p>'.StoryRenderer::inline((string) ($block['text'] ?? '')).'</p>';

        foreach ($block['media_ids'] ?? [] as $id) {
            $item = $media->get((int) $id);
            if ($item === null) {
                $out .= '<figure class="story-media-missing"><p>Image unavailable.</p></figure>';

                continue;
            }
            if (! $item->isReady()) {
                $out .= '<figure><p>Image is still processing…</p></figure>';

                continue;
            }
            $src = route('projects.media.show', [$project, $item, 'variant' => 'card']);
            $full = route('projects.media.show', [$project, $item, 'variant' => 'wide']);
            $out .= '<figure class="story-media doc-step-media">'
                .'<button type="button" data-gallery-open data-full="'.e($full).'" data-alt="'.e($item->altText()).'" data-caption="'.e((string) ($item->caption ?? '')).'" class="block w-full cursor-zoom-in" aria-label="Enlarge image: '.e($item->altText()).'">'
                .'<img src="'.e($src).'" alt="'.e($item->altText()).'" loading="lazy" />'
                .'</button>'
                .($item->caption ? '<figcaption>'.e($item->caption).'</figcaption>' : '')
                .'</figure>';
        }

        return $out.'</section>';
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  Collection<int, DocPage>  $targets
     */
    private static function renderDocLink(array $block, $targets, Project $project, ?User $viewer): string
    {
        $target = $targets->get((int) ($block['doc_id'] ?? 0));
        $canSee = $target !== null
            && ($target->isPublished() || ($viewer !== null && $viewer->can('update', $project)));

        if (! $canSee) {
            return '<p class="doc-link-missing">[Documentation unavailable]</p>';
        }

        $label = trim((string) ($block['label'] ?? '')) !== '' ? (string) $block['label'] : $target->title;

        return '<p class="doc-link"><a href="'.e(route('projects.docs.show', [$project, $target])).'">'.e($label).'</a></p>';
    }

    /** Render to Markdown for exports (readable, captioned, pinned). */
    public static function toMarkdown(?array $document, Project $project, ?User $viewer): string
    {
        $media = ProjectMedia::where('project_id', $project->id)
            ->where('kind', ProjectMedia::KIND_IMAGE)
            ->whereIn('id', DocDocument::mediaIds($document))
            ->get()
            ->keyBy('id');

        $targets = DocPage::where('project_id', $project->id)
            ->whereIn('id', DocDocument::docIds($document))
            ->get()
            ->keyBy('id');

        $lines = [];
        $step = 0;
        $image = 0;
        foreach ($document['blocks'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }
            switch ($block['type'] ?? '') {
                case 'paragraph':
                    $lines[] = (string) ($block['text'] ?? '');
                    break;
                case 'heading':
                    $lines[] = (($block['level'] ?? 2) === 3 ? '### ' : '## ').($block['text'] ?? '');
                    break;
                case 'quote':
                    $lines[] = '> '.str_replace("\n", "\n> ", (string) ($block['text'] ?? ''));
                    break;
                case 'code':
                    $lines[] = "```\n".($block['text'] ?? '')."\n```";
                    break;
                case 'list':
                    $i = 0;
                    foreach ($block['items'] ?? [] as $item) {
                        $i++;
                        $lines[] = (! empty($block['ordered']) ? "{$i}. " : '- ').$item;
                    }
                    break;
                case 'image':
                    $image++;
                    $item = $media->get((int) ($block['media_id'] ?? 0));
                    if ($item === null || ! $item->isReady()) {
                        $lines[] = '*[image unavailable]*';

                        break;
                    }
                    $alt = trim((string) ($block['alt'] ?? '')) !== '' ? (string) $block['alt'] : $item->altText();
                    $caption = array_key_exists('caption', $block) ? (string) $block['caption'] : (string) ($item->caption ?? '');
                    $lines[] = '!['.$alt.']('.route('projects.media.show', [$project, $item]).')';
                    if ($caption !== '') {
                        $lines[] = '*'.$caption.'*';
                    }
                    foreach (array_values($block['markers'] ?? []) as $n => $marker) {
                        if (! is_array($marker)) {
                            continue;
                        }
                        $label = trim((string) (($marker['label'] ?? '').' '.($marker['note'] ?? '')));
                        $lines[] = '- Marker '.($n + 1).($label !== '' ? ': '.$label : '');
                    }
                    break;
                case 'step':
                    $step++;
                    $title = trim((string) ($block['title'] ?? ''));
                    $lines[] = "{$step}. ".($title !== '' ? "**{$title}** — " : '').($block['text'] ?? '');
                    foreach ($block['media_ids'] ?? [] as $id) {
                        $item = $media->get((int) $id);
                        $lines[] = $item !== null && $item->isReady()
                            ? '!['.$item->altText().']('.route('projects.media.show', [$project, $item]).')'
                            : '*[image unavailable]*';
                    }
                    break;
                case 'doc_link':
                    $target = $targets->get((int) ($block['doc_id'] ?? 0));
                    $canSee = $target !== null
                        && ($target->isPublished() || ($viewer !== null && $viewer->can('update', $project)));
                    if ($canSee) {
                        $label = trim((string) ($block['label'] ?? '')) !== '' ? (string) $block['label'] : $target->title;
                        $lines[] = "[{$label}](".route('projects.docs.show', [$project, $target]).')';
                    } else {
                        $lines[] = '*[documentation unavailable]*';
                    }
                    break;
            }
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }
}
