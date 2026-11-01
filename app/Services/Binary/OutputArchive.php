<?php

namespace App\Services\Binary;

use App\Services\Binary\Adapters\SandboxedBackend;
use App\Services\Revival\ArchiveInspector;
use ZipArchive;

/**
 * Read-only access to a run's retained tool-output archive
 * (output.zip). The central directory is listed without extracting
 * anything; single entries stream out bounded through
 * ArchiveInspector::readEntry. Directory entries, symlinks, and
 * unsafe names are skipped and counted, never served.
 */
final class OutputArchive
{
    public const PROVENANCE = 'rev2square-output.json';

    /** Source extensions the runner prioritizes (rank 0). */
    public const SOURCE_EXTENSIONS = ['java', 'kt', 'kts', 'cs'];

    /** Hard ceiling for central-directory iteration sanity. */
    public const MAX_LIST_ENTRIES = 100000;

    /**
     * List servable entries: name, size, and kind (source or
     * resource). Null when the zip does not open or is hostile.
     *
     * @return array{entries: list<array{name: string, size: int, kind: string}>, skipped: int}|null
     */
    public static function listEntries(string $zipPath): ?array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            return null;
        }
        try {
            if ($zip->numFiles < 0 || $zip->numFiles > self::MAX_LIST_ENTRIES) {
                return null;
            }
            $entries = [];
            $skipped = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
                if ($name === '' || str_ends_with($name, '/') || $name === self::PROVENANCE
                    || ! SandboxedBackend::safeRelativePath($name)) {
                    $skipped++;

                    continue;
                }
                $opsys = 0;
                $attr = 0;
                $zip->getExternalAttributesIndex($i, $opsys, $attr);
                if ($opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0xF000) === 0xA000) {
                    $skipped++;

                    continue;
                }
                $entries[] = [
                    'name' => $name,
                    'size' => max(0, (int) ($stat['size'] ?? 0)),
                    'kind' => self::kindOf($name),
                ];
            }
            usort($entries, fn ($a, $b) => [$a['kind'] === 'source' ? 0 : 1, $a['name']] <=> [$b['kind'] === 'source' ? 0 : 1, $b['name']]);

            return ['entries' => $entries, 'skipped' => $skipped];
        } finally {
            $zip->close();
        }
    }

    /**
     * Read one entry by exact name, bounded. Returns the bytes, or
     * null when the entry is missing, a symlink, unreadable, or over
     * the cap. Symlink and name checks repeat here so direct callers
     * get the same guarantees as the listing flow.
     */
    public static function readEntry(string $zipPath, string $name, int $maxBytes): ?string
    {
        if (! SandboxedBackend::safeRelativePath($name)) {
            return null;
        }
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            return null;
        }
        try {
            if ($zip->numFiles < 0 || $zip->numFiles > self::MAX_LIST_ENTRIES) {
                return null;
            }
            $index = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if ($zip->getNameIndex($i) === $name) {
                    $opsys = 0;
                    $attr = 0;
                    $zip->getExternalAttributesIndex($i, $opsys, $attr);
                    if ($opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0xF000) === 0xA000) {
                        return null;
                    }
                    $index = $i;

                    break;
                }
            }
        } finally {
            $zip->close();
        }
        if ($index === null) {
            return null;
        }

        return ArchiveInspector::readEntry($zipPath, $index, $maxBytes);
    }

    public static function kindOf(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return in_array($ext, self::SOURCE_EXTENSIONS, true) ? 'source' : 'resource';
    }
}
