<?php

namespace App\Services\Media;

use App\Models\Project;
use App\Models\ProjectMedia;

/**
 * Versioned rich-story document (schema v1).
 *
 * {"version":1,"blocks":[
 *   {"type":"paragraph","text":"..."},
 *   {"type":"heading","level":2,"text":"..."},
 *   {"type":"list","ordered":false,"items":["..."]},
 *   {"type":"quote","text":"..."},
 *   {"type":"code","text":"..."},
 *   {"type":"image","media_id":12,"layout":"normal|wide"}
 * ]}
 *
 * Text uses a minimal inline syntax: **bold**, *italic*, `code`,
 * [text](https-url). Everything else is literal. Editor output is
 * untrusted: validate() enforces the allowlist server-side and every
 * media reference is checked against the owning project.
 */
final class StoryDocument
{
    public const VERSION = 1;

    public const MAX_BLOCKS = 100;

    public const MAX_TEXT = 5000;

    public const MAX_ITEMS = 30;

    /** @return array{ok: bool, document?: array, error?: string} */
    public static function validate(mixed $input, Project $project): array
    {
        if (! is_array($input)) {
            return ['ok' => false, 'error' => 'The story data could not be read. It may have been corrupted in transit — the text below is preserved; correct it and save again.'];
        }
        if (($input['version'] ?? null) !== self::VERSION) {
            $seen = $input['version'] ?? null;

            return ['ok' => false, 'error' => 'This story uses document version '.(is_scalar($seen) ? (string) $seen : 'unknown').'; this editor writes version '.self::VERSION.'. Refresh the page and save again.'];
        }
        if (! is_array($input['blocks'] ?? null)) {
            return ['ok' => false, 'error' => 'The story has no blocks. Add a paragraph and save again.'];
        }
        if (count($input['blocks']) > self::MAX_BLOCKS) {
            return ['ok' => false, 'error' => 'The story has '.number_format(count($input['blocks'])).' blocks. The limit is '.self::MAX_BLOCKS.' per story. Remove some blocks.'];
        }

        $blocks = [];
        $mediaIds = [];
        foreach (array_values($input['blocks']) as $i => $block) {
            [$clean, $error] = self::cleanBlock($block, $i + 1);
            if ($clean === null) {
                return ['ok' => false, 'error' => $error];
            }
            if ($clean['type'] === 'image') {
                $mediaIds[] = [$clean['media_id'], $i + 1];
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
                'Split this text into multiple paragraphs.');
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

            return [['type' => 'image', 'media_id' => (int) $mediaId, 'layout' => $layout], null];
        }

        return [null, 'Block '.$n.' has an unsupported block type (\''.$type.'\'). Remove it or choose a supported block type.'];
    }

    /** Long text field with the detailed failure shape. */
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
            if (is_array($block) && ($block['type'] ?? null) === 'image' && isset($block['media_id'])) {
                $ids[] = (int) $block['media_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /** Plain-text fallback (legacy body sync, search, previews). */
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
            }
        }

        return trim(implode("\n\n", $lines));
    }
}
