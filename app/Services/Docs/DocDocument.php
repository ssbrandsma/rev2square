<?php

namespace App\Services\Docs;

use App\Models\DocPage;
use App\Models\Project;
use App\Models\ProjectMedia;
use Illuminate\Support\Str;

/**
 * Versioned documentation-page document (schema v1).
 *
 * Same budgets and base blocks as the project story (see
 * StoryDocument), plus docs-only blocks:
 *
 * {"version":1,"blocks":[
 *   ...story blocks (paragraph/heading/list/quote/code/image),
 *   {"type":"step","title":"...","text":"...","media_ids":[12]},
 *   {"type":"doc_link","doc_id":7,"label":"..."}
 * ]}
 *
 * Image blocks additionally accept per-placement overrides and
 * numbered annotation markers:
 *
 * {"type":"image","media_id":12,"layout":"normal","caption":"...",
 *  "alt":"...","markers":[{"x":0.42,"y":0.61,"label":"U1","note":"..."}]}
 *
 * Marker coordinates are normalized 0..1 fractions of the displayed
 * image, so they stay aligned at any size. Editor output is
 * untrusted: validate() enforces the allowlist server-side and every
 * media/page reference is checked against the owning project.
 */
final class DocDocument
{
    public const VERSION = 1;

    public const MAX_BLOCKS = 100;

    public const MAX_TEXT = 5000;

    public const MAX_ITEMS = 30;

    public const MAX_STEP_MEDIA = 4;

    public const MAX_MARKERS = 20;

    public const MAX_LABEL = 80;

    public const MAX_NOTE = 500;

    public const MAX_CAPTION = 500;

    /** @return array{ok: bool, document?: array, error?: string} */
    public static function validate(mixed $input, Project $project, ?int $ignoreDocId = null): array
    {
        if (! is_array($input)) {
            return ['ok' => false, 'error' => 'The page is not a valid document.'];
        }
        if (($input['version'] ?? null) !== self::VERSION) {
            return ['ok' => false, 'error' => 'This page uses an unsupported version.'];
        }
        if (! is_array($input['blocks'] ?? null)) {
            return ['ok' => false, 'error' => 'The page has no blocks.'];
        }
        if (count($input['blocks']) > self::MAX_BLOCKS) {
            return ['ok' => false, 'error' => 'The page has too many blocks (max '.self::MAX_BLOCKS.').'];
        }

        $blocks = [];
        $mediaIds = [];
        $docIds = [];
        foreach ($input['blocks'] as $block) {
            $clean = self::cleanBlock($block);
            if ($clean === null) {
                return ['ok' => false, 'error' => 'The page contains an unsupported block.'];
            }
            if ($clean['type'] === 'image') {
                $mediaIds[] = $clean['media_id'];
            }
            if ($clean['type'] === 'step') {
                foreach ($clean['media_ids'] as $id) {
                    $mediaIds[] = $id;
                }
            }
            if ($clean['type'] === 'doc_link') {
                $docIds[] = $clean['doc_id'];
            }
            $blocks[] = $clean;
        }

        // Every embedded reference must be this project's image. IDs
        // from other projects (or files, not images) are rejected, so
        // identifier swapping cannot leak private uploads.
        if ($mediaIds !== []) {
            $allowed = ProjectMedia::where('project_id', $project->id)
                ->where('kind', ProjectMedia::KIND_IMAGE)
                ->whereIn('id', array_unique($mediaIds))
                ->pluck('id')
                ->all();
            foreach ($mediaIds as $id) {
                if (! in_array($id, $allowed, true)) {
                    return ['ok' => false, 'error' => 'The page references an image that does not belong to this project.'];
                }
            }
        }

        // Doc links must target another page of this project; a page
        // cannot link to itself.
        if ($docIds !== []) {
            $allowed = DocPage::where('project_id', $project->id)
                ->whereIn('id', array_unique($docIds))
                ->pluck('id')
                ->all();
            foreach ($docIds as $id) {
                if ($id === $ignoreDocId || ! in_array($id, $allowed, true)) {
                    return ['ok' => false, 'error' => 'The page links to documentation that does not belong to this project.'];
                }
            }
        }

        return ['ok' => true, 'document' => ['version' => self::VERSION, 'blocks' => $blocks]];
    }

    /** @return array<string, mixed>|null */
    private static function cleanBlock(mixed $block): ?array
    {
        if (! is_array($block)) {
            return null;
        }

        $type = $block['type'] ?? null;

        if (in_array($type, ['paragraph', 'quote'], true)) {
            $text = self::cleanText($block['text'] ?? null);
            if ($text === null) {
                return null;
            }

            return ['type' => $type, 'text' => $text];
        }

        if ($type === 'heading') {
            $text = self::cleanText($block['text'] ?? null);
            $level = (int) ($block['level'] ?? 2);
            if ($text === null || ! in_array($level, [2, 3], true)) {
                return null;
            }

            return ['type' => 'heading', 'level' => $level, 'text' => $text];
        }

        if ($type === 'list') {
            $items = $block['items'] ?? null;
            if (! is_array($items) || $items === [] || count($items) > self::MAX_ITEMS) {
                return null;
            }
            $clean = [];
            foreach ($items as $item) {
                $text = self::cleanText($item);
                if ($text === null) {
                    return null;
                }
                $clean[] = $text;
            }

            return ['type' => 'list', 'ordered' => (bool) ($block['ordered'] ?? false), 'items' => $clean];
        }

        if ($type === 'code') {
            $text = self::cleanText($block['text'] ?? null);
            if ($text === null) {
                return null;
            }

            return ['type' => 'code', 'text' => $text];
        }

        if ($type === 'image') {
            $mediaId = $block['media_id'] ?? null;
            $layout = $block['layout'] ?? 'normal';
            if (! is_numeric($mediaId) || ! in_array($layout, ['normal', 'wide'], true)) {
                return null;
            }
            $markers = self::cleanMarkers($block['markers'] ?? []);
            if ($markers === null) {
                return null;
            }
            $caption = self::cleanShort($block['caption'] ?? null, self::MAX_CAPTION, true);
            $alt = self::cleanShort($block['alt'] ?? null, self::MAX_CAPTION, true);
            if ($caption === false || $alt === false) {
                return null;
            }

            $clean = ['type' => 'image', 'media_id' => (int) $mediaId, 'layout' => $layout];
            if ($caption !== null) {
                $clean['caption'] = $caption;
            }
            if ($alt !== null) {
                $clean['alt'] = $alt;
            }
            if ($markers !== []) {
                $clean['markers'] = $markers;
            }

            return $clean;
        }

        if ($type === 'step') {
            $title = self::cleanShort($block['title'] ?? null, 200, true);
            $text = self::cleanText($block['text'] ?? null);
            if ($title === false || $text === null) {
                return null;
            }
            $mediaIds = $block['media_ids'] ?? [];
            if (! is_array($mediaIds) || count($mediaIds) > self::MAX_STEP_MEDIA) {
                return null;
            }
            $cleanIds = [];
            foreach ($mediaIds as $id) {
                if (! is_numeric($id)) {
                    return null;
                }
                $cleanIds[] = (int) $id;
            }

            $clean = ['type' => 'step', 'text' => $text, 'media_ids' => $cleanIds];
            if ($title !== null) {
                $clean['title'] = $title;
            }

            return $clean;
        }

        if ($type === 'doc_link') {
            $docId = $block['doc_id'] ?? null;
            $label = self::cleanShort($block['label'] ?? null, 200, true);
            if (! is_numeric($docId) || $label === false) {
                return null;
            }

            $clean = ['type' => 'doc_link', 'doc_id' => (int) $docId];
            if ($label !== null) {
                $clean['label'] = $label;
            }

            return $clean;
        }

        return null;
    }

    /** @return list<array{x: float, y: float, label?: string, note?: string}>|null */
    private static function cleanMarkers(mixed $markers): ?array
    {
        if ($markers === [] || $markers === null) {
            return [];
        }
        if (! is_array($markers) || count($markers) > self::MAX_MARKERS) {
            return null;
        }
        $clean = [];
        foreach ($markers as $marker) {
            if (! is_array($marker) || ! isset($marker['x'], $marker['y'])) {
                return null;
            }
            $x = $marker['x'];
            $y = $marker['y'];
            if (! is_numeric($x) || ! is_numeric($y) || $x < 0 || $x > 1 || $y < 0 || $y > 1) {
                return null;
            }
            $label = self::cleanShort($marker['label'] ?? null, self::MAX_LABEL, true);
            $note = self::cleanShort($marker['note'] ?? null, self::MAX_NOTE, true);
            if ($label === false || $note === false) {
                return null;
            }
            $entry = ['x' => round((float) $x, 4), 'y' => round((float) $y, 4)];
            if ($label !== null) {
                $entry['label'] = $label;
            }
            if ($note !== null) {
                $entry['note'] = $note;
            }
            $clean[] = $entry;
        }

        return $clean;
    }

    /**
     * @return string|null|false null when absent, false when invalid
     */
    private static function cleanShort(mixed $value, int $max, bool $optional): string|null|false
    {
        if ($value === null || $value === '') {
            return $optional ? null : false;
        }
        if (! is_string($value)) {
            return false;
        }
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        $value = trim($value);

        return mb_strlen($value) <= $max ? $value : false;
    }

    private static function cleanText(mixed $text): ?string
    {
        if (! is_string($text)) {
            return null;
        }
        // Strip control characters (keep newlines/tabs); length-bounded.
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        return mb_strlen($text) <= self::MAX_TEXT ? $text : null;
    }

    /** @return list<int> */
    public static function mediaIds(?array $document): array
    {
        $ids = [];
        foreach ($document['blocks'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (($block['type'] ?? null) === 'image' && isset($block['media_id'])) {
                $ids[] = (int) $block['media_id'];
            }
            if (($block['type'] ?? null) === 'step') {
                foreach ($block['media_ids'] ?? [] as $id) {
                    $ids[] = (int) $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<int> */
    public static function docIds(?array $document): array
    {
        $ids = [];
        foreach ($document['blocks'] ?? [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'doc_link' && isset($block['doc_id'])) {
                $ids[] = (int) $block['doc_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /** Plain-text fallback (search index, previews, agent slices). */
    public static function toPlainText(?array $document): string
    {
        $lines = [];
        foreach ($document['blocks'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? '';
            if (in_array($type, ['paragraph', 'heading', 'quote', 'code'], true)) {
                $lines[] = (string) ($block['text'] ?? '');
            } elseif ($type === 'list') {
                foreach ($block['items'] ?? [] as $item) {
                    $lines[] = '- '.(string) $item;
                }
            } elseif ($type === 'step') {
                if (trim((string) ($block['title'] ?? '')) !== '') {
                    $lines[] = (string) $block['title'];
                }
                $lines[] = (string) ($block['text'] ?? '');
            } elseif ($type === 'image') {
                foreach ($block['markers'] ?? [] as $marker) {
                    $label = trim((string) (($marker['label'] ?? '').' '.($marker['note'] ?? '')));
                    if ($label !== '') {
                        $lines[] = $label;
                    }
                }
            }
        }

        return trim(implode("\n\n", $lines));
    }

    /**
     * Stable in-page anchors: section/step/image/marker/link ids in
     * document order. Citations and the agent address these; ids are
     * derived from block position so they survive text edits.
     *
     * @return list<array{id: string, kind: string, label: string}>
     */
    public static function anchors(?array $document): array
    {
        $anchors = [];
        $heading = 0;
        $step = 0;
        $image = 0;
        $link = 0;
        foreach ($document['blocks'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }
            switch ($block['type'] ?? '') {
                case 'heading':
                    $heading++;
                    $slug = Str::slug(mb_substr((string) ($block['text'] ?? ''), 0, 60)) ?: 'section';
                    $anchors[] = ['id' => "section-{$heading}-{$slug}", 'kind' => 'section', 'label' => (string) ($block['text'] ?? '')];
                    break;
                case 'step':
                    $step++;
                    $anchors[] = ['id' => "step-{$step}", 'kind' => 'step', 'label' => trim((string) ($block['title'] ?? '')) !== '' ? (string) $block['title'] : "Step {$step}"];
                    break;
                case 'image':
                    $image++;
                    $anchors[] = ['id' => "img-{$image}", 'kind' => 'image', 'label' => "Image {$image}"];
                    foreach (array_values($block['markers'] ?? []) as $n => $marker) {
                        if (! is_array($marker)) {
                            continue;
                        }
                        $anchors[] = ['id' => "img-{$image}-marker-".($n + 1), 'kind' => 'marker', 'label' => trim((string) ($marker['label'] ?? '')) !== '' ? (string) $marker['label'] : 'Marker '.($n + 1)];
                    }
                    break;
                case 'doc_link':
                    $link++;
                    $anchors[] = ['id' => "link-{$link}", 'kind' => 'link', 'label' => trim((string) ($block['label'] ?? '')) !== '' ? (string) $block['label'] : 'Related page'];
                    break;
            }
        }

        return $anchors;
    }
}
