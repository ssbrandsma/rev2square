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
            return ['ok' => false, 'error' => 'The story is not a valid document.'];
        }
        if (($input['version'] ?? null) !== self::VERSION) {
            return ['ok' => false, 'error' => 'This story uses an unsupported version.'];
        }
        if (! is_array($input['blocks'] ?? null)) {
            return ['ok' => false, 'error' => 'The story has no blocks.'];
        }
        if (count($input['blocks']) > self::MAX_BLOCKS) {
            return ['ok' => false, 'error' => 'The story has too many blocks (max '.self::MAX_BLOCKS.').'];
        }

        $blocks = [];
        $mediaIds = [];
        foreach ($input['blocks'] as $block) {
            $clean = self::cleanBlock($block);
            if ($clean === null) {
                return ['ok' => false, 'error' => 'The story contains an unsupported block.'];
            }
            if ($clean['type'] === 'image') {
                $mediaIds[] = $clean['media_id'];
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
                    return ['ok' => false, 'error' => 'The story references an image that does not belong to this project.'];
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

            return ['type' => 'image', 'media_id' => (int) $mediaId, 'layout' => $layout];
        }

        return null;
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
