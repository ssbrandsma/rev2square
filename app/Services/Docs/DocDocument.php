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
            return ['ok' => false, 'error' => 'The page data could not be read. It may have been corrupted in transit — the text below is preserved; correct it and save again.'];
        }
        if (($input['version'] ?? null) !== self::VERSION) {
            $seen = $input['version'] ?? null;

            return ['ok' => false, 'error' => 'This page uses document version '.(is_scalar($seen) ? (string) $seen : 'unknown').'; this editor writes version '.self::VERSION.'. Refresh the page and save again.'];
        }
        if (! is_array($input['blocks'] ?? null)) {
            return ['ok' => false, 'error' => 'The page has no blocks. Add a paragraph and save again.'];
        }
        if (count($input['blocks']) > self::MAX_BLOCKS) {
            return ['ok' => false, 'error' => 'The page has '.number_format(count($input['blocks'])).' blocks. The limit is '.self::MAX_BLOCKS.' per page. Remove some blocks or split the page.'];
        }

        $blocks = [];
        $mediaIds = [];
        $docIds = [];
        foreach (array_values($input['blocks']) as $i => $block) {
            [$clean, $error] = self::cleanBlock($block, $i + 1);
            if ($clean === null) {
                return ['ok' => false, 'error' => $error];
            }
            if ($clean['type'] === 'image') {
                $mediaIds[] = [$clean['media_id'], $i + 1];
            }
            if ($clean['type'] === 'step') {
                foreach ($clean['media_ids'] as $id) {
                    $mediaIds[] = [$id, $i + 1];
                }
            }
            if ($clean['type'] === 'doc_link') {
                $docIds[] = [$clean['doc_id'], $i + 1];
            }
            $blocks[] = $clean;
        }

        // Every embedded reference must be this project's image. IDs
        // from other projects (or files, not images) are rejected, so
        // identifier swapping cannot leak private uploads. The message
        // names the block but never reveals where the id belongs.
        if ($mediaIds !== []) {
            $allowed = ProjectMedia::where('project_id', $project->id)
                ->where('kind', ProjectMedia::KIND_IMAGE)
                ->whereIn('id', array_unique(array_column($mediaIds, 0)))
                ->pluck('id')
                ->all();
            foreach ($mediaIds as [$id, $n]) {
                if (! in_array($id, $allowed, true)) {
                    return ['ok' => false, 'error' => 'Block '.$n.' — this photo does not belong to this project. Pick a photo from this project’s library.'];
                }
            }
        }

        // Doc links must target another page of this project; a page
        // cannot link to itself.
        if ($docIds !== []) {
            $allowed = DocPage::where('project_id', $project->id)
                ->whereIn('id', array_unique(array_column($docIds, 0)))
                ->pluck('id')
                ->all();
            foreach ($docIds as [$id, $n]) {
                if ($id === $ignoreDocId) {
                    return ['ok' => false, 'error' => 'Block '.$n.' — a page cannot link to itself. Choose another page.'];
                }
                if (! in_array($id, $allowed, true)) {
                    return ['ok' => false, 'error' => 'Block '.$n.' — this link target is not part of this project. Choose another page.'];
                }
            }
        }

        return ['ok' => true, 'document' => ['version' => self::VERSION, 'blocks' => $blocks]];
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string|null} cleaned
     *                                                             block plus, on failure, a message naming the block, the
     *                                                             actual problem, the limit, and the recovery action.
     */
    private static function cleanBlock(mixed $block, int $n): array
    {
        if (! is_array($block)) {
            return [null, 'Block '.$n.' is not a valid block. Remove it and re-add its content.'];
        }

        $type = $block['type'] ?? null;
        if (! is_string($type) || $type === '') {
            return [null, 'Block '.$n.' has no block type. Remove it and re-add its content.'];
        }

        if (in_array($type, ['paragraph', 'quote'], true)) {
            [$text, $error] = self::cleanLongText($block['text'] ?? null, $n, ucfirst($type),
                'Split this text into multiple paragraph or step blocks.');
            if ($text === null) {
                return [null, $error];
            }

            return [['type' => $type, 'text' => $text], null];
        }

        if ($type === 'heading') {
            [$text, $error] = self::cleanLongText($block['text'] ?? null, $n, 'Heading',
                'Shorten the heading or move detail into a paragraph.');
            if ($text === null) {
                return [null, $error];
            }
            $level = (int) ($block['level'] ?? 2);
            if (! in_array($level, [2, 3], true)) {
                return [null, 'Block '.$n.' — Heading has an unsupported level. Choose a section or sub heading.'];
            }

            return [['type' => 'heading', 'level' => $level, 'text' => $text], null];
        }

        if ($type === 'list') {
            $items = $block['items'] ?? null;
            if (! is_array($items) || $items === []) {
                return [null, 'Block '.$n.' — List has no items. Add at least one item or remove the block.'];
            }
            if (count($items) > self::MAX_ITEMS) {
                return [null, 'Block '.$n.' — List has '.number_format(count($items)).' items. The limit is '.self::MAX_ITEMS.' per list. Split it into multiple lists.'];
            }
            $clean = [];
            foreach (array_values($items) as $i => $item) {
                [$text, $error] = self::cleanLongText($item, $n, 'List item '.($i + 1),
                    'Shorten this item or split the list.');
                if ($text === null) {
                    return [null, $error];
                }
                $clean[] = $text;
            }

            return [['type' => 'list', 'ordered' => (bool) ($block['ordered'] ?? false), 'items' => $clean], null];
        }

        if ($type === 'code') {
            [$text, $error] = self::cleanLongText($block['text'] ?? null, $n, 'Code',
                'Split this text into multiple code blocks.');
            if ($text === null) {
                return [null, $error];
            }

            return [['type' => 'code', 'text' => $text], null];
        }

        if ($type === 'image') {
            $mediaId = $block['media_id'] ?? null;
            $layout = $block['layout'] ?? 'normal';
            if (! is_numeric($mediaId)) {
                return [null, 'Block '.$n.' — Image reference is invalid. Pick the photo again from the library.'];
            }
            if (! in_array($layout, ['normal', 'wide'], true)) {
                return [null, 'Block '.$n.' — Image layout is invalid. Choose normal or wide layout.'];
            }
            [$markers, $error] = self::cleanMarkersDetailed($block['markers'] ?? [], $n);
            if ($markers === null) {
                return [null, $error];
            }
            [$caption, $error] = self::cleanShortDetailed($block['caption'] ?? null, self::MAX_CAPTION, $n, 'Caption', 'Shorten it.');
            if ($error !== null) {
                return [null, $error];
            }
            [$alt, $error] = self::cleanShortDetailed($block['alt'] ?? null, self::MAX_CAPTION, $n, 'Alternative text', 'Shorten it.');
            if ($error !== null) {
                return [null, $error];
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

            return [$clean, null];
        }

        if ($type === 'step') {
            [$title, $error] = self::cleanShortDetailed($block['title'] ?? null, 200, $n, 'Step title', 'Shorten it.');
            if ($error !== null) {
                return [null, $error];
            }
            [$text, $error] = self::cleanLongText($block['text'] ?? null, $n, 'Step instructions',
                'Split these instructions into multiple steps.');
            if ($text === null) {
                return [null, $error];
            }
            $mediaIds = $block['media_ids'] ?? [];
            if (! is_array($mediaIds)) {
                return [null, 'Block '.$n.' — Step photo list is invalid. Attach the photos again.'];
            }
            if (count($mediaIds) > self::MAX_STEP_MEDIA) {
                return [null, 'Block '.$n.' — Step has '.number_format(count($mediaIds)).' photos. The limit is '.self::MAX_STEP_MEDIA.' per step. Remove the extras.'];
            }
            $cleanIds = [];
            foreach ($mediaIds as $id) {
                if (! is_numeric($id)) {
                    return [null, 'Block '.$n.' — Step photo reference is invalid. Attach the photo again.'];
                }
                $cleanIds[] = (int) $id;
            }

            $clean = ['type' => 'step', 'text' => $text, 'media_ids' => $cleanIds];
            if ($title !== null) {
                $clean['title'] = $title;
            }

            return [$clean, null];
        }

        if ($type === 'doc_link') {
            $docId = $block['doc_id'] ?? null;
            if (! is_numeric($docId)) {
                return [null, 'Block '.$n.' — Page link is invalid. Choose the page again.'];
            }
            [$label, $error] = self::cleanShortDetailed($block['label'] ?? null, 200, $n, 'Link label', 'Shorten it.');
            if ($error !== null) {
                return [null, $error];
            }

            $clean = ['type' => 'doc_link', 'doc_id' => (int) $docId];
            if ($label !== null) {
                $clean['label'] = $label;
            }

            return [$clean, null];
        }

        return [null, 'Block '.$n.' has an unsupported block type (\''.$type.'\'). Remove it or choose a supported block type.'];
    }

    /**
     * @return array{0: list<array{x: float, y: float, label?: string, note?: string}>|null, 1: string|null}
     */
    private static function cleanMarkersDetailed(mixed $markers, int $n): array
    {
        if ($markers === [] || $markers === null) {
            return [[], null];
        }
        if (! is_array($markers)) {
            return [null, 'Block '.$n.' — Marker list is invalid. Remove the markers and place them again.'];
        }
        if (count($markers) > self::MAX_MARKERS) {
            return [null, 'Block '.$n.' — Image has '.number_format(count($markers)).' markers. The limit is '.self::MAX_MARKERS.' per photo. Remove the extras.'];
        }
        $clean = [];
        foreach (array_values($markers) as $i => $marker) {
            $m = $i + 1;
            if (! is_array($marker) || ! isset($marker['x'], $marker['y'])) {
                return [null, 'Block '.$n.' — Marker '.$m.' is invalid. Remove it and place it again.'];
            }
            $x = $marker['x'];
            $y = $marker['y'];
            if (! is_numeric($x) || ! is_numeric($y) || $x < 0 || $x > 1 || $y < 0 || $y > 1) {
                return [null, 'Block '.$n.' — Marker '.$m.' has an invalid position. Click the photo to reposition it.'];
            }
            [$label, $error] = self::cleanShortDetailed($marker['label'] ?? null, self::MAX_LABEL, $n, 'Marker '.$m.' label', 'Shorten it.');
            if ($error !== null) {
                return [null, $error];
            }
            [$note, $error] = self::cleanShortDetailed($marker['note'] ?? null, self::MAX_NOTE, $n, 'Marker '.$m.' note', 'Shorten it.');
            if ($error !== null) {
                return [null, $error];
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

        return [$clean, null];
    }

    /**
     * Optional short field: null when absent, cleaned string when
     * valid, error naming block/field/actual/limit otherwise.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function cleanShortDetailed(mixed $value, int $max, int $n, string $field, string $recovery): array
    {
        if ($value === null || $value === '') {
            return [null, null];
        }
        if (! is_string($value)) {
            return [null, 'Block '.$n.' — '.$field.' is invalid. Re-enter it.'];
        }
        $value = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value));
        $len = mb_strlen($value);
        if ($len > $max) {
            return [null, 'Block '.$n.' — '.$field.' contains '.number_format($len).' characters. The limit is '.number_format($max).'. '.$recovery];
        }

        return [$value, null];
    }

    /** Long text field with the same detailed failure shape. */
    private static function cleanLongText(mixed $text, int $n, string $field, string $recovery): array
    {
        if (! is_string($text)) {
            return [null, 'Block '.$n.' — '.$field.' text is missing. Re-enter the text or remove the block.'];
        }
        // Strip control characters (keep newlines/tabs); length-bounded.
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $len = mb_strlen($text);
        if ($len > self::MAX_TEXT) {
            return [null, 'Block '.$n.' — '.$field.' contains '.number_format($len).' characters. The limit is '.number_format(self::MAX_TEXT).' per block. '.$recovery];
        }

        return [$text, null];
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
