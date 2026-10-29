<?php

namespace App\Services\Binary;

/**
 * Mach-O parsing: thin 32/64-bit headers (both endians), FAT member
 * enumeration, segment/section walk, and bounded linked-library
 * names. FAT members are described, not recursively analyzed —
 * analysis targets one member at a time via explicit selection.
 */
final class MachOParser
{
    public const NAME = 'macho-php';

    public const VERSION = '1.0.0';

    private const CPUTYPES = [
        7 => 'i386',
        12 => 'arm',
        18 => 'ppc',
        0x01000007 => 'x86-64',
        0x0100000C => 'arm64',
    ];

    private const ARCHES = [
        7 => 'x86',
        12 => 'arm32',
        18 => 'ppc',
        0x01000007 => 'x86-64',
        0x0100000C => 'aarch64',
    ];

    /**
     * @param  array{max_members?: int, max_commands?: int, max_sections?: int, max_dl?: int, max_strings?: int}  $limits
     * @return array{ok: bool, error?: string, arch?: ?string, data?: array<string, mixed>}
     */
    public static function parse(string $bytes, array $limits = []): array
    {
        $limits = array_merge(['max_members' => 16, 'max_commands' => 128, 'max_sections' => 256, 'max_dl' => 200, 'max_strings' => 2000], $limits);
        $reader = new ByteReader($bytes);
        $magic = $reader->u32be(0);
        if ($magic === null) {
            return ['ok' => false, 'error' => 'too_small'];
        }

        if ($magic === 0xCAFEBABE) {
            return self::parseFat($reader, $limits);
        }
        if ($magic === 0xBEBAFECA) {
            return ['ok' => false, 'error' => 'fat64_unsupported'];
        }

        return self::parseThin($reader, 0, $limits, null);
    }

    /** @return array{ok: bool, error?: string, arch?: ?string, data?: array<string, mixed>} */
    private static function parseFat(ByteReader $reader, array $limits): array
    {
        $count = $reader->u32be(4);
        if ($count === null || $count < 1 || $count > 64) {
            return ['ok' => false, 'error' => 'bad_fat_count'];
        }

        $members = [];
        $warnings = [];
        $kept = min($count, $limits['max_members']);
        for ($i = 0; $i < $kept; $i++) {
            $off = 8 + $i * 20;
            $cputype = $reader->u32be($off);
            $cpusub = $reader->u32be($off + 4);
            $moff = $reader->u32be($off + 8);
            $msize = $reader->u32be($off + 12);
            if ($cputype === null || $cpusub === null || $moff === null || $msize === null) {
                return ['ok' => false, 'error' => 'truncated_fat'];
            }
            if ($moff + $msize > $reader->length()) {
                $warnings[] = 'fat_member_out_of_bounds:'.$i;

                continue;
            }
            $members[] = [
                'index' => $i,
                'cputype' => $cputype,
                'cpusubtype' => $cpusub,
                'cpu_name' => self::CPUTYPES[$cputype] ?? 'UNKNOWN',
                'arch' => self::ARCHES[$cputype] ?? null,
                'offset' => $moff,
                'size' => $msize,
            ];
        }
        if ($count > $kept) {
            $warnings[] = 'fat_members_capped';
        }

        $first = $members[0] ?? null;
        $inner = null;
        if ($first !== null) {
            // Describe (not recurse into) the first member's header.
            $inner = self::parseThin($reader, $first['offset'], $limits, $first);
            if (! ($inner['ok'] ?? false)) {
                $warnings[] = 'first_member_unparsed:'.($inner['error'] ?? 'unknown');
                $inner = null;
            } else {
                $inner = $inner['data'];
            }
        }

        return [
            'ok' => true,
            'arch' => $first['arch'] ?? null,
            'data' => [
                'kind' => 'fat',
                'members' => $members,
                'member_count' => $count,
                'first_member' => $inner,
                'warnings' => $warnings,
            ],
        ];
    }

    /** @return array{ok: bool, error?: string, arch?: ?string, data?: array<string, mixed>} */
    private static function parseThin(ByteReader $reader, int $base, array $limits, ?array $member): array
    {
        $magic = $reader->u32le($base);
        $is64 = $magic === 0xFEEDFACF;
        $is32 = $magic === 0xFEEDFACE;
        $be = false;
        if (! $is64 && ! $is32) {
            $bem = $reader->u32be($base);
            $is64 = $bem === 0xFEEDFACF;
            $is32 = $bem === 0xFEEDFACE;
            $be = $is64 || $is32;
        }
        if (! $is64 && ! $is32) {
            return ['ok' => false, 'error' => 'not_macho'];
        }

        $u32 = fn (int $o): ?int => $be ? $reader->u32be($o) : $reader->u32le($o);
        $u64 = function (int $o) use ($reader, $be): ?int {
            $raw = $reader->slice($o, 8);
            if ($raw === null) {
                return null;
            }
            $v = unpack($be ? 'J' : 'P', $raw);

            return $v[1];
        };

        $cputype = $u32($base + 4);
        $cpusub = $u32($base + 8);
        $filetype = $u32($base + 12);
        $ncmds = $u32($base + 16);
        if ($cputype === null || $cpusub === null || $filetype === null || $ncmds === null) {
            return ['ok' => false, 'error' => 'truncated_header'];
        }
        if ($ncmds > 1024) {
            return ['ok' => false, 'error' => 'too_many_commands'];
        }

        $warnings = [];
        $segments = [];
        $dylibs = [];
        $entry = null;
        $keptCmds = min($ncmds, $limits['max_commands']);
        $pos = $base + ($is64 ? 32 : 28);
        for ($i = 0; $i < $keptCmds; $i++) {
            $cmd = $u32($pos);
            $cmdsize = $u32($pos + 4);
            if ($cmd === null || $cmdsize === null || $cmdsize < 8 || $pos + $cmdsize > $reader->length()) {
                return ['ok' => false, 'error' => 'truncated_commands'];
            }

            if ($cmd === 0x19 || $cmd === 0x1) { // LC_SEGMENT_64 / LC_SEGMENT
                $seg = self::segment($reader, $pos, $cmd === 0x19, $be, $limits);
                if ($seg === null) {
                    return ['ok' => false, 'error' => 'truncated_segment'];
                }
                $segments[] = $seg;
            } elseif ($cmd === 0xC || $cmd === 0xD || $cmd === 0x80000018) { // LC_LOAD_DYLIB etc.
                $nameOff = $u32($pos + 8);
                if ($nameOff !== null && count($dylibs) < $limits['max_dl']) {
                    $name = $reader->cstring($pos + $nameOff, 256);
                    if ($name !== null && $name !== '') {
                        $dylibs[] = $name;
                    }
                }
            } elseif ($cmd === 0x80000028) { // LC_MAIN
                $entry = $u64($pos + 8);
            }

            $pos += $cmdsize;
        }
        if ($ncmds > $keptCmds) {
            $warnings[] = 'commands_capped';
        }
        if (! isset(self::CPUTYPES[$cputype])) {
            $warnings[] = 'unsupported_cputype:'.$cputype;
        }

        $strings = StringsExtractor::extract($reader->bytes, (int) $limits['max_strings']);
        if ($strings['truncated']) {
            $warnings[] = 'strings_capped';
        }

        return [
            'ok' => true,
            'arch' => self::ARCHES[$cputype] ?? null,
            'data' => [
                'kind' => 'thin',
                'fat_member' => $member,
                'bits' => $is64 ? 64 : 32,
                'big_endian' => $be,
                'cputype' => $cputype,
                'cpusubtype' => $cpusub,
                'cpu_name' => self::CPUTYPES[$cputype] ?? 'UNKNOWN',
                'filetype' => $filetype,
                'entry_offset' => $entry,
                'segments' => $segments,
                'dylibs' => $dylibs,
                'strings' => $member === null ? $strings['strings'] : [],
                'string_stats' => ['kept' => $member === null ? count($strings['strings']) : 0, 'truncated' => $strings['truncated']],
                'warnings' => $warnings,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private static function segment(ByteReader $reader, int $pos, bool $is64, bool $be, array $limits): ?array
    {
        $u32 = fn (int $o): ?int => $be ? $reader->u32be($o) : $reader->u32le($o);
        $name = rtrim((string) $reader->slice($pos + 8, 16), "\0");
        if ($is64) {
            $vmaddr = $reader->slice($pos + 24, 8);
            $vmsize = $reader->slice($pos + 32, 8);
            $fileoff = $reader->slice($pos + 40, 8);
            $filesize = $reader->slice($pos + 48, 8);
            $nsects = $u32($pos + 64);
            $sectBase = $pos + 72;
            $sectSize = 80;
            $unpack = $be ? 'J' : 'P';
            $vmaddr = $vmaddr !== null ? unpack($unpack, $vmaddr)[1] : null;
            $vmsize = $vmsize !== null ? unpack($unpack, $vmsize)[1] : null;
            $fileoff = $fileoff !== null ? unpack($unpack, $fileoff)[1] : null;
            $filesize = $filesize !== null ? unpack($unpack, $filesize)[1] : null;
        } else {
            $vmaddr = $u32($pos + 24);
            $vmsize = $u32($pos + 28);
            $fileoff = $u32($pos + 32);
            $filesize = $u32($pos + 36);
            $nsects = $u32($pos + 48);
            $sectBase = $pos + 56;
            $sectSize = 68;
        }
        if ($vmaddr === null || $vmsize === null || $fileoff === null || $filesize === null || $nsects === null || $nsects > 512) {
            return null;
        }

        $sections = [];
        $kept = min($nsects, $limits['max_sections']);
        for ($i = 0; $i < $kept; $i++) {
            $so = $sectBase + $i * $sectSize;
            if ($so + $sectSize > $reader->length()) {
                return null;
            }
            $sname = rtrim((string) $reader->slice($so, 16), "\0");
            $sections[] = [
                'name' => $sname === '' ? '.sect'.$i : $sname,
                'address' => $is64
                    ? unpack($be ? 'J' : 'P', (string) $reader->slice($so + 32, 8))[1]
                    : $u32($so + 32),
                'size' => $is64
                    ? unpack($be ? 'J' : 'P', (string) $reader->slice($so + 40, 8))[1]
                    : $u32($so + 36),
                'offset' => $u32($so + ($is64 ? 48 : 40)),
            ];
        }

        return [
            'name' => $name === '' ? '__SEG' : $name,
            'vmaddr' => $vmaddr,
            'vmsize' => $vmsize,
            'fileoff' => $fileoff,
            'filesize' => $filesize,
            'sections' => $sections,
        ];
    }
}
