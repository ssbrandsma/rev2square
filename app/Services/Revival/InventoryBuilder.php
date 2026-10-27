<?php

namespace App\Services\Revival;

/**
 * Builds a complete, deterministic repository inventory from a GitHub tree
 * listing. Pure: no HTTP, no database. Every blob is recorded with its
 * classification and eligibility, whether or not it is later downloaded.
 */
class InventoryBuilder
{
    /**
     * @param  array<int, array{path?: string, type?: string, size?: int, sha?: string}>  $tree
     * @param  array<string, mixed>  $config  revival.github inventory keys.
     * @return array{entries: list<array{path: string, extension: ?string, language: ?string, kind: string, size_bytes: int, blob_sha: ?string, subsystem: string, overview: bool, eligible: bool, skip_reason: ?string}>, discovered: int, blobs: int, capped: bool, over_cap: int}
     */
    public static function build(array $tree, array $config): array
    {
        $discovered = count($tree);
        $entries = [];

        foreach ($tree as $entry) {
            $path = $entry['path'] ?? '';
            $type = $entry['type'] ?? '';

            if ($path === '' || $type !== 'blob') {
                continue;
            }

            // Guard against absolute paths and traversal; GitHub paths are
            // repository-relative, anything else is inventoried as ineligible.
            if (str_starts_with($path, '/') || str_contains($path, '..')) {
                $entries[] = [
                    'path' => $path,
                    'extension' => null,
                    'language' => null,
                    'kind' => 'unknown',
                    'size_bytes' => 0,
                    'blob_sha' => null,
                    'subsystem' => '(root)',
                    'overview' => false,
                    'eligible' => false,
                    'skip_reason' => 'unsafe path',
                ];

                continue;
            }

            $size = max(0, (int) ($entry['size'] ?? 0));
            $classified = FileClassifier::classify($path, $size, $config);

            $entries[] = [
                'path' => $path,
                'extension' => $classified['extension'],
                'language' => $classified['language'],
                'kind' => $classified['kind'],
                'size_bytes' => $size,
                'blob_sha' => isset($entry['sha']) && preg_match('/^[0-9a-f]{40}$/', $entry['sha'])
                    ? $entry['sha']
                    : null,
                'subsystem' => FileClassifier::subsystem($path),
                'overview' => FileClassifier::isOverview($path, $classified['kind']),
                'eligible' => $classified['eligible'],
                'skip_reason' => $classified['reason'],
            ];
        }

        // Deterministic order: inventory never depends on API traversal order.
        usort($entries, fn ($a, $b) => $a['path'] <=> $b['path']);

        $blobs = count($entries);
        $cap = max(1, (int) ($config['max_inventory_entries'] ?? 5000));
        $overCap = max(0, $blobs - $cap);

        if ($overCap > 0) {
            $entries = array_slice($entries, 0, $cap);
        }

        return [
            'entries' => $entries,
            'discovered' => $discovered,
            'blobs' => $blobs,
            'capped' => $overCap > 0,
            'over_cap' => $overCap,
        ];
    }
}
