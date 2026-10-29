<?php

namespace App\Services\Binary;

/**
 * Java class-file metadata: version, constant-pool census, class and
 * super-class names, and bounded method/field references. Bytecode
 * instructions are counted per tag but not disassembled here —
 * method bodies stay available to a Java-capable adapter.
 */
final class ClassParser
{
    public const NAME = 'class-php';

    public const VERSION = '1.0.0';

    public const MAX_POOL = 10000;

    /**
     * @param  array{max_pool?: int, max_members?: int, max_strings?: int}  $limits
     * @return array{ok: bool, error?: string, arch?: ?string, data?: array<string, mixed>}
     */
    public static function parse(string $bytes, array $limits = []): array
    {
        $limits = array_merge(['max_pool' => self::MAX_POOL, 'max_members' => 500, 'max_strings' => 500], $limits);
        $reader = new ByteReader($bytes);
        if ($reader->magic(0, 4) !== "\xCA\xFE\xBA\xBE") {
            return ['ok' => false, 'error' => 'not_class'];
        }
        $minor = $reader->u16be(4);
        $major = $reader->u16be(6);
        $poolCount = $reader->u16be(8);
        if ($minor === null || $major === null || $poolCount === null || $poolCount < 1) {
            return ['ok' => false, 'error' => 'truncated_header'];
        }
        if ($major < 45 || $major > 75) {
            return ['ok' => false, 'error' => 'bad_version'];
        }

        // Bounded constant-pool walk: resolve Utf8 + Class names only,
        // count member refs. Long/Double consume two slots.
        $utf8 = [];
        $classes = [];
        $memberRefs = 0;
        $tagCounts = [];
        $pos = 10;
        $kept = min($poolCount - 1, $limits['max_pool']);
        $truncated = ($poolCount - 1) > $kept;
        for ($i = 1; $i <= $kept; $i++) {
            $tag = $reader->u8($pos);
            if ($tag === null) {
                return ['ok' => false, 'error' => 'truncated_pool'];
            }
            $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
            $pos++;
            switch ($tag) {
                case 1: // Utf8
                    $len = $reader->u16be($pos);
                    if ($len === null) {
                        return ['ok' => false, 'error' => 'truncated_pool'];
                    }
                    $raw = $reader->slice($pos + 2, $len);
                    if ($raw === null) {
                        return ['ok' => false, 'error' => 'truncated_pool'];
                    }
                    if (strlen($raw) <= 256 && mb_check_encoding($raw, 'UTF-8')) {
                        $utf8[$i] = $raw;
                    }
                    $pos += 2 + $len;
                    break;
                case 7: // Class
                    $nameIdx = $reader->u16be($pos);
                    if ($nameIdx === null) {
                        return ['ok' => false, 'error' => 'truncated_pool'];
                    }
                    $classes[$i] = $nameIdx;
                    $pos += 2;
                    break;
                case 5: case 6: // Long, Double: two slots
                    if ($reader->slice($pos, 8) === null) {
                        return ['ok' => false, 'error' => 'truncated_pool'];
                    }
                    $pos += 8;
                    $i++;
                    break;
                case 3: case 4: case 9: case 10: case 11: case 12: case 17: case 18:
                    if ($reader->slice($pos, 4) === null) {
                        return ['ok' => false, 'error' => 'truncated_pool'];
                    }
                    if (in_array($tag, [9, 10, 11], true)) {
                        $memberRefs++;
                    }
                    $pos += 4;
                    break;
                case 8:
                    if ($reader->slice($pos, 2) === null) {
                        return ['ok' => false, 'error' => 'truncated_pool'];
                    }
                    $pos += 2;
                    break;
                case 15:
                    if ($reader->slice($pos, 3) === null) {
                        return ['ok' => false, 'error' => 'truncated_pool'];
                    }
                    $pos += 3;
                    break;
                case 16: case 19: case 20:
                    if ($reader->slice($pos, 2) === null) {
                        return ['ok' => false, 'error' => 'truncated_pool'];
                    }
                    $pos += 2;
                    break;
                default:
                    return ['ok' => false, 'error' => 'bad_pool_tag:'.$tag];
            }
        }

        $resolveClass = fn (int $idx): ?string => isset($classes[$idx]) && isset($utf8[$classes[$idx]]) ? str_replace('/', '.', $utf8[$classes[$idx]]) : null;

        $thisIdx = $reader->u16be($pos);
        $superIdx = $reader->u16be($pos + 2);
        if ($thisIdx === null || $superIdx === null) {
            return ['ok' => false, 'error' => 'truncated_body'];
        }

        $warnings = [];
        if ($truncated) {
            $warnings[] = 'pool_capped';
        }

        $strings = array_values(array_filter($utf8, fn ($s, $idx) => strlen($s) >= 4, ARRAY_FILTER_USE_BOTH));
        $strings = array_slice($strings, 0, (int) $limits['max_strings']);

        return [
            'ok' => true,
            'arch' => 'jvm',
            'data' => [
                'version' => ['major' => $major, 'minor' => $minor, 'java' => $major >= 49 ? 'Java '.($major - 44) : 'Java 1.'.($major - 44)],
                'pool_entries' => $poolCount - 1,
                'class_name' => $resolveClass($thisIdx),
                'super_name' => $superIdx === 0 ? null : $resolveClass($superIdx),
                'member_refs' => $memberRefs,
                'pool_tag_counts' => $tagCounts,
                'pool_strings' => $strings,
                'warnings' => $warnings,
            ],
        ];
    }
}
