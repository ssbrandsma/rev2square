<?php

namespace App\Services\Media;

use App\Models\Project;
use App\Models\ProjectMedia;

/**
 * The single safe story renderer. Used for the published story,
 * maintainer preview, and export conversion alike. Output is escaped
 * HTML built from the validated document — editor JSON is never
 * rendered directly and no raw markup survives.
 */
final class StoryRenderer
{
    /** Render to already-escaped HTML (safe for {!! !!}). */
    public static function toHtml(?array $document, Project $project): string
    {
        $media = ProjectMedia::where('project_id', $project->id)
            ->where('kind', ProjectMedia::KIND_IMAGE)
            ->whereIn('id', StoryDocument::mediaIds($document))
            ->get()
            ->keyBy('id');

        $html = [];
        foreach ($document['blocks'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }
            $html[] = match ($block['type'] ?? '') {
                'paragraph' => '<p>'.self::inline((string) ($block['text'] ?? '')).'</p>',
                'heading' => ($block['level'] ?? 2) === 3
                    ? '<h4>'.self::inline((string) ($block['text'] ?? '')).'</h4>'
                    : '<h3>'.self::inline((string) ($block['text'] ?? '')).'</h3>',
                'quote' => '<blockquote>'.self::inline((string) ($block['text'] ?? '')).'</blockquote>',
                'code' => '<pre><code>'.e((string) ($block['text'] ?? '')).'</code></pre>',
                'list' => self::renderList($block),
                'image' => self::renderImage($media->get((int) ($block['media_id'] ?? 0)), $project, (string) ($block['layout'] ?? 'normal')),
                default => '',
            };
        }

        return implode("\n", array_filter($html));
    }

    /** @param  array<string, mixed>  $block */
    private static function renderList(array $block): string
    {
        $items = '';
        foreach ($block['items'] ?? [] as $item) {
            $items .= '<li>'.self::inline((string) $item).'</li>';
        }
        $tag = ! empty($block['ordered']) ? 'ol' : 'ul';

        return "<{$tag}>{$items}</{$tag}>";
    }

    private static function renderImage(?ProjectMedia $media, Project $project, string $layout): string
    {
        if ($media === null) {
            return '<figure class="story-media-missing"><p>Image unavailable.</p></figure>';
        }
        if (! $media->isReady()) {
            return '<figure><p>Image is still processing…</p></figure>';
        }

        $wide = $layout === 'wide' ? ' story-media-wide' : '';
        $src = route('projects.media.show', [$project, $media, 'variant' => 'card']);
        $srcset = route('projects.media.show', [$project, $media, 'variant' => 'thumb']).' 400w, '
            .route('projects.media.show', [$project, $media, 'variant' => 'card']).' 800w';
        $caption = $media->caption ?: null;
        $credit = $media->credit ? 'Credit: '.$media->credit.(($media->license) ? ' ('.$media->license.')' : '') : null;

        return '<figure class="story-media'.$wide.'">'
            .'<img src="'.e($src).'" srcset="'.e($srcset).'" sizes="(max-width: 720px) 100vw, 720px" alt="'.e($media->altText()).'" loading="lazy"'
            .($media->width ? ' width="'.(int) $media->width.'" height="'.(int) $media->height.'"' : '').' />'
            .($caption !== null ? '<figcaption>'.e($caption).'</figcaption>' : '')
            .($credit !== null ? '<p class="story-credit">'.e($credit).'</p>' : '')
            .'</figure>';
    }

    /**
     * Minimal inline syntax on escaped segments: **bold**, *italic*,
     * `code`, [text](url). Links allow http/https only, without
     * credentials; anything else renders as literal text.
     */
    public static function inline(string $text): string
    {
        $out = '';
        $rest = $text;

        while ($rest !== '') {
            if (preg_match('/\A\*\*(.+?)\*\*/s', $rest, $m) && trim($m[1]) !== '') {
                $out .= '<strong>'.e($m[1]).'</strong>';
                $rest = substr($rest, strlen($m[0]));

                continue;
            }
            if (preg_match('/\A`(.+?)`/s', $rest, $m)) {
                $out .= '<code>'.e($m[1]).'</code>';
                $rest = substr($rest, strlen($m[0]));

                continue;
            }
            if (preg_match('/\A\[([^\]]+)\]\(([^)\s]+)\)/', $rest, $m)) {
                $url = $m[2];
                if (self::safeUrl($url)) {
                    $out .= '<a href="'.e($url).'" rel="noopener nofollow">'.e($m[1]).'</a>';
                } else {
                    $out .= e($m[0]);
                }
                $rest = substr($rest, strlen($m[0]));

                continue;
            }
            if (preg_match('/\A\*(.+?)\*/s', $rest, $m) && trim($m[1]) !== '') {
                $out .= '<em>'.e($m[1]).'</em>';
                $rest = substr($rest, strlen($m[0]));

                continue;
            }
            $out .= e(mb_substr($rest, 0, 1));
            $rest = mb_substr($rest, 1);
        }

        return nl2br($out);
    }

    public static function safeUrl(string $url): bool
    {
        if (strlen($url) > 2000 || preg_match('/[\s<>"\\\\]/', $url)) {
            return false;
        }
        $parts = @parse_url($url);

        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ! isset($parts['user'], $parts['pass'])
            && ! str_contains($url, '@')
            && isset($parts['host']);
    }

    /** Render to Markdown for exports (readable, captioned). */
    public static function toMarkdown(?array $document, Project $project): string
    {
        $media = ProjectMedia::where('project_id', $project->id)
            ->where('kind', ProjectMedia::KIND_IMAGE)
            ->whereIn('id', StoryDocument::mediaIds($document))
            ->get()
            ->keyBy('id');

        $lines = [];
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
                    $item = $media->get((int) ($block['media_id'] ?? 0));
                    $lines[] = $item !== null
                        ? '!['.$item->altText().']('.route('projects.media.show', [$project, $item]).')'
                            .($item->caption ? "\n*".$item->caption.'*' : '')
                        : '*[image unavailable]*';
                    break;
            }
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }
}
