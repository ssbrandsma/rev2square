<?php

namespace App\Services\Binary\Adapters;

use App\Models\BinaryAnalysis;
use App\Models\ProjectArtifact;
use App\Services\Binary\Disasm\I8051Decoder;
use App\Services\Binary\Disasm\InstructionDecoder;
use App\Services\Binary\Disasm\LinearSweep;
use App\Services\Binary\Disasm\Mos6502Decoder;
use App\Services\Binary\Disasm\Z80Decoder;
use App\Services\Binary\HexRecords;
use App\Services\Binary\Targets;

/**
 * Bundled pure-PHP adapter: in-process disassembly for 6502, Z80 and
 * 8051 targets, function seeding from entries/symbols/call targets,
 * one-level call graphs, and candidate vector detection.
 *
 * No subprocesses, no network, no model calls. Anything outside the
 * three native targets is refused (not approximated).
 */
final class PhpNativeAdapter implements BinaryAdapter
{
    public const VERSION = '1.0.0';

    public function id(): string
    {
        return 'php-native';
    }

    public function name(): string
    {
        return 'Bundled PHP analyzer';
    }

    public function version(): ?string
    {
        return self::VERSION;
    }

    public function probe(): array
    {
        return ['available' => true, 'reason' => 'Bundled with the application; always available.', 'setup_doc' => null];
    }

    public function formats(): array
    {
        return [
            ProjectArtifact::FORMAT_ELF,
            ProjectArtifact::FORMAT_PE,
            ProjectArtifact::FORMAT_PRG,
            ProjectArtifact::FORMAT_RAW,
            ProjectArtifact::FORMAT_IHEX,
            ProjectArtifact::FORMAT_SREC,
        ];
    }

    public function targets(): array
    {
        return Targets::nativeIds();
    }

    public function candidateTargets(): array
    {
        return [];
    }

    public function capabilities(string $format, ?string $target): array
    {
        $native = $target !== null && in_array($target, Targets::nativeIds(), true);
        $openable = in_array($format, $this->formats(), true);

        if (! $openable) {
            return array_fill_keys(Capability::all(), Capability::UNSUPPORTED);
        }

        $needsConfig = $target === null && in_array($format, [
            ProjectArtifact::FORMAT_RAW, ProjectArtifact::FORMAT_IHEX, ProjectArtifact::FORMAT_SREC,
        ], true);

        if ($needsConfig) {
            $caps = array_fill_keys(Capability::all(), Capability::CONFIGURATION_REQUIRED);
            $caps[Capability::DETECTION] = Capability::SUPPORTED;
            $caps[Capability::METADATA] = $format === ProjectArtifact::FORMAT_RAW ? Capability::UNSUPPORTED : Capability::SUPPORTED;
            $caps[Capability::AI_EXPLAIN] = Capability::SUPPORTED;

            return $caps;
        }

        if (! $native) {
            $caps = array_fill_keys(Capability::all(), Capability::DEPENDENCY_UNAVAILABLE);
            $caps[Capability::DETECTION] = Capability::SUPPORTED;
            $caps[Capability::METADATA] = Capability::SUPPORTED;
            $caps[Capability::STRINGS] = Capability::SUPPORTED;
            $caps[Capability::SEGMENTS] = Capability::SUPPORTED;
            $caps[Capability::AI_EXPLAIN] = Capability::SUPPORTED;
            $caps[Capability::COMPARISON] = Capability::PARTIAL;

            return $caps;
        }

        return [
            Capability::DETECTION => Capability::SUPPORTED,
            Capability::METADATA => Capability::SUPPORTED,
            Capability::STRINGS => Capability::SUPPORTED,
            Capability::SEGMENTS => Capability::SUPPORTED,
            Capability::DISASSEMBLY => Capability::SUPPORTED,
            Capability::FUNCTIONS => Capability::PARTIAL,
            Capability::XREFS => Capability::PARTIAL,
            Capability::CALLGRAPH => Capability::PARTIAL,
            Capability::DECOMPILATION => Capability::UNSUPPORTED,
            Capability::AI_EXPLAIN => Capability::SUPPORTED,
            Capability::COMPARISON => Capability::PARTIAL,
        ];
    }

    public function analyze(BinaryAnalysis $analysis, string $bytes, array $snapshot, array $config, callable $heartbeat): array
    {
        $started = microtime(true);
        $target = (string) ($analysis->target ?? '');
        $decoder = $this->decoderFor($target);
        if ($decoder === null) {
            throw new AnalysisFailedException('target_unsupported', 'The bundled analyzer cannot disassemble the “'.$target.'” target. Install the documented backend or pick a supported target.');
        }

        $format = $analysis->artifact->format;
        $segments = $this->segments($format, $bytes, $snapshot, $config);
        if ($segments === []) {
            throw new AnalysisFailedException('no_executable_segments', 'No executable byte ranges could be mapped for this format and target.');
        }
        if (! $heartbeat()) {
            throw new AnalysisFailedException('cancelled_race', 'The run was cancelled before disassembly started.');
        }

        $maxInstructions = (int) config('artifacts.binary_max_instructions');
        $maxXrefs = (int) config('artifacts.binary_max_xrefs');
        $windows = [];
        $allXrefs = [];
        $totalInstructions = 0;
        $totalData = 0;
        $sweptTruncated = false;

        foreach ($segments as $segment) {
            $budget = max(0, $maxInstructions - $totalInstructions);
            $sweep = LinearSweep::sweep(
                $decoder, $segment['bytes'], $segment['base'], 0, $budget,
                (int) config('artifacts.binary_max_sweep_bytes'),
            );
            $windows[] = [
                'segment' => $segment['name'],
                'kind' => $segment['kind'],
                'base_address' => $segment['base'],
                'file_offset' => $segment['file_offset'],
                'lines' => $sweep['lines'],
                'truncated' => $sweep['truncated'],
            ];
            $totalInstructions += $sweep['instructions'];
            $totalData += $sweep['data_bytes'];
            $sweptTruncated = $sweptTruncated || $sweep['truncated'];
            foreach ($sweep['xrefs'] as $xref) {
                if (count($allXrefs) < $maxXrefs) {
                    $allXrefs[] = $xref;
                }
            }
            if (! $heartbeat()) {
                throw new AnalysisFailedException('cancelled_race', 'The run was cancelled during disassembly.');
            }
            if ($totalInstructions >= $maxInstructions) {
                $sweptTruncated = true;
                break;
            }
        }

        $functions = $this->functions($format, $bytes, $snapshot, $config, $segments, $allXrefs);
        $calls = $this->callGraph($functions, $allXrefs);
        $vectors = $this->vectors($target, $segments);
        $ioNotes = $this->ioNotes($target, $config, $windows);

        $warnings = [];
        if ($sweptTruncated) {
            $warnings[] = 'disassembly_capped';
        }
        if (count($allXrefs) >= $maxXrefs) {
            $warnings[] = 'xrefs_capped';
        }

        return [
            'schema' => 'binary-analysis/1',
            'adapter' => $this->id(),
            'adapter_version' => self::VERSION,
            'artifact' => [
                'id' => $analysis->artifact->id,
                'sha256' => $analysis->artifact->sha256,
                'format' => $format,
            ],
            'target' => $target,
            'config' => $config,
            'analyzed_at' => now()->toIso8601String(),
            'elapsed_ms' => (int) ((microtime(true) - $started) * 1000),
            'method_note' => 'Linear sweep per executable segment; function sizes are tentative; vector and I/O notes are candidates, not confirmed hardware facts.',
            'segments' => array_map(fn ($s) => [
                'name' => $s['name'], 'kind' => $s['kind'], 'file_offset' => $s['file_offset'],
                'file_size' => strlen($s['bytes']), 'base_address' => $s['base'], 'length' => strlen($s['bytes']),
            ], $segments),
            'functions' => $functions,
            'xrefs' => $allXrefs,
            'calls' => $calls,
            'disassembly' => [
                'windows' => $windows,
                'total_instructions' => $totalInstructions,
                'total_data_bytes' => $totalData,
                'truncated' => $sweptTruncated,
            ],
            'vectors' => $vectors,
            'io_notes' => $ioNotes,
            'warnings' => $warnings,
            'limits' => [
                'max_instructions' => $maxInstructions,
                'max_functions' => (int) config('artifacts.binary_max_functions'),
                'max_xrefs' => $maxXrefs,
            ],
        ];
    }

    public function decoderFor(string $target): ?InstructionDecoder
    {
        return match ($target) {
            'mos6502' => new Mos6502Decoder,
            'z80' => new Z80Decoder,
            'i8051' => new I8051Decoder,
            default => null,
        };
    }

    /**
     * Map executable byte ranges. Every segment records BOTH the file
     * offset and the virtual base so viewers never confuse the two.
     *
     * @return list<array{name: string, kind: string, bytes: string, base: int, file_offset: ?int}>
     */
    private function segments(string $format, string $bytes, array $snapshot, array $config): array
    {
        $data = $snapshot['data'] ?? [];
        $maxBytes = (int) config('artifacts.binary_max_sweep_bytes');

        if ($format === ProjectArtifact::FORMAT_RAW) {
            $base = (int) ($config['base_address'] ?? 0);

            return [[
                'name' => 'raw-image', 'kind' => 'flat', 'bytes' => substr($bytes, 0, $maxBytes),
                'base' => $base, 'file_offset' => 0,
            ]];
        }

        if ($format === ProjectArtifact::FORMAT_PRG) {
            $load = (int) ($data['load_address'] ?? 0);
            $payloadOffset = (int) ($data['payload_offset'] ?? 2);
            $slice = substr($bytes, $payloadOffset, $maxBytes);
            if ($slice === '' || $slice === false) {
                return [];
            }

            return [[
                'name' => 'prg-payload', 'kind' => 'flat',
                'bytes' => $slice, 'base' => $load + ($payloadOffset - 2), 'file_offset' => $payloadOffset,
            ]];
        }

        if ($format === ProjectArtifact::FORMAT_IHEX || $format === ProjectArtifact::FORMAT_SREC) {
            $materialized = HexRecords::materialize($format, $bytes, $maxBytes);
            $out = [];
            foreach (array_slice($materialized['segments'], 0, 64) as $i => $segment) {
                $out[] = [
                    'name' => 'record-segment-'.$i, 'kind' => 'records',
                    'bytes' => $segment['bytes'], 'base' => $segment['base'], 'file_offset' => null,
                ];
            }

            return $out;
        }

        if ($format === ProjectArtifact::FORMAT_ELF) {
            $out = [];
            foreach (array_slice($data['sections'] ?? [], 0, 64) as $section) {
                if (! ($section['in_bounds'] ?? false) || ! ((int) ($section['flags'] ?? 0) & 0x4)) {
                    continue;
                }
                $offset = (int) ($section['file_offset'] ?? 0);
                $size = min((int) ($section['size'] ?? 0), $maxBytes);
                if ($size <= 0 || $offset + $size > strlen($bytes)) {
                    continue;
                }
                $out[] = [
                    'name' => (string) ($section['name'] ?: 'section'),
                    'kind' => 'elf-section',
                    'bytes' => substr($bytes, $offset, $size),
                    'base' => (int) hexdec((string) ($section['virtual_address'] ?? '0')),
                    'file_offset' => $offset,
                ];
            }

            return $out;
        }

        if ($format === ProjectArtifact::FORMAT_PE) {
            $imageBase = (int) ($data['image_base'] ?? 0);
            $out = [];
            foreach (array_slice($data['sections'] ?? [], 0, 64) as $section) {
                if (! ($section['executable'] ?? false)) {
                    continue;
                }
                $offset = (int) ($section['raw_offset'] ?? 0);
                $size = min((int) ($section['raw_size'] ?? 0), $maxBytes);
                if ($size <= 0 || $offset + $size > strlen($bytes)) {
                    continue;
                }
                $out[] = [
                    'name' => (string) ($section['name'] ?: 'section'),
                    'kind' => 'pe-section',
                    'bytes' => substr($bytes, $offset, $size),
                    'base' => $imageBase + (int) ($section['virtual_address'] ?? 0),
                    'file_offset' => $offset,
                ];
            }

            return $out;
        }

        return [];
    }

    /**
     * Seed functions from entries, symbols, and SYS targets; then add
     * call-target discoveries inside swept ranges. Sizes are
     * tentative (next seed or window end).
     *
     * @return list<array{id: string, address: int, file_offset: ?int, name: ?string, source: string, size: ?int, size_tentative: bool}>
     */
    private function functions(string $format, string $bytes, array $snapshot, array $config, array $segments, array $xrefs): array
    {
        $data = $snapshot['data'] ?? [];
        $maxFunctions = (int) config('artifacts.binary_max_functions');
        $seeds = [];

        $addSeed = function (int $address, ?string $name, string $source) use (&$seeds): void {
            $key = sprintf('%x', $address);
            if (! isset($seeds[$key])) {
                $seeds[$key] = ['address' => $address, 'name' => $name, 'source' => $source];
            } elseif ($name !== null && $seeds[$key]['name'] === null) {
                $seeds[$key]['name'] = $name;
            }
        };

        $inRange = function (int $address) use ($segments): bool {
            foreach ($segments as $segment) {
                if ($address >= $segment['base'] && $address < $segment['base'] + strlen($segment['bytes'])) {
                    return true;
                }
            }

            return false;
        };

        if ($format === ProjectArtifact::FORMAT_ELF && isset($data['entry_point'])) {
            $entry = (int) hexdec((string) $data['entry_point']);
            if ($inRange($entry)) {
                $addSeed($entry, '_start', 'entry');
            }
        }
        if ($format === ProjectArtifact::FORMAT_PE && isset($data['entry_rva'])) {
            $entry = (int) ($data['image_base'] ?? 0) + (int) $data['entry_rva'];
            if ($inRange($entry)) {
                $addSeed($entry, 'entry', 'entry');
            }
        }
        if (isset($config['entry']) && $inRange((int) $config['entry'])) {
            $addSeed((int) $config['entry'], 'entry', 'entry');
        }
        foreach (($data['symbols'] ?? []) as $symbol) {
            if (($symbol['type'] ?? null) !== 2 || ! is_string($symbol['value'] ?? null)) {
                continue;
            }
            $address = (int) hexdec($symbol['value']);
            // ARM Thumb symbols carry the mode bit; mask it for range checks.
            $address &= ~1;
            if ($inRange($address)) {
                $name = is_string($symbol['name'] ?? null) && $symbol['name'] !== '' ? $symbol['name'] : null;
                $addSeed($address, $name, 'symbol');
            }
        }
        foreach (($data['sys_targets'] ?? []) as $sysTarget) {
            if ($inRange((int) $sysTarget)) {
                $addSeed((int) $sysTarget, null, 'sys_target');
            }
        }
        foreach ($xrefs as $xref) {
            if ($xref['kind'] === 'call' && $inRange($xref['to'])) {
                $addSeed($xref['to'], null, 'call_target');
            }
        }

        uasort($seeds, fn ($a, $b) => $a['address'] <=> $b['address']);
        $seeds = array_slice($seeds, 0, $maxFunctions, true);

        $offsetOf = function (int $address) use ($segments): ?int {
            foreach ($segments as $segment) {
                if ($address >= $segment['base'] && $address < $segment['base'] + strlen($segment['bytes'])) {
                    return $segment['file_offset'] === null ? null : $segment['file_offset'] + ($address - $segment['base']);
                }
            }

            return null;
        };

        $functions = [];
        $addresses = array_column($seeds, 'address');
        $i = 0;
        foreach ($seeds as $seed) {
            $next = null;
            foreach ($addresses as $candidate) {
                if ($candidate > $seed['address'] && ($next === null || $candidate < $next)) {
                    $next = $candidate;
                }
            }
            $windowEnd = null;
            foreach ($segments as $segment) {
                if ($seed['address'] >= $segment['base'] && $seed['address'] < $segment['base'] + strlen($segment['bytes'])) {
                    $windowEnd = $segment['base'] + strlen($segment['bytes']);
                    break;
                }
            }
            $end = $next !== null && $windowEnd !== null ? min($next, $windowEnd) : ($next ?? $windowEnd);
            $functions[] = [
                'id' => 'f'.dechex($seed['address']),
                'address' => $seed['address'],
                'file_offset' => $offsetOf($seed['address']),
                'name' => $seed['name'],
                'source' => $seed['source'],
                'size' => $end === null ? null : $end - $seed['address'],
                'size_tentative' => true,
            ];
            $i++;
        }

        return $functions;
    }

    /**
     * One-level caller/callee slice per function. Deeper graphs are
     * computed on demand by the viewer, not stored.
     *
     * @return array<string, array{callers: list<string>, callees: list<string>}>
     */
    private function callGraph(array $functions, array $xrefs): array
    {
        $byAddress = [];
        foreach ($functions as $function) {
            $byAddress[$function['address']] = $function['id'];
        }

        $calls = [];
        foreach ($functions as $function) {
            $calls[$function['id']] = ['callers' => [], 'callees' => []];
        }

        // Attribute each call xref to its enclosing function.
        $addresses = array_keys($byAddress);
        sort($addresses);
        $enclosing = function (int $address) use ($addresses, $byAddress): ?string {
            $best = null;
            foreach ($addresses as $candidate) {
                if ($candidate > $address) {
                    break;
                }
                $best = $candidate;
            }

            return $best === null ? null : $byAddress[$best];
        };

        foreach ($xrefs as $xref) {
            if ($xref['kind'] !== 'call' || ! isset($byAddress[$xref['to']])) {
                continue;
            }
            $caller = $enclosing($xref['from']);
            $callee = $byAddress[$xref['to']];
            if ($caller === null || ! isset($calls[$caller])) {
                continue;
            }
            if (! in_array($callee, $calls[$caller]['callees'], true)) {
                $calls[$caller]['callees'][] = $callee;
            }
            if (! in_array($caller, $calls[$callee]['callers'], true)) {
                $calls[$callee]['callers'][] = $caller;
            }
        }

        return $calls;
    }

    /**
     * Candidate reset/interrupt vectors. Architecturally fixed
     * addresses only — never device-specific peripheral claims.
     *
     * @return list<array{address: int, kind: string, points_to: ?int, note: string}>
     */
    private function vectors(string $target, array $segments): array
    {
        $read16 = function (int $address) use ($segments): ?int {
            foreach ($segments as $segment) {
                $end = $segment['base'] + strlen($segment['bytes']);
                if ($address >= $segment['base'] && $address + 1 < $end) {
                    $off = $address - $segment['base'];

                    return ord($segment['bytes'][$off]) | (ord($segment['bytes'][$off + 1]) << 8);
                }
            }

            return null;
        };
        $covered = fn (int $address): bool => $read16($address) !== null || $this->addressCovered($address, $segments);

        $vectors = [];
        if ($target === 'mos6502') {
            foreach ([0xFFFA => 'NMI vector', 0xFFFC => 'reset vector', 0xFFFE => 'IRQ/BRK vector'] as $address => $kind) {
                if ($covered($address)) {
                    $vectors[] = ['address' => $address, 'kind' => $kind, 'points_to' => $read16($address), 'note' => 'Candidate: valid only if the image is mapped with these addresses visible.'];
                }
            }
        } elseif ($target === 'z80') {
            foreach ([0x0000 => 'reset entry', 0x0008 => 'RST 08h', 0x0010 => 'RST 10h', 0x0018 => 'RST 18h', 0x0020 => 'RST 20h', 0x0028 => 'RST 28h', 0x0030 => 'RST 30h', 0x0038 => 'RST 38h / IM1 interrupt'] as $address => $kind) {
                if ($covered($address)) {
                    $vectors[] = ['address' => $address, 'kind' => $kind, 'points_to' => null, 'note' => 'Candidate entry point (fixed Z80 restart address).'];
                }
            }
        } elseif ($target === 'i8051') {
            foreach ([0x0000 => 'reset entry', 0x0003 => 'external interrupt 0', 0x000B => 'timer 0', 0x0013 => 'external interrupt 1', 0x001B => 'timer 1', 0x0023 => 'serial port'] as $address => $kind) {
                if ($covered($address)) {
                    $vectors[] = ['address' => $address, 'kind' => $kind, 'points_to' => null, 'note' => 'Candidate interrupt vector (classic 8051 map).'];
                }
            }
        }

        return $vectors;
    }

    private function addressCovered(int $address, array $segments): bool
    {
        foreach ($segments as $segment) {
            if ($address >= $segment['base'] && $address < $segment['base'] + strlen($segment['bytes'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Candidate I/O notes mined from our own deterministic output.
     * 8051 SFR-range access is architectural; anything device-shaped
     * (C64 I/O area) requires explicit platform context and stays a
     * candidate.
     *
     * @return list<array{address: int, line: string, note: string}>
     */
    private function ioNotes(string $target, array $config, array $windows): array
    {
        $notes = [];
        foreach ($windows as $window) {
            foreach ($window['lines'] as $line) {
                $text = $line['text'];
                if ($target === 'i8051' && preg_match('/\$(8[0-9A-F]|9[0-9A-F]|A[0-9A-F]|B[0-9A-F]|C[0-9A-F]|D[0-9A-F]|E[0-9A-F]|F[0-9A-F])\b/', $text, $m) === 1) {
                    $notes[] = [
                        'address' => $line['address'],
                        'line' => mb_substr($text, 0, 80),
                        'note' => 'Candidate special-function-register access ($'.$m[1].'); peripheral meaning needs the exact device datasheet.',
                    ];
                }
                if ($target === 'mos6502' && ($config['platform'] ?? null) === 'c64'
                    && preg_match('/\$D[0-9A-F]{3}\b/', $text, $m) === 1) {
                    $notes[] = [
                        'address' => $line['address'],
                        'line' => mb_substr($text, 0, 80),
                        'note' => 'Possible C64 I/O-area access ('.$m[0].', platform context: c64) — candidate, not a confirmed peripheral access.',
                    ];
                }
                if (count($notes) >= 200) {
                    return $notes;
                }
            }
        }

        return $notes;
    }
}
