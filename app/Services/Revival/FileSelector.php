<?php

namespace App\Services\Revival;

/**
 * Pure file-selection policy: picks an ordered, budgeted evidence set from a
 * GitHub tree listing. No HTTP, no database — see EvidenceManifestTest.
 */
class FileSelector
{
    /**
     * @param  array<int, array{path?: string, type?: string, size?: int}>  $tree
     * @param  array<string, mixed>  $config  revival.github selection keys.
     * @return array{selected: list<array{path: string, size: int}>, skipped: list<array{path: string, reason: string}>}
     */
    public static function select(array $tree, array $config): array
    {
        ['ranked' => $ranked, 'skipped' => $skipped] = self::partition($tree, $config);

        $selected = [];
        $totalBytes = 0;

        foreach ($ranked as $entry) {
            if (count($selected) >= $config['max_files']) {
                $skipped[] = ['path' => $entry['path'], 'reason' => 'over file-count budget'];

                continue;
            }

            if ($totalBytes + $entry['size'] > $config['max_total_bytes']) {
                $skipped[] = ['path' => $entry['path'], 'reason' => 'over total-byte budget'];

                continue;
            }

            $selected[] = $entry;
            $totalBytes += $entry['size'];
        }

        return ['selected' => $selected, 'skipped' => $skipped];
    }

    /**
     * Split tree entries into priority-ranked candidates and pre-skipped paths.
     * Priority: README → docs → license/changelog → everything else.
     *
     * @return array{ranked: list<array{path: string, size: int}>, skipped: list<array{path: string, reason: string}>}
     */
    private static function partition(array $tree, array $config): array
    {
        $ranked = [];
        $skipped = [];

        foreach ($tree as $entry) {
            $path = $entry['path'] ?? '';
            $type = $entry['type'] ?? '';
            $size = (int) ($entry['size'] ?? 0);

            if ($path === '' || $type !== 'blob') {
                continue;
            }

            $reason = self::rejectReason($path, $size, $config);

            if ($reason !== null) {
                $skipped[] = ['path' => $path, 'reason' => $reason];

                continue;
            }

            $ranked[] = ['path' => $path, 'size' => $size, 'rank' => self::rank($path)];
        }

        usort($ranked, fn ($a, $b) => [$a['rank'], $a['path']] <=> [$b['rank'], $b['path']]);
        $ranked = array_map(fn ($e) => ['path' => $e['path'], 'size' => $e['size']], $ranked);

        return ['ranked' => $ranked, 'skipped' => $skipped];
    }

    private static function rejectReason(string $path, int $size, array $config): ?string
    {
        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            if (in_array($segment, $config['exclude_dirs'], true)) {
                return 'excluded directory';
            }
        }

        if (count($segments) > $config['max_depth']) {
            return 'exceeds depth limit';
        }

        if ($size > $config['max_file_bytes']) {
            return 'exceeds file-size limit';
        }

        $basename = strtolower(basename($path));
        $stem = strtolower(pathinfo($basename, PATHINFO_FILENAME));
        $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));

        $stemAllowed = self::basenameAllowed($stem, $config['include_basenames'])
            || self::basenameAllowed($basename, $config['include_basenames']);

        if (! $stemAllowed && ! in_array($extension, $config['include_extensions'], true)) {
            return 'extension not allowlisted';
        }

        return null;
    }

    /** @param list<string> $allowed */
    private static function basenameAllowed(string $value, array $allowed): bool
    {
        foreach ($allowed as $name) {
            if ($value === $name || str_starts_with($value, $name.'-') || str_starts_with($value, $name.'_')) {
                return true;
            }
        }

        return false;
    }

    private static function rank(string $path): int
    {
        $basename = strtolower(basename($path));

        if (str_starts_with($basename, 'readme')) {
            return 0;
        }

        if (str_ends_with($basename, '.md') || str_ends_with($basename, '.markdown')) {
            return 1;
        }

        if (str_starts_with($basename, 'license') || str_starts_with($basename, 'changelog')
            || str_starts_with($basename, 'contributing') || str_starts_with($basename, 'notice')) {
            return 2;
        }

        return 3;
    }
}
