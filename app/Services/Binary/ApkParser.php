<?php

namespace App\Services\Binary;

use App\Services\Artifacts\ElfParser;

/**
 * APK inspection: ZIP member census, binary-manifest string pool,
 * per-DEX summaries, and embedded native (.so) ELF summaries.
 *
 * Members are inflated only under explicit per-member and total
 * budgets; encrypted or streaming members are reported, never
 * extracted. resource.arsc is detected but not decoded (honest
 * partial — see warnings).
 */
final class ApkParser
{
    public const NAME = 'apk-php';

    public const VERSION = '1.0.0';

    /**
     * @param  array{max_entries?: int, max_member_bytes?: int, max_total_bytes?: int, max_dex?: int, max_so?: int, max_manifest_strings?: int}  $limits
     * @return array{ok: bool, error?: string, arch?: ?string, data?: array<string, mixed>}
     */
    public static function parse(string $bytes, array $limits = []): array
    {
        $limits = array_merge([
            'max_entries' => 2000, 'max_member_bytes' => 8 * 1024 * 1024,
            'max_total_bytes' => 24 * 1024 * 1024, 'max_dex' => 4,
            'max_so' => 4, 'max_manifest_strings' => 500,
        ], $limits);

        $cd = ZipReader::centralDirectory($bytes, (int) $limits['max_entries']);
        if (! ($cd['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'zip_'.($cd['error'] ?? 'unreadable')];
        }

        $entries = $cd['entries'] ?? [];
        $warnings = [];
        if ($cd['truncated'] ?? false) {
            $warnings[] = 'entries_capped';
        }

        $byName = [];
        foreach ($entries as $entry) {
            $byName[$entry['name']] = $entry;
            if ($entry['encrypted']) {
                $warnings[] = 'encrypted_member:'.$entry['name'];
            }
        }

        if (! isset($byName['AndroidManifest.xml'])) {
            return ['ok' => false, 'error' => 'no_manifest'];
        }

        $dexNames = array_values(array_filter(array_keys($byName), fn ($n) => (bool) preg_match('#^classes\d*\.dex$#', $n)));
        sort($dexNames);
        if ($dexNames === []) {
            return ['ok' => false, 'error' => 'no_dex'];
        }

        $spent = 0;
        $readMember = function (array $entry) use ($bytes, $limits, &$spent): ?string {
            if ($entry['encrypted'] || $entry['size'] > $limits['max_member_bytes'] || $spent + $entry['size'] > $limits['max_total_bytes']) {
                return null;
            }
            $raw = $entry['method'] === 0
                ? ZipReader::readStored($bytes, $entry)
                : ZipReader::readInflated($bytes, $entry, (int) $limits['max_member_bytes']);
            if ($raw !== null) {
                $spent += strlen($raw);
            }

            return $raw;
        };

        // Manifest: binary AXML string pool (package, components,
        // permissions surface as searchable strings).
        $manifestBytes = $readMember($byName['AndroidManifest.xml']);
        $manifest = ['present' => true, 'readable' => $manifestBytes !== null, 'kind' => null, 'strings' => [], 'string_count' => 0];
        if ($manifestBytes !== null) {
            $pool = self::axmlStrings($manifestBytes, (int) $limits['max_manifest_strings']);
            $manifest['kind'] = $pool['kind'];
            $manifest['strings'] = $pool['strings'];
            $manifest['string_count'] = $pool['count'];
            if ($pool['kind'] === 'unknown') {
                $warnings[] = 'manifest_unparsed';
            }
        } else {
            $warnings[] = 'manifest_unreadable';
        }

        // DEX members, bounded.
        $dexes = [];
        foreach (array_slice($dexNames, 0, (int) $limits['max_dex']) as $name) {
            $raw = $readMember($byName[$name]);
            if ($raw === null) {
                $warnings[] = 'dex_unreadable:'.$name;

                continue;
            }
            $parsed = DexParser::parse($raw, ['max_strings' => 200, 'max_classes' => 200, 'max_methods' => 200]);
            $dexes[] = [
                'name' => $name,
                'size' => strlen($raw),
                'ok' => $parsed['ok'],
                'error' => $parsed['error'] ?? null,
                'summary' => $parsed['ok'] ? [
                    'version' => $parsed['data']['version'],
                    'classes' => $parsed['data']['class_count'],
                    'methods' => $parsed['data']['method_count'],
                    'strings' => $parsed['data']['string_count'],
                ] : null,
                'classes' => $parsed['ok'] ? $parsed['data']['classes'] : [],
                'methods' => $parsed['ok'] ? array_slice($parsed['data']['methods'], 0, 200) : [],
            ];
        }
        if (count($dexNames) > count($dexes)) {
            $warnings[] = 'dex_members_capped';
        }

        // Native libraries, bounded: delegate to the ELF parser.
        $soNames = array_values(array_filter(array_keys($byName), fn ($n) => str_starts_with($n, 'lib/') && str_ends_with($n, '.so')));
        sort($soNames);
        $nativeLibs = [];
        foreach (array_slice($soNames, 0, (int) $limits['max_so']) as $name) {
            $raw = $readMember($byName[$name]);
            if ($raw === null) {
                $warnings[] = 'so_unreadable:'.$name;

                continue;
            }
            $abi = explode('/', $name)[1] ?? 'unknown';
            $parsed = str_starts_with($raw, "\x7fELF")
                ? ElfParser::parse($raw, ['max_sections' => 64, 'max_symbols' => 200, 'max_strings' => 200, 'max_string_bytes' => 256, 'max_endpoints' => 50])
                : ['ok' => false, 'error' => 'not_elf'];
            $nativeLibs[] = [
                'name' => $name,
                'abi' => $abi,
                'size' => strlen($raw),
                'ok' => $parsed['ok'],
                'error' => $parsed['error'] ?? null,
                'arch' => $parsed['arch'] ?? null,
                'symbols' => $parsed['ok'] ? array_slice(array_column($parsed['data']['symbols'] ?? [], 'name'), 0, 200) : [],
            ];
        }
        if (count($soNames) > count($nativeLibs)) {
            $warnings[] = 'so_members_capped';
        }

        // Split-package markers (unsupported arrangements, detected).
        $splitMarkers = [];
        if (isset($byName['BundleConfig.pb']) || isset($byName['BUNDLE-METADATA/com.android.tools.build.bundletool/version.pb'])) {
            $splitMarkers[] = 'bundletool_metadata_present';
        }
        if (preg_match('/split/i', implode(' ', array_keys($byName))) === 1) {
            $splitMarkers[] = 'split_named_entries_present';
        }
        if ($splitMarkers !== []) {
            $warnings[] = 'possible_split_package';
        }

        if (isset($byName['resources.arsc'])) {
            $warnings[] = 'resources_arsc_not_decoded';
        }

        $memberSummary = array_map(fn ($e) => [
            'name' => mb_substr($e['name'], 0, 200),
            'size' => $e['size'],
            'method' => $e['method'] === 0 ? 'stored' : ($e['method'] === 8 ? 'deflated' : 'method:'.$e['method']),
            'encrypted' => $e['encrypted'],
        ], array_slice($entries, 0, 500));

        return [
            'ok' => true,
            'arch' => 'dalvik',
            'data' => [
                'members' => $memberSummary,
                'member_count' => count($entries),
                'manifest' => $manifest,
                'dexes' => $dexes,
                'dex_count' => count($dexNames),
                'native_libs' => $nativeLibs,
                'native_lib_count' => count($soNames),
                'split_markers' => $splitMarkers,
                'warnings' => $warnings,
            ],
        ];
    }

    /** @return array{kind: string, strings: list<string>, count: int} */
    private static function axmlStrings(string $bytes, int $max): array
    {
        $reader = new ByteReader($bytes);
        if ($reader->length() < 8 || $reader->u16le(0) !== 0x0003) {
            // Not binary AXML: maybe a text manifest (unusual but valid).
            if (str_contains(substr($bytes, 0, 512), '<manifest')) {
                return ['kind' => 'text_xml', 'strings' => [], 'count' => 0];
            }

            return ['kind' => 'unknown', 'strings' => [], 'count' => 0];
        }

        // First chunk should be the string pool (type 0x0001).
        $headerSize = $reader->u16le(2);
        if ($headerSize === null || $headerSize < 8) {
            return ['kind' => 'unknown', 'strings' => [], 'count' => 0];
        }
        $chunk = $headerSize;
        if ($reader->u16le($chunk) !== 0x0001) {
            return ['kind' => 'binary_axml_no_pool', 'strings' => [], 'count' => 0];
        }
        $chunkSize = $reader->u32le($chunk + 4);
        $stringCount = $reader->u32le($chunk + 8);
        $flags = $reader->u32le($chunk + 16);
        $stringsStart = $reader->u32le($chunk + 20);
        if ($chunkSize === null || $stringCount === null || $flags === null || $stringsStart === null) {
            return ['kind' => 'unknown', 'strings' => [], 'count' => 0];
        }
        if ($stringCount > 100000 || $chunk + $chunkSize > $reader->length()) {
            return ['kind' => 'unknown', 'strings' => [], 'count' => 0];
        }

        $utf8 = (bool) ($flags & (1 << 8));
        $strings = [];
        $kept = min($stringCount, $max);
        for ($i = 0; $i < $kept; $i++) {
            $strOff = $reader->u32le($chunk + 28 + $i * 4);
            if ($strOff === null) {
                break;
            }
            $at = $chunk + $stringsStart + $strOff;
            if ($utf8) {
                $len = $reader->u8($at);
                if ($len === null) {
                    break;
                }
                $skip = 1;
                if (($len & 0x80) !== 0) {
                    $len2 = $reader->u8($at + 1);
                    if ($len2 === null) {
                        break;
                    }
                    $len = (($len & 0x7F) << 8) | $len2;
                    $skip = 2;
                }
                // UTF-8 strings carry a second (UTF-16) length prefix.
                $lenB = $reader->u8($at + $skip);
                if ($lenB === null) {
                    break;
                }
                $skip += ($lenB & 0x80) !== 0 ? 2 : 1;
                if ($len > 4096) {
                    continue;
                }
                $raw = $reader->slice($at + $skip, $len);
                if ($raw === null || ! mb_check_encoding($raw, 'UTF-8')) {
                    continue;
                }
                $strings[] = $raw;
            } else {
                $len = $reader->u16le($at);
                if ($len === null) {
                    break;
                }
                $skip = 2;
                if (($len & 0x8000) !== 0) {
                    $len2 = $reader->u16le($at + 2);
                    if ($len2 === null) {
                        break;
                    }
                    $len = (($len & 0x7FFF) << 16) | $len2;
                    $skip = 4;
                }
                if ($len > 4096) {
                    continue;
                }
                $raw = $reader->slice($at + $skip, $len * 2);
                if ($raw === null) {
                    continue;
                }
                $decoded = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
                if (is_string($decoded)) {
                    $strings[] = $decoded;
                }
            }
        }

        return ['kind' => 'binary_axml', 'strings' => $strings, 'count' => $stringCount];
    }
}
