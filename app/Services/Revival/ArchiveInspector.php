<?php

namespace App\Services\Revival;

use App\Services\GitHub\ArchiveInvalidException;
use ZipArchive;

/**
 * Lists and validates a downloaded source archive without extracting it:
 * entry bytes are streamed straight out of the zip, so traversal entries
 * can never escape onto disk. Nothing is executed.
 *
 * @phpstan-type ArchiveEntry array{index: int, path: string, size: int}
 */
class ArchiveInspector
{
    /**
     * Inspect an archive listing against the configured budgets.
     *
     * @return array{prefix: ?string, tree: list<array{path: string, type: string, size: int, sha: null}>, explicit: list<array{path: string, extension: null, language: null, kind: string, size_bytes: int, blob_sha: null, subsystem: string, overview: bool, eligible: bool, skip_reason: string}>, expanded_bytes: int, entry_count: int, capped: bool, warnings: list<string>, submodules: list<string>}
     */
    public static function inspect(string $zipPath, array $config): array
    {
        $zip = new ZipArchive;
        $opened = $zip->open($zipPath, ZipArchive::RDONLY);

        if ($opened !== true) {
            throw new ArchiveInvalidException('Could not open the repository archive (malformed download).');
        }

        try {
            return self::inspectOpen($zip, $zipPath, $config);
        } finally {
            $zip->close();
        }
    }

    /**
     * Read one entry's bytes, bounded. Returns null when the entry exceeds
     * the read cap instead of loading it into memory.
     */
    public static function readEntry(string $zipPath, int $index, int $maxBytes): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            $stat = $zip->statIndex($index);

            if ($stat === false || ($stat['size'] ?? 0) > $maxBytes) {
                return null;
            }

            $stream = $zip->getStream($zip->getNameIndex($index) ?: '');

            if (! is_resource($stream)) {
                return null;
            }

            $content = stream_get_contents($stream, $maxBytes + 1);
            fclose($stream);

            if (! is_string($content) || strlen($content) > $maxBytes) {
                return null;
            }

            return $content;
        } finally {
            $zip->close();
        }
    }

    /** @return array{prefix: ?string, tree: list, explicit: list, expanded_bytes: int, entry_count: int, capped: bool, warnings: list<string>, submodules: list<string>} */
    private static function inspectOpen(ZipArchive $zip, string $zipPath, array $config): array
    {
        $files = [];
        $expandedBytes = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'] ?? '';

            if ($name === '' || str_ends_with($name, '/')) {
                continue; // Directories carry no content.
            }

            // statIndex carries no attribute data; modes come from the
            // external attributes, trusted only on unix-made entries.
            $opsys = 0;
            $attr = 0;
            $zip->getExternalAttributesIndex($i, $opsys, $attr);

            $files[] = [
                'index' => $i,
                'name' => $name,
                'size' => max(0, (int) ($stat['size'] ?? 0)),
                'mode' => $opsys === ZipArchive::OPSYS_UNIX ? $attr >> 16 : 0,
            ];
            $expandedBytes += max(0, (int) ($stat['size'] ?? 0));
        }

        $maxExpanded = (int) ($config['max_expanded_bytes'] ?? 512000000);

        if ($expandedBytes > $maxExpanded) {
            throw new ArchiveInvalidException(sprintf(
                'Repository archive expands to %s bytes, over the %s budget; it cannot be imported.',
                number_format($expandedBytes),
                number_format($maxExpanded)
            ));
        }

        $compressed = filesize($zipPath) ?: 1;
        $maxRatio = max(1, (int) ($config['max_archive_ratio'] ?? 200));

        if ($expandedBytes / $compressed > $maxRatio) {
            throw new ArchiveInvalidException(
                'Repository archive has a suspicious compression ratio; refusing to import.'
            );
        }

        $prefix = self::commonPrefix($files);
        $warnings = [];
        $tree = [];
        $explicit = [];
        $seen = [];

        foreach ($files as $file) {
            $stripped = $prefix !== null ? substr($file['name'], strlen($prefix) + 1) : $file['name'];
            // Normalize so './x' and 'x' cannot import as two files; paths
            // escaping the root keep their raw form for the unsafe verdict.
            $path = self::normalizePath($stripped) ?? $stripped;

            if (($file['mode'] & 0xF000) === 0xA000) {
                $explicit[] = self::explicitRow($path, $file['size'], 'symlink not followed');

                continue;
            }

            if (isset($seen[$path])) {
                $explicit[] = self::explicitRow($path, $file['size'], 'duplicate archive entry');

                continue;
            }

            $seen[$path] = true;
            $tree[] = ['index' => $file['index'], 'path' => $path, 'type' => 'blob', 'size' => $file['size'], 'sha' => null];
        }

        // Deterministic archive cap, mirroring the inventory cap below it.
        $maxEntries = max(1, (int) ($config['max_archive_entries'] ?? 20000));
        usort($tree, fn ($a, $b) => $a['path'] <=> $b['path']);
        $capped = count($tree) > $maxEntries;

        if ($capped) {
            $warnings[] = sprintf(
                'Archive listing capped at %d entries; %d further paths were not recorded.',
                $maxEntries,
                count($tree) - $maxEntries
            );
            $tree = array_slice($tree, 0, $maxEntries);
        }

        $submodules = self::submodulePaths($zipPath, $tree);

        if ($submodules !== []) {
            $kept = [];

            foreach ($tree as $entry) {
                if (self::underSubmodule($entry['path'], $submodules)) {
                    $explicit[] = self::explicitRow($entry['path'], $entry['size'], 'submodule content not included');
                } else {
                    $kept[] = $entry;
                }
            }

            $tree = $kept;
            $warnings[] = 'Submodules are not expanded; their contents were not imported: '.implode(', ', $submodules);
        }

        return [
            'prefix' => $prefix,
            'tree' => array_map(fn ($e) => ['path' => $e['path'], 'type' => 'blob', 'size' => $e['size'], 'sha' => null, 'index' => $e['index']], $tree),
            'explicit' => $explicit,
            'expanded_bytes' => $expandedBytes,
            'entry_count' => count($files),
            'capped' => $capped,
            'warnings' => $warnings,
            'submodules' => $submodules,
        ];
    }

    /**
     * Codeload prefixes every entry with "<repo>-<sha>/". Strip exactly that
     * shared top segment; anything else is used verbatim.
     *
     * @param  list<array{name: string}>  $files
     */
    private static function commonPrefix(array $files): ?string
    {
        $first = null;

        foreach ($files as $file) {
            $segment = str_contains($file['name'], '/') ? substr($file['name'], 0, strpos($file['name'], '/')) : null;

            if ($segment === null || $segment === '') {
                return null;
            }

            $first ??= $segment;

            if ($segment !== $first) {
                return null;
            }
        }

        return $first;
    }

    /**
     * Resolve '.' segments and empty parts so equivalent spellings dedupe.
     * Returns null for absolute paths and root escapes, which keep their raw
     * form so downstream classification reports them as unsafe.
     */
    private static function normalizePath(string $path): ?string
    {
        if (str_starts_with($path, '/')) {
            return null;
        }

        $out = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($out === []) {
                    return null;
                }

                array_pop($out);

                continue;
            }

            $out[] = $segment;
        }

        return implode('/', $out);
    }

    /** @param  list<array{path: string, size: int, index: int}>  $tree */
    private static function submodulePaths(string $zipPath, array $tree): array
    {
        foreach ($tree as $entry) {
            if ($entry['path'] !== '.gitmodules' || $entry['size'] > 65536) {
                continue;
            }

            $content = self::readEntry($zipPath, $entry['index'], 65536);

            if ($content === null) {
                continue;
            }

            $paths = [];

            foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
                if (preg_match('/^\s*path\s*=\s*(.+?)\s*$/', $line, $m)) {
                    $paths[] = trim($m[1], " \t\"'");
                }
            }

            $paths = array_values(array_unique(array_filter($paths)));
            sort($paths);

            return $paths;
        }

        return [];
    }

    /** @param  list<string>  $submodules */
    private static function underSubmodule(string $path, array $submodules): bool
    {
        foreach ($submodules as $sub) {
            if ($path === $sub || str_starts_with($path, $sub.'/')) {
                return true;
            }
        }

        return false;
    }

    /** @return array{path: string, extension: null, language: null, kind: string, size_bytes: int, blob_sha: null, subsystem: string, overview: bool, eligible: bool, skip_reason: string} */
    private static function explicitRow(string $path, int $size, string $reason): array
    {
        return [
            'path' => $path,
            'extension' => null,
            'language' => null,
            'kind' => 'unknown',
            'size_bytes' => $size,
            'blob_sha' => null,
            'subsystem' => FileClassifier::subsystem($path),
            'overview' => false,
            'eligible' => false,
            'skip_reason' => $reason,
        ];
    }
}
