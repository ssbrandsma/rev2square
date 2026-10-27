<?php

namespace App\Services\Revival;

/**
 * Deterministic, explainable relevance scoring for inventory entries.
 *
 * Pure functions of (entry, repository profile): the same commit and
 * configuration always produce the same scores. No language is preferred
 * a priori — the score adapts to the repository's own language mix.
 */
class RelevanceScorer
{
    /** kind => base score */
    private const KIND_SCORES = [
        'readme' => 100,
        'doc' => 30,
        'license' => 10,
        'source' => 50,
        'script' => 40,
        'config' => 35,
        'build' => 60,
        'patch' => 45,
        'lockfile' => 5,
        'data' => 8,
        'unknown' => 12,
    ];

    private const ENTRY_POINTS = [
        'main', 'app', 'index', 'server', 'cli', 'manage', '__main__',
        'mod', 'lib', 'setup', 'firmware', 'bootloader',
    ];

    private const HARDWARE_KEYWORDS = [
        'firmware', 'driver', 'hal', 'usb', 'hid', 'uart', 'i2c', 'spi',
        'gpio', 'ble', 'bluetooth', 'radio', 'rf', 'zigbee', 'lora',
        'canbus', 'modbus', 'protocol', 'packet', 'ir', 'infrared',
        'remote', 'sensor', 'actuator', 'motor', 'servo', 'esc',
        'bootloader', 'flash', 'eeprom', 'register', 'peripheral',
        'mcu', 'stm32', 'esp32', 'esp8266', 'arduino', 'rp2040', 'nrf',
        'fpga', 'schematic', 'pcb', 'kicad', 'jtag', 'swd', 'boot',
    ];

    /**
     * Aggregate repository profile from inventory entries.
     *
     * @param  list<array{path: string, language: ?string, kind: string, size_bytes: int}>  $entries
     * @return array{top_languages: list<string>, basenames: array<string, int>, dir_stats: array<string, array{sources: int, headers: int}>}
     */
    public static function profile(array $entries): array
    {
        $languageBytes = [];
        $basenames = [];
        $dirStats = [];

        foreach ($entries as $entry) {
            $language = $entry['language'] ?? null;

            if (is_string($language) && $language !== '') {
                $languageBytes[$language] = ($languageBytes[$language] ?? 0) + (int) ($entry['size_bytes'] ?? 0);
            }

            $basename = strtolower(basename($entry['path']));
            $basenames[$basename] = ($basenames[$basename] ?? 0) + 1;

            $dir = str_contains($entry['path'], '/') ? dirname($entry['path']) : '(root)';
            $dirStats[$dir] ??= ['sources' => 0, 'headers' => 0];

            if (($entry['kind'] ?? '') === 'source') {
                $dirStats[$dir]['sources']++;

                $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));

                if (in_array($ext, ['h', 'hpp', 'hh', 'hxx'], true)) {
                    $dirStats[$dir]['headers']++;
                }
            }
        }

        arsort($languageBytes);

        return [
            'top_languages' => array_slice(array_keys($languageBytes), 0, 2),
            'basenames' => $basenames,
            'dir_stats' => $dirStats,
        ];
    }

    /**
     * @param  array{path: string, extension: ?string, language: ?string, kind: string, size_bytes: int, subsystem: string, overview: bool}  $entry
     * @param  array{top_languages: list<string>, basenames: array<string, int>, dir_stats: array<string, array{sources: int, headers: int}>}  $profile
     * @param  list<string>  $readmeRefs  Inventory paths referenced from README/docs.
     * @return array{score: int, reasons: list<string>}
     */
    public static function score(array $entry, array $profile, array $readmeRefs = [], array $config = []): array
    {
        $score = 0;
        $reasons = [];
        $path = $entry['path'];
        $lowerPath = strtolower($path);
        $basename = strtolower(basename($path));
        $stem = strtolower(pathinfo($basename, PATHINFO_FILENAME));
        $kind = $entry['kind'];
        $size = (int) ($entry['size_bytes'] ?? 0);

        $add = function (int $points, string $reason) use (&$score, &$reasons) {
            $score += $points;
            $reasons[] = $reason;
        };

        $add(self::KIND_SCORES[$kind] ?? 10, "kind:{$kind}");

        if ($kind === 'doc' && str_starts_with($basename, 'architecture')) {
            $add(55, 'architecture doc');
        }

        if (self::isTestPath($lowerPath, $basename)) {
            $add(25, 'test revealing device behavior');
        }

        if (in_array($stem, self::ENTRY_POINTS, true) && in_array($kind, ['source', 'script'], true)) {
            $add(40, 'application entry point');
        } elseif (! str_contains($path, '/') && $kind === 'source') {
            $add(15, 'root-level source');
        }

        $keywordHits = 0;

        foreach (self::HARDWARE_KEYWORDS as $keyword) {
            if (str_contains($lowerPath, $keyword)) {
                $keywordHits++;
            }
        }

        if ($keywordHits > 0) {
            $add(min($keywordHits * 25, 50), 'hardware/protocol relevance');
        }

        foreach (self::HARDWARE_KEYWORDS as $keyword) {
            if (str_contains($basename, $keyword)) {
                $add(20, 'hardware keyword in filename');

                break;
            }
        }

        if (in_array($path, $readmeRefs, true)) {
            $add(35, 'referenced from README/docs');
        }

        $language = $entry['language'] ?? null;

        if (is_string($language) && in_array($language, $profile['top_languages'] ?? [], true)) {
            $add(15, 'core repository language');
        }

        $dir = str_contains($path, '/') ? dirname($path) : '(root)';
        $stats = $profile['dir_stats'][$dir] ?? ['sources' => 0, 'headers' => 0];

        if ($kind === 'source' && $stats['sources'] >= 2 && $stats['headers'] >= 1) {
            $add(20, 'implementation cluster (sources + headers)');
        }

        $depth = count(explode('/', $path));

        if ($depth > 2) {
            $penalty = min(($depth - 2) * 3, 15);
            $score -= $penalty;
            $reasons[] = 'deep path penalty';
        }

        if ($size > 0 && $size < 100) {
            $score -= 5;
            $reasons[] = 'tiny file';
        } elseif ($size >= 1024 && $size <= 61440) {
            $add(10, 'informative size');
        } elseif ($size > ($config['max_extraction_bytes'] ?? $config['max_file_bytes'] ?? 100000)) {
            $score -= 10;
            $reasons[] = 'will need partial extraction';
        }

        if (($profile['basenames'][$basename] ?? 0) > 2) {
            $score -= 15;
            $reasons[] = 'duplicated filename';
        }

        return ['score' => max(0, $score), 'reasons' => $reasons];
    }

    /**
     * Find inventory paths mentioned in README / documentation text.
     *
     * @param  list<string>  $inventoryPaths
     * @return list<string> Sorted referenced paths (deterministic).
     */
    public static function extractReferences(string $content, array $inventoryPaths): array
    {
        if ($content === '' || $inventoryPaths === []) {
            return [];
        }

        $known = array_fill_keys($inventoryPaths, true);
        $found = [];

        if (preg_match_all('#[\w\-.]+(?:/[\w\-.]+)+\.[\w]+#', $content, $matches) === false) {
            return [];
        }

        foreach ($matches[0] as $candidate) {
            $candidate = ltrim($candidate, './');

            if (isset($known[$candidate])) {
                $found[$candidate] = true;
            }
        }

        $refs = array_keys($found);
        sort($refs);

        return $refs;
    }

    private static function isTestPath(string $lowerPath, string $basename): bool
    {
        if (str_starts_with($basename, 'test_') || str_starts_with($basename, 'test-')
            || str_ends_with($basename, '_test.py') || str_ends_with($basename, '_test.go')
            || str_ends_with($basename, '.test.js') || str_ends_with($basename, '.test.ts')
            || str_ends_with($basename, '.spec.js') || str_ends_with($basename, '.spec.ts')) {
            return true;
        }

        foreach (explode('/', $lowerPath) as $segment) {
            if (in_array($segment, ['test', 'tests', '__tests__', 'spec', 'testing'], true)) {
                return true;
            }
        }

        return false;
    }
}
