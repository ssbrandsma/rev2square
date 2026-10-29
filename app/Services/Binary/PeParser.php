<?php

namespace App\Services\Binary;

/**
 * Windows PE32/PE32+ parsing: COFF header, optional header, sections,
 * entry point, bounded imports/exports, and CLR (.NET) runtime
 * detection via the COM descriptor directory entry.
 *
 * Pure structural parsing — nothing is mapped or executed, and RVAs
 * are translated to file offsets only for bounded reads.
 */
final class PeParser
{
    public const NAME = 'pe-php';

    public const VERSION = '1.0.0';

    private const MACHINES = [
        0x014C => 'i386',
        0x0162 => 'r3000-mips',
        0x0166 => 'r4000-mips',
        0x0168 => 'r10000-mips',
        0x01A2 => 'sh3',
        0x01A4 => 'sh4',
        0x01C0 => 'arm',
        0x01C2 => 'thumb',
        0x01C4 => 'armv7',
        0x0200 => 'ia64',
        0x0266 => 'mips16',
        0x0284 => 'axp64',
        0x0366 => 'mipsfpu',
        0x0466 => 'mipsfpu16',
        0x0500 => 'riscv32',
        0x0501 => 'riscv64',
        0x0502 => 'riscv128',
        0x0EBC => 'efi',
        0x8664 => 'x86-64',
        0xAA64 => 'arm64',
        0xEB64 => 'efi-x64',
    ];

    /** Machine id → Rev2Square arch slug (null = recognized but unmapped). */
    private const ARCHES = [
        0x014C => 'x86',
        0x8664 => 'x86-64',
        0x01C0 => 'arm32',
        0x01C2 => 'arm32-thumb',
        0x01C4 => 'arm32',
        0xAA64 => 'aarch64',
        0x0162 => 'mips',
        0x0166 => 'mips',
        0x0168 => 'mips',
        0x0500 => 'riscv32',
        0x0501 => 'riscv64',
    ];

    /**
     * @param  array{max_sections?: int, max_imports?: int, max_exports?: int, max_strings?: int}  $limits
     * @return array{ok: bool, error?: string, arch?: ?string, data?: array<string, mixed>}
     */
    public static function parse(string $bytes, array $limits = []): array
    {
        $limits = array_merge(['max_sections' => 96, 'max_imports' => 500, 'max_exports' => 500, 'max_strings' => 2000], $limits);
        $reader = new ByteReader($bytes);
        $length = $reader->length();

        if ($length < 64 || $reader->magic(0, 2) !== 'MZ') {
            return ['ok' => false, 'error' => 'not_pe'];
        }
        $lfanew = $reader->u32le(0x3C);
        if ($lfanew === null || $lfanew > $length - 6 || $reader->magic($lfanew, 4) !== "PE\0\0") {
            return ['ok' => false, 'error' => 'bad_pe_signature'];
        }

        $coff = $lfanew + 4;
        $machine = $reader->u16le($coff);
        $numSections = $reader->u16le($coff + 2);
        $optSize = $reader->u16le($coff + 16);
        $characteristics = $reader->u16le($coff + 18);
        if ($machine === null || $numSections === null || $optSize === null || $characteristics === null) {
            return ['ok' => false, 'error' => 'truncated_coff'];
        }
        if ($numSections > 96) {
            return ['ok' => false, 'error' => 'too_many_sections'];
        }

        $opt = $coff + 20;
        $optMagic = $reader->u16le($opt);
        if ($optMagic !== 0x10B && $optMagic !== 0x20B) {
            return ['ok' => false, 'error' => 'bad_optional_magic'];
        }
        $is64 = $optMagic === 0x20B;
        $need = $is64 ? 112 : 96;
        if ($optSize < $need || $opt + $need > $length) {
            return ['ok' => false, 'error' => 'truncated_optional'];
        }

        $entryRva = $reader->u32le($opt + 16);
        $imageBase = $is64 ? $reader->u64le($opt + 24) : $reader->u32le($opt + 28);
        $subsystem = $reader->u16le($opt + 68);
        $numRvaSizes = $reader->u32le($opt + ($is64 ? 108 : 92));
        $dataDir = $opt + ($is64 ? 112 : 96);
        if ($entryRva === null || $imageBase === null || $subsystem === null || $numRvaSizes === null) {
            return ['ok' => false, 'error' => 'truncated_optional'];
        }

        $sections = [];
        $secTable = $coff + 20 + $optSize;
        $maxSec = min($numSections, $limits['max_sections']);
        for ($i = 0; $i < $maxSec; $i++) {
            $off = $secTable + $i * 40;
            if ($off + 40 > $length) {
                return ['ok' => false, 'error' => 'truncated_sections'];
            }
            $name = rtrim((string) $reader->slice($off, 8), "\0");
            $sections[] = [
                'name' => $name === '' ? '.sec'.$i : $name,
                'virtual_size' => $reader->u32le($off + 8),
                'virtual_address' => $reader->u32le($off + 12),
                'raw_size' => $reader->u32le($off + 16),
                'raw_offset' => $reader->u32le($off + 20),
                'characteristics' => sprintf('0x%08X', $reader->u32le($off + 36) ?? 0),
                'executable' => (bool) (($reader->u32le($off + 36) ?? 0) & 0x20000000),
                'writable' => (bool) (($reader->u32le($off + 36) ?? 0) & 0x80000000),
            ];
        }
        $sectionsTruncated = $numSections > $maxSec;

        $translate = function (int $rva) use ($sections): ?int {
            foreach ($sections as $s) {
                $size = max((int) $s['virtual_size'], (int) $s['raw_size']);
                if ($rva >= (int) $s['virtual_address'] && $rva < (int) $s['virtual_address'] + $size) {
                    return (int) $s['raw_offset'] + ($rva - (int) $s['virtual_address']);
                }
            }

            return null;
        };

        $dir = function (int $index) use ($reader, $dataDir, $numRvaSizes, $length): ?array {
            if ($index >= $numRvaSizes || $dataDir + ($index + 1) * 8 > $length) {
                return null;
            }
            $rva = $reader->u32le($dataDir + $index * 8);
            $size = $reader->u32le($dataDir + $index * 8 + 4);
            if ($rva === null || $size === null || $rva === 0) {
                return null;
            }

            return [$rva, $size];
        };

        $imports = self::imports($reader, $translate, $dir(1), (int) $limits['max_imports'], $is64);
        $exports = self::exports($reader, $translate, $dir(0), (int) $limits['max_exports']);

        // Data directory 14: CLR runtime header (COM descriptor).
        $clr = $dir(14);
        $runtime = $clr !== null ? 'clr' : 'native';
        $clrHeader = null;
        if ($clr !== null) {
            $clrOff = $translate($clr[0]);
            $clrHeader = $clrOff !== null ? [
                'rva' => $clr[0],
                'size' => $clr[1],
                'metadata_rva' => $reader->u32le($clrOff + 8),
                'metadata_size' => $reader->u32le($clrOff + 12),
                'flags' => $reader->u32le($clrOff + 16) !== null ? sprintf('0x%08X', $reader->u32le($clrOff + 16)) : null,
            ] : ['rva' => $clr[0], 'size' => $clr[1], 'unmapped' => true];
        }

        $entryOffset = $translate($entryRva);
        $strings = StringsExtractor::extract($bytes, (int) $limits['max_strings']);

        $warnings = [];
        if ($sectionsTruncated) {
            $warnings[] = 'sections_capped';
        }
        if ($imports['truncated'] || $exports['truncated']) {
            $warnings[] = 'imports_exports_capped';
        }
        if ($strings['truncated']) {
            $warnings[] = 'strings_capped';
        }
        if ($entryOffset === null) {
            $warnings[] = 'entry_unmapped';
        }
        if (! isset(self::MACHINES[$machine])) {
            $warnings[] = 'unsupported_machine:'.$machine;
        }

        return [
            'ok' => true,
            'arch' => self::ARCHES[$machine] ?? null,
            'data' => [
                'pe_type' => $is64 ? 'PE32+' : 'PE32',
                'machine' => ['id' => $machine, 'name' => self::MACHINES[$machine] ?? 'UNKNOWN'],
                'runtime' => $runtime,
                'characteristics' => sprintf('0x%04X', $characteristics),
                'is_dll' => (bool) ($characteristics & 0x2000),
                'subsystem' => $subsystem,
                'entry_rva' => $entryRva,
                'entry_file_offset' => $entryOffset,
                'image_base' => $imageBase,
                'sections' => $sections,
                'imports' => $imports['modules'],
                'import_count' => $imports['count'],
                'exports' => $exports['names'],
                'export_count' => $exports['count'],
                'clr' => $clrHeader,
                'strings' => $strings['strings'],
                'string_stats' => ['kept' => count($strings['strings']), 'truncated' => $strings['truncated']],
                'warnings' => $warnings,
            ],
        ];
    }

    /**
     * @param  callable(int): ?int  $translate
     * @return array{modules: list<array{dll: string, functions: list<string>}>, count: int, truncated: bool}
     */
    private static function imports(ByteReader $reader, callable $translate, ?array $dir, int $max, bool $is64): array
    {
        $modules = [];
        $count = 0;
        $truncated = false;
        if ($dir === null) {
            return ['modules' => [], 'count' => 0, 'truncated' => false];
        }

        $off = $translate($dir[0]);
        if ($off === null) {
            return ['modules' => [], 'count' => 0, 'truncated' => false];
        }

        // Import directory: 20-byte entries, terminated by zeros.
        for ($i = 0; $i < 256; $i++) {
            $base = $off + $i * 20;
            if ($base + 20 > $reader->length()) {
                break;
            }
            $ilt = $reader->u32le($base);
            $nameRva = $reader->u32le($base + 12);
            $iat = $reader->u32le($base + 16);
            if ($ilt === null || $nameRva === null || $iat === null) {
                break;
            }
            if ($ilt === 0 && $nameRva === 0 && $iat === 0) {
                break;
            }
            $nameOff = $translate($nameRva);
            $dll = $nameOff !== null ? $reader->cstring($nameOff, 128) : null;
            if ($dll === null || $dll === '') {
                continue;
            }

            $table = $ilt !== 0 ? $ilt : $iat;
            $functions = [];
            $step = $is64 ? 8 : 4;
            for ($j = 0; $j < 256; $j++) {
                $entryOff = $translate($table + $j * $step);
                if ($entryOff === null) {
                    break;
                }
                $value = $is64 ? $reader->u64le($entryOff) : $reader->u32le($entryOff);
                if ($value === null || $value === 0) {
                    break;
                }
                $ordinalBit = $is64 ? (1 << 63) : (1 << 31);
                if (($value & $ordinalBit) !== 0) {
                    $functions[] = 'ordinal:'.($value & 0xFFFF);
                } else {
                    $hintOff = $translate((int) ($value & 0x7FFFFFFF));
                    $name = $hintOff !== null ? $reader->cstring($hintOff + 2, 256) : null;
                    $functions[] = $name !== null && $name !== '' ? $name : 'hint:'.($value & 0xFFFF);
                }
                $count++;
                if ($count >= $max) {
                    $truncated = true;
                    break 2;
                }
            }
            $modules[] = ['dll' => $dll, 'functions' => $functions];
        }

        return ['modules' => $modules, 'count' => $count, 'truncated' => $truncated];
    }

    /**
     * @param  callable(int): ?int  $translate
     * @return array{names: list<string>, count: int, truncated: bool}
     */
    private static function exports(ByteReader $reader, callable $translate, ?array $dir, int $max): array
    {
        if ($dir === null) {
            return ['names' => [], 'count' => 0, 'truncated' => false];
        }
        $off = $translate($dir[0]);
        if ($off === null || $off + 40 > $reader->length()) {
            return ['names' => [], 'count' => 0, 'truncated' => false];
        }

        $numNames = $reader->u32le($off + 24);
        $addrOfNames = $reader->u32le($off + 32);
        if ($numNames === null || $addrOfNames === null || $numNames > 65536) {
            return ['names' => [], 'count' => 0, 'truncated' => false];
        }

        $names = [];
        $kept = min($numNames, $max);
        for ($i = 0; $i < $kept; $i++) {
            $ptrOff = $translate($addrOfNames + $i * 4);
            if ($ptrOff === null) {
                break;
            }
            $nameRva = $reader->u32le($ptrOff);
            if ($nameRva === null) {
                break;
            }
            $nameOff = $translate($nameRva);
            $name = $nameOff !== null ? $reader->cstring($nameOff, 256) : null;
            if ($name !== null && $name !== '') {
                $names[] = $name;
            }
        }

        return ['names' => $names, 'count' => $numNames, 'truncated' => $numNames > $max];
    }
}
