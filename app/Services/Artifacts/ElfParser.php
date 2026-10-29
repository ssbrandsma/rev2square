<?php

namespace App\Services\Artifacts;

/**
 * Deterministic pure-PHP ELF inspector (no shell, no network, no deps).
 *
 * Parses headers, segments, sections, symbols, dynamic entries, bounded
 * strings and endpoint candidates from an in-memory byte string. Every
 * read is bounds-checked; malformed or truncated input yields explicit
 * warnings instead of guesses. This is inspection, not decompilation:
 * no function names, call graphs or source lines are fabricated.
 *
 * Addresses are always labeled: file offsets vs virtual addresses are
 * never interchanged, and vaddr→offset mapping is derived only from
 * PT_LOAD segments when present.
 */
final class ElfParser
{
    public const NAME = 'elf-php';

    public const VERSION = '1.0.0';

    private const MACHINES = [
        3 => ['name' => 'EM_386', 'arch' => 'x86'],
        20 => ['name' => 'EM_PPC', 'arch' => null],
        28 => ['name' => 'EM_ARM', 'arch' => null], // historical id, see 40
        40 => ['name' => 'EM_ARM', 'arch' => 'arm32'],
        62 => ['name' => 'EM_X86_64', 'arch' => 'x86-64'],
        183 => ['name' => 'EM_AARCH64', 'arch' => 'aarch64'],
        243 => ['name' => 'EM_RISCV', 'arch' => null],
    ];

    private const TYPES = [
        0 => 'NONE', 1 => 'REL', 2 => 'EXEC', 3 => 'DYN', 4 => 'CORE',
    ];

    private const SEGMENT_TYPES = [
        0 => 'NULL', 1 => 'LOAD', 2 => 'DYNAMIC', 3 => 'INTERP', 4 => 'NOTE',
        5 => 'SHLIB', 6 => 'PHDR', 7 => 'TLS', 0x6474E550 => 'GNU_EH_FRAME',
        0x6474E551 => 'GNU_STACK', 0x6474E552 => 'GNU_RELRO',
    ];

    private const SECTION_TYPES = [
        0 => 'NULL', 1 => 'PROGBITS', 2 => 'SYMTAB', 3 => 'STRTAB', 4 => 'RELA',
        5 => 'HASH', 6 => 'DYNAMIC', 7 => 'NOTE', 8 => 'NOBITS', 9 => 'REL',
        10 => 'SHLIB', 11 => 'DYNSYM', 14 => 'INIT_ARRAY', 15 => 'FINI_ARRAY',
    ];

    private const DYNAMIC_TAGS = [
        1 => 'NEEDED', 2 => 'PLTRELSZ', 3 => 'PLTGOT', 4 => 'HASH', 5 => 'STRTAB',
        6 => 'SYMTAB', 10 => 'STRSZ', 11 => 'SYMENT', 12 => 'INIT', 13 => 'FINI',
        14 => 'SONAME', 15 => 'RPATH', 16 => 'SYMBOLIC', 17 => 'REL', 18 => 'RELSZ',
        19 => 'RELENT', 20 => 'PLTREL', 21 => 'DEBUG', 22 => 'TEXTREL', 23 => 'JMPREL',
        24 => 'BIND_NOW', 25 => 'INIT_ARRAY', 26 => 'FINI_ARRAY', 28 => 'RUNPATH',
        29 => 'FLAGS', 30 => 'PREINIT_ARRAY', 32 => 'RELACOUNT',
    ];

    /**
     * @param  array{max_sections?: int, max_symbols?: int, max_strings?: int, max_string_bytes?: int, max_endpoints?: int}  $limits
     * @return array{ok: bool, format: string, arch: ?string, error?: string, data?: array<string, mixed>}
     */
    public static function parse(string $bytes, array $limits = []): array
    {
        $limits = array_merge([
            'max_sections' => 256,
            'max_symbols' => 2000,
            'max_strings' => 2000,
            'max_string_bytes' => 256,
            'max_endpoints' => 200,
        ], $limits);

        $size = strlen($bytes);
        if ($size < 52 || substr($bytes, 0, 4) !== "\x7fELF") {
            return ['ok' => false, 'format' => 'unknown', 'arch' => null, 'error' => 'not_elf'];
        }

        $class = ord($bytes[4]);
        $data = ord($bytes[5]);
        if (! in_array($class, [1, 2], true)) {
            return ['ok' => false, 'format' => 'elf', 'arch' => null, 'error' => 'unsupported_class'];
        }
        if (! in_array($data, [1, 2], true)) {
            return ['ok' => false, 'format' => 'elf', 'arch' => null, 'error' => 'unsupported_endian'];
        }

        $r = new self($bytes, $class === 2, $data === 2, $limits);

        return $r->run($class, $data);
    }

    /** @var list<string> */
    private array $warnings = [];

    private function __construct(
        private readonly string $bytes,
        private readonly bool $is64,
        private readonly bool $bigEndian,
        private readonly array $limits,
    ) {}

    /** @return array{ok: bool, format: string, arch: ?string, error?: string, data?: array<string, mixed>} */
    private function run(int $class, int $data): array
    {
        $size = strlen($this->bytes);
        $need = $this->is64 ? 64 : 52;
        if ($size < $need) {
            return $this->fail('truncated_header', null, 'File ends inside the ELF header.');
        }

        $u16 = $this->bigEndian ? 'n' : 'v';
        $u32 = $this->bigEndian ? 'N' : 'V';

        if ($this->is64) {
            $eh = unpack("{$u16}type/{$u16}machine/{$u32}version/x8/x8/x8/{$u32}flags/{$u16}ehsize/{$u16}phentsize/{$u16}phnum/{$u16}shentsize/{$u16}shnum/{$u16}shstrndx", $this->bytes, 16);
            $entry = $this->u64(24);
            $phoff = $this->u64(32);
            $shoff = $this->u64(40);
        } else {
            $eh = unpack("{$u16}type/{$u16}machine/{$u32}version/{$u32}entry/{$u32}phoff/{$u32}shoff/{$u32}flags/{$u16}ehsize/{$u16}phentsize/{$u16}phnum/{$u16}shentsize/{$u16}shnum/{$u16}shstrndx", $this->bytes, 16);
            $entry = $eh['entry'];
            $phoff = $eh['phoff'];
            $shoff = $eh['shoff'];
        }

        if ($eh === false) {
            return $this->fail('malformed_header', null, 'ELF header could not be decoded.');
        }

        $machine = self::MACHINES[$eh['machine']] ?? null;
        $arch = $machine['arch'] ?? null;
        if ($machine === null) {
            $this->warnings[] = 'unsupported_machine:'.$eh['machine'];
        } elseif ($arch === null) {
            $this->warnings[] = 'unsupported_arch:'.$machine['name'];
        }

        $segments = $this->segments($phoff, $eh['phentsize'], $eh['phnum']);
        $sections = $this->sections($shoff, $eh['shentsize'], $eh['shnum'], $eh['shstrndx']);
        $symbols = $this->symbols($sections);
        $dynamic = $this->dynamic($segments, $sections);
        $strings = $this->strings();
        $endpoints = EndpointFinder::find($strings, $this->limits['max_endpoints']);

        $hasInterp = false;
        $hasDynamic = $dynamic['present'];
        foreach ($segments as $seg) {
            if ($seg['type'] === 'INTERP') {
                $hasInterp = true;
            }
        }
        if (! $hasInterp && ! $hasDynamic) {
            $this->warnings[] = 'no_dynamic_segment';
        }
        if ($symbols['symtab_count'] === 0 && $symbols['dynsym_count'] === 0) {
            $this->warnings[] = 'no_symbols';
        }

        return [
            'ok' => true,
            'format' => 'elf',
            'arch' => $arch,
            'data' => [
                'class' => $class === 2 ? 'ELF64' : 'ELF32',
                'endian' => $this->bigEndian ? 'big' : 'little',
                'machine' => ['id' => $eh['machine'], 'name' => $machine['name'] ?? 'UNKNOWN'],
                'object_type' => self::TYPES[$eh['type']] ?? 'UNKNOWN('.$eh['type'].')',
                'entry_point' => $this->hex($entry),
                'flags' => $eh['flags'],
                'segments' => $segments,
                'sections' => $sections,
                'symbols' => $symbols['items'],
                'symbol_stats' => ['symtab' => $symbols['symtab_count'], 'dynsym' => $symbols['dynsym_count'], 'truncated' => $symbols['truncated']],
                'dynamic' => $dynamic,
                'strings' => $strings,
                'string_stats' => ['kept' => count($strings), 'truncated' => $this->stringsTruncated],
                'endpoints' => $endpoints,
                'warnings' => $this->warnings,
            ],
        ];
    }

    private bool $stringsTruncated = false;

    /** @return list<array<string, mixed>> */
    private function segments(int $phoff, int $entsize, int $count): array
    {
        $out = [];
        $min = $this->is64 ? 56 : 32;
        if ($count === 0 || $phoff === 0) {
            return $out;
        }
        if ($entsize < $min) {
            $this->warnings[] = 'bad_phentsize';

            return $out;
        }
        $count = min($count, 64);
        $u32 = $this->bigEndian ? 'N' : 'V';
        for ($i = 0; $i < $count; $i++) {
            $off = $phoff + $i * $entsize;
            if ($off + $min > strlen($this->bytes)) {
                $this->warnings[] = 'truncated_segments';

                break;
            }
            if ($this->is64) {
                $p = unpack("{$u32}type/{$u32}flags", $this->bytes, $off);
                $fileoff = $this->u64($off + 8);
                $vaddr = $this->u64($off + 16);
                $filesz = $this->u64($off + 32);
                $memsz = $this->u64($off + 40);
            } else {
                $p = unpack("{$u32}type/{$u32}offset/{$u32}vaddr/{$u32}paddr/{$u32}filesz/{$u32}memsz/{$u32}flags", $this->bytes, $off);
                $fileoff = $p['offset'];
                $vaddr = $p['vaddr'];
                $filesz = $p['filesz'];
                $memsz = $p['memsz'];
            }
            $out[] = [
                'index' => $i,
                'type' => self::SEGMENT_TYPES[$p['type']] ?? 'UNKNOWN('.$p['type'].')',
                'flags' => ['read' => (bool) ($p['flags'] & 4), 'write' => (bool) ($p['flags'] & 2), 'execute' => (bool) ($p['flags'] & 1)],
                'file_offset' => $fileoff,
                'virtual_address' => $this->hex($vaddr),
                'file_size' => $filesz,
                'memory_size' => $memsz,
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function sections(int $shoff, int $entsize, int $count, int $strndx): array
    {
        $out = [];
        $min = $this->is64 ? 64 : 40;
        if ($count === 0 || $shoff === 0) {
            $this->warnings[] = 'no_section_headers';

            return $out;
        }
        if ($entsize < $min) {
            $this->warnings[] = 'bad_shentsize';

            return $out;
        }
        $size = strlen($this->bytes);
        $count = min($count, $this->limits['max_sections']);
        $raw = [];
        $u32 = $this->bigEndian ? 'N' : 'V';
        for ($i = 0; $i < $count; $i++) {
            $off = $shoff + $i * $entsize;
            if ($off + $min > $size) {
                $this->warnings[] = 'truncated_sections';

                break;
            }
            if ($this->is64) {
                $s = unpack("{$u32}name/{$u32}type/x8/x8/x8/x8/{$u32}link/{$u32}info/x8/x8", $this->bytes, $off);
                $s['flags'] = $this->u64($off + 8);
                $s['addr'] = $this->u64($off + 16);
                $s['offset'] = $this->u64($off + 24);
                $s['size'] = $this->u64($off + 32);
            } else {
                $s = unpack("{$u32}name/{$u32}type/{$u32}flags/{$u32}addr/{$u32}offset/{$u32}size/{$u32}link/{$u32}info/x4/x4", $this->bytes, $off);
            }
            $raw[$i] = $s;
        }

        $strtab = '';
        if (isset($raw[$strndx]) && $raw[$strndx]['type'] === 3) {
            $strtab = $this->slice($raw[$strndx]['offset'], $raw[$strndx]['size']) ?? '';
        } elseif ($strndx !== 0) {
            $this->warnings[] = 'bad_shstrndx';
        }

        foreach ($raw as $i => $s) {
            $valid = $s['type'] === 8 || ($size >= $s['offset'] + $s['size'] && $s['offset'] <= $s['offset'] + $s['size']);
            if (! $valid) {
                $this->warnings[] = 'section_out_of_bounds:'.$i;
            }
            $out[] = [
                'index' => $i,
                'name' => $this->cstr($strtab, $s['name']),
                'type' => self::SECTION_TYPES[$s['type']] ?? 'UNKNOWN('.$s['type'].')',
                'flags' => $s['flags'],
                'virtual_address' => $this->hex($s['addr']),
                'file_offset' => $s['offset'],
                'size' => $s['size'],
                'link' => $s['link'],
                'info' => $s['info'],
                'in_bounds' => $valid,
            ];
        }

        return $out;
    }

    /** @return array{items: list<array<string, mixed>>, symtab_count: int, dynsym_count: int, truncated: bool} */
    private function symbols(array $sections): array
    {
        $items = [];
        $truncated = false;
        $counts = ['symtab' => 0, 'dynsym' => 0];
        $byIndex = [];
        foreach ($sections as $s) {
            $byIndex[$s['index']] = $s;
        }
        foreach ($sections as $s) {
            if (! in_array($s['type'], ['SYMTAB', 'DYNSYM'], true) || ! $s['in_bounds']) {
                continue;
            }
            $entsize = $this->is64 ? 24 : 16;
            $n = $entsize > 0 ? intdiv($s['size'], $entsize) : 0;
            $strtab = '';
            if (isset($byIndex[$s['link']]) && $byIndex[$s['link']]['in_bounds']) {
                $strtab = $this->slice($byIndex[$s['link']]['file_offset'], $byIndex[$s['link']]['size']) ?? '';
            }
            $u32 = $this->bigEndian ? 'N' : 'V';
            $u16 = $this->bigEndian ? 'n' : 'v';
            for ($i = 0; $i < $n; $i++) {
                if (count($items) >= $this->limits['max_symbols']) {
                    $truncated = true;

                    break 2;
                }
                $off = $s['file_offset'] + $i * $entsize;
                if ($off + $entsize > strlen($this->bytes)) {
                    $this->warnings[] = 'truncated_symbols';

                    break 2;
                }
                if ($this->is64) {
                    $e = unpack("{$u32}name/Cinfo/Cother/{$u16}shndx", $this->bytes, $off);
                    $value = $this->u64($off + 8);
                    $symsize = $this->u64($off + 16);
                } else {
                    $e = unpack("{$u32}name/{$u32}value/{$u32}size/Cinfo/Cother/{$u16}shndx", $this->bytes, $off);
                    $value = $e['value'];
                    $symsize = $e['size'];
                }
                $key = $s['type'] === 'SYMTAB' ? 'symtab' : 'dynsym';
                $counts[$key]++;
                $name = $this->cstr($strtab, $e['name']);
                if ($name === '' && $value === 0) {
                    continue;
                }
                $items[] = [
                    'name' => $name,
                    'value' => $this->hex($value),
                    'size' => $symsize,
                    'bind' => $e['info'] >> 4,
                    'type' => $e['info'] & 0x0F,
                    'undefined' => $e['shndx'] === 0,
                    'table' => $s['type'],
                    'section' => $s['name'],
                ];
            }
        }

        return ['items' => $items, 'symtab_count' => $counts['symtab'], 'dynsym_count' => $counts['dynsym'], 'truncated' => $truncated];
    }

    /** @return array<string, mixed> */
    private function dynamic(array $segments, array $sections): array
    {
        $entries = [];
        foreach ($sections as $s) {
            if ($s['type'] === 'DYNAMIC' && $s['in_bounds']) {
                $entries = $this->dynamicEntries($s['file_offset'], $s['size']);

                break;
            }
        }
        if ($entries === []) {
            foreach ($segments as $seg) {
                if ($seg['type'] === 'DYNAMIC') {
                    $entries = $this->dynamicEntries($seg['file_offset'], min($seg['file_size'], 65536));

                    break;
                }
            }
        }
        if ($entries === []) {
            return ['present' => false, 'needed' => [], 'soname' => null, 'unresolved_imports' => []];
        }

        // Resolve string-valued tags via the DYNAMIC section's linked
        // string table first (that is what the offsets index); never guess
        // vaddr→offset without LOAD mappings.
        $linked = null;
        foreach ($sections as $s) {
            if ($s['type'] === 'DYNAMIC' && $s['in_bounds'] && isset($sections[$s['link']])) {
                $linked = $sections[$s['link']];
            }
        }
        $strtabs = [];
        if ($linked !== null && $linked['type'] === 'STRTAB' && $linked['in_bounds']) {
            $strtabs[] = $this->slice($linked['file_offset'], $linked['size']) ?? '';
        }
        foreach ($sections as $s) {
            if ($s['type'] === 'STRTAB' && $s['in_bounds']) {
                $strtabs[] = $this->slice($s['file_offset'], $s['size']) ?? '';
            }
        }

        $needed = [];
        $soname = null;
        foreach ($entries as $e) {
            if ($e['tag'] === 1) {
                $needed[] = $this->dynString($strtabs, $e['value']);
            } elseif ($e['tag'] === 14) {
                $soname = $this->dynString($strtabs, $e['value']);
            }
        }

        return [
            'present' => true,
            'needed' => array_values(array_filter($needed)),
            'soname' => $soname,
            'entry_count' => count($entries),
        ];
    }

    /** @return list<array{tag: int, value: int}> */
    private function dynamicEntries(int $offset, int $size): array
    {
        $out = [];
        $entsize = $this->is64 ? 16 : 8;
        $n = min(intdiv(max(0, $size), $entsize), 256);
        for ($i = 0; $i < $n; $i++) {
            $off = $offset + $i * $entsize;
            if ($off + $entsize > strlen($this->bytes)) {
                $this->warnings[] = 'truncated_dynamic';

                break;
            }
            if ($this->is64) {
                $tag = $this->u64($off);
                $val = $this->u64($off + 8);
            } else {
                $u32 = $this->bigEndian ? 'N' : 'V';
                $d = unpack("{$u32}tag/{$u32}val", $this->bytes, $off);
                $tag = $d['tag'];
                $val = $d['val'];
            }
            if ($tag === 0) {
                break;
            }
            $out[] = ['tag' => $tag, 'value' => $val];
        }

        return $out;
    }

    /** @param list<string> $strtabs */
    private function dynString(array $strtabs, int $offset): ?string
    {
        foreach ($strtabs as $tab) {
            $s = $this->cstr($tab, $offset);
            if ($s !== '') {
                return $s;
            }
        }

        return null;
    }

    /** @return list<array{offset: int, length: int, value: string, encoding: string}> */
    private function strings(): array
    {
        $out = [];
        $min = 4;
        $len = 0;
        $start = 0;
        $size = strlen($this->bytes);
        $maxBytes = $this->limits['max_string_bytes'];
        for ($i = 0; $i <= $size; $i++) {
            $c = $i < $size ? ord($this->bytes[$i]) : 0;
            $printable = ($c >= 32 && $c <= 126) || $c === 9;
            if ($printable) {
                if ($len === 0) {
                    $start = $i;
                }
                $len++;
                if ($len > $maxBytes + 64) {
                    // Long run: close it to stay bounded.
                    $out[] = $this->stringEntry($start, $len);
                    if (count($out) >= $this->limits['max_strings']) {
                        $this->stringsTruncated = true;

                        return $out;
                    }
                    $len = 0;
                }

                continue;
            }
            if ($len >= $min) {
                $out[] = $this->stringEntry($start, $len);
                if (count($out) >= $this->limits['max_strings']) {
                    $this->stringsTruncated = true;

                    return $out;
                }
            }
            $len = 0;
        }

        return $out;
    }

    /** @return array{offset: int, length: int, value: string, encoding: string} */
    private function stringEntry(int $start, int $len): array
    {
        $maxBytes = $this->limits['max_string_bytes'];
        $value = substr($this->bytes, $start, min($len, $maxBytes));

        return [
            'offset' => $start,
            'length' => $len,
            'value' => $len > $maxBytes ? $value.'…' : $value,
            'encoding' => 'ascii-printable',
        ];
    }

    private function u64(int $off): int
    {
        if ($off + 8 > strlen($this->bytes)) {
            return 0;
        }
        if ($this->bigEndian) {
            $w = unpack('N2', $this->bytes, $off);

            return $w[1] * 4294967296 + $w[2];
        }
        $w = unpack('V2', $this->bytes, $off);

        return $w[2] * 4294967296 + $w[1];
    }

    private function slice(int $off, int $size): ?string
    {
        if ($off < 0 || $size < 0 || $off + $size > strlen($this->bytes) || $off + $size < $off) {
            return null;
        }

        return substr($this->bytes, $off, $size);
    }

    private function cstr(string $tab, int $off): string
    {
        if ($off < 0 || $off >= strlen($tab)) {
            return '';
        }
        $end = strpos($tab, "\0", $off);

        return $end === false ? '' : substr($tab, $off, min($end - $off, 256));
    }

    private function hex(int $v): string
    {
        return '0x'.dechex($v);
    }

    /** @return array{ok: bool, format: string, arch: ?string, error?: string, data?: array<string, mixed>} */
    private function fail(string $code, ?string $arch, string $detail): array
    {
        $this->warnings[] = $detail;

        return ['ok' => false, 'format' => 'elf', 'arch' => $arch, 'error' => $code, 'data' => ['warnings' => $this->warnings]];
    }
}
