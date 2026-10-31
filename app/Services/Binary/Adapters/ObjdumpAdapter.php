<?php

namespace App\Services\Binary\Adapters;

use App\Models\BinaryAnalysis;
use App\Models\ProjectArtifact;

/**
 * GNU objdump adapter (isolated multiarch runner): real disassembly
 * for x86 and x86-64 via the mature BFD decoders — ELF/PE directly,
 * raw binaries with an explicit machine + VMA, and Intel HEX /
 * S-record files natively. No decompilation (objdump cannot
 * decompile); that stays UNSUPPORTED with a pointer at the Ghidra path.
 *
 * Execution crosses the spool boundary: the coordinator submits a
 * (mode, machine, vma) job and parses the bounded text output back
 * home. Verified routing stays deliberately narrow (i386 family);
 * probed-but-unverified architectures surface via
 * candidateTargets() until a qualified run admits them.
 */
final class ObjdumpAdapter extends SandboxedBackend
{
    /** Verified target => objdump -m name. Extended only with tests. */
    public const VERIFIED_MACHINES = [
        'x86' => 'i386',
        'x86-64' => 'i386:x86-64',
    ];

    /**
     * Probed-architecture token => application target. UNVERIFIED by
     * design: these only feed candidateTargets() with an explicit
     * unverified note, never routing.
     */
    private const DOCUMENTED_ARCH_TARGETS = [
        'arm' => 'arm32',
        'aarch64' => 'aarch64',
        'mips' => 'mips32',
        'riscv' => 'riscv32',
        'powerpc' => 'ppc32',
        'avr' => 'avr',
        'msp430' => 'msp430',
    ];

    public function id(): string
    {
        return 'objdump';
    }

    public function name(): string
    {
        return 'GNU objdump (isolated multiarch)';
    }

    public function tool(): string
    {
        return 'objdump';
    }

    public function probeBudgetSeconds(): int
    {
        // Covers the COMPLETE bounded runner probe with margin: claim
        // latency (~1s runner poll) + version ping + architecture
        // discovery (`objdump -i`, same runner.py
        // VERSION_TIMEOUT_SECONDS bound each) + collection. A healthy
        // runner answers in seconds; the bound exists for the worst
        // case. Keep aligned with docker/analysis/runner.py
        // (protocol doc section 1); ProbeRoutingTest pins the math.
        return 75;
    }

    public function formats(): array
    {
        return [
            ProjectArtifact::FORMAT_ELF,
            ProjectArtifact::FORMAT_PE,
            ProjectArtifact::FORMAT_RAW,
            ProjectArtifact::FORMAT_IHEX,
            ProjectArtifact::FORMAT_SREC,
            ProjectArtifact::FORMAT_ARCHIVE,
        ];
    }

    public function targets(): array
    {
        return $this->probe()['available'] ? array_keys(self::VERIFIED_MACHINES) : [];
    }

    public function candidateTargets(): array
    {
        $out = [];
        foreach ($this->probeArchitectures() as $arch) {
            if ($arch === 'i386' || $arch === 'iamcu' || ! isset(self::DOCUMENTED_ARCH_TARGETS[$arch])) {
                continue;
            }
            $out[] = [
                'id' => self::DOCUMENTED_ARCH_TARGETS[$arch],
                'backend_id' => 'objdump -m '.$arch,
                'note' => 'Probed in the isolated objdump build but the mapping has no qualified run; see docs/analysis-spool-deployment.md.',
            ];
        }

        return $out;
    }

    public function capabilities(string $format, ?string $target): array
    {
        if (! in_array($format, $this->formats(), true)) {
            return array_fill_keys(Capability::all(), Capability::UNSUPPORTED);
        }

        if ($target === null && in_array($format, [
            ProjectArtifact::FORMAT_RAW, ProjectArtifact::FORMAT_IHEX, ProjectArtifact::FORMAT_SREC,
            ProjectArtifact::FORMAT_ARCHIVE,
        ], true)) {
            $caps = array_fill_keys(Capability::all(), Capability::CONFIGURATION_REQUIRED);
            $caps[Capability::DETECTION] = Capability::SUPPORTED;
            $caps[Capability::METADATA] = $format === ProjectArtifact::FORMAT_RAW ? Capability::UNSUPPORTED : Capability::SUPPORTED;
            $caps[Capability::AI_EXPLAIN] = Capability::SUPPORTED;

            return $caps;
        }

        if ($target === null || ! isset(self::VERIFIED_MACHINES[$target])) {
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
            Capability::EXTRACTION => Capability::UNSUPPORTED,
            Capability::AI_EXPLAIN => Capability::SUPPORTED,
            Capability::COMPARISON => Capability::PARTIAL,
        ];
    }

    /**
     * Parse `objdump -i` architecture rows. The listing is a table
     * where architecture rows carry a shallow indent and target-name
     * rows a deep one — only shallow-indented leading tokens count.
     *
     * @return list<string>
     */
    public static function parseArchitectures(string $output): array
    {
        $archs = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\s{1,8}([A-Za-z0-9_+-]+)(?:\s|$)/', $line, $m) !== 1) {
                continue;
            }
            if (str_contains($m[1], '-') || str_contains($m[1], ':')) {
                continue;
            }
            if (! in_array($m[1], $archs, true)) {
                $archs[] = $m[1];
            }
        }

        return $archs;
    }

    /** Architectures probed inside the isolated runner. @return list<string> */
    public function probeArchitectures(): array
    {
        $probe = $this->spoolProbe();
        $archs = $probe['architectures'] ?? null;
        if (! is_array($archs)) {
            return [];
        }

        return array_values(array_filter($archs, fn ($a) => is_string($a) && $a !== ''));
    }

    /**
     * Documented objdump argv contract (runner-side paths). Enforcement
     * lives in the runner; this builder exists so the PHP side and the
     * conformance tests share one shape. Null when inputs are rejected.
     *
     * @param  array{job_dir: string, input: string, mode: string, machine?: string, vma?: int}  $params
     */
    public function buildCommand(array $params): ?array
    {
        $jobDir = $params['job_dir'] ?? '';
        $input = $params['input'] ?? '';
        if (! is_string($jobDir) || $jobDir === '' || ! is_string($input) || $input === '') {
            return null;
        }
        $binary = '/usr/bin/objdump';

        $mode = $params['mode'] ?? '';
        if ($mode === 'auto') {
            return [$binary, '-d', '-M', 'intel', '-w', $input];
        }

        $machine = $params['machine'] ?? '';
        if (! in_array($machine, self::VERIFIED_MACHINES, true)) {
            return null;
        }
        if ($mode === 'binary') {
            $vma = $params['vma'] ?? null;
            if (! is_int($vma) || $vma < 0) {
                return null;
            }

            return [$binary, '-D', '-M', 'intel', '-w', '-b', 'binary', '-m', $machine, '--adjust-vma='.$vma, $input];
        }
        if ($mode === 'ihex' || $mode === 'srec') {
            return [$binary, '-D', '-M', 'intel', '-w', '-b', $mode, '-m', $machine, $input];
        }

        return null;
    }

    public function analyze(BinaryAnalysis $analysis, string $bytes, array $snapshot, array $config, callable $heartbeat): array
    {
        $started = microtime(true);
        $target = (string) ($analysis->target ?? '');
        $machine = self::VERIFIED_MACHINES[$target] ?? null;
        if ($machine === null) {
            throw new AnalysisFailedException('target_unsupported', 'objdump has no verified machine mapping for the “'.$target.'” target.');
        }

        $format = (string) ($config['member_format'] ?? $analysis->artifact->format);
        $segments = PhpNativeAdapter::segments($format, $bytes, $snapshot, $config);
        if ($segments === []) {
            throw new AnalysisFailedException('no_executable_segments', 'No executable byte ranges could be mapped for this format and target.');
        }
        if (! $heartbeat()) {
            throw new AnalysisFailedException('cancelled_race', 'The run was cancelled before disassembly started.');
        }

        $mode = match ($format) {
            ProjectArtifact::FORMAT_RAW => 'binary',
            ProjectArtifact::FORMAT_IHEX => 'ihex',
            ProjectArtifact::FORMAT_SREC => 'srec',
            default => 'auto',
        };
        if ($mode === 'binary' && ! isset($config['base_address'])) {
            throw new AnalysisFailedException('configuration_required', 'A raw image needs a base address before objdump can place it.');
        }

        $ext = match ($format) {
            ProjectArtifact::FORMAT_ELF => '.elf',
            ProjectArtifact::FORMAT_PE => '.exe',
            ProjectArtifact::FORMAT_IHEX => '.hex',
            ProjectArtifact::FORMAT_SREC => '.srec',
            default => '.bin',
        };
        $result = $this->runSandbox(
            $mode,
            ['machine' => $machine, 'vma' => (int) ($config['base_address'] ?? 0)],
            'input'.$ext,
            $bytes,
            $heartbeat,
        );
        $output = $result['tool_output'];
        if (! is_string($output)) {
            throw new AnalysisFailedException('evidence_rejected', 'The isolated backend returned no disassembly text. Its output was discarded.');
        }

        if (! $heartbeat()) {
            throw new AnalysisFailedException('cancelled_race', 'The run was cancelled during disassembly.');
        }

        // Pure string parsing runs on the coordinator: it executes
        // nothing and only reads the bounded text back home.
        $parsed = $this->parse(
            $output,
            $segments,
            (int) ($config['base_address'] ?? 0),
            $format === ProjectArtifact::FORMAT_RAW,
            (int) config('artifacts.binary_max_instructions'),
            (int) config('artifacts.binary_max_xrefs'),
        );

        $functions = PhpNativeAdapter::functions($format, $bytes, $snapshot, $config, $segments, $parsed['xrefs']);
        $calls = PhpNativeAdapter::callGraph($functions, $parsed['xrefs']);

        $warnings = [];
        if ($parsed['truncated']) {
            $warnings[] = 'disassembly_capped';
        }
        if (count($parsed['xrefs']) >= (int) config('artifacts.binary_max_xrefs')) {
            $warnings[] = 'xrefs_capped';
        }
        if ($parsed['skipped'] > 0) {
            $warnings[] = 'unparsed_output_lines';
        }
        if (($result['evidence']['output_truncated'] ?? false) === true) {
            $warnings[] = 'runner_output_capped';
        }

        return $this->envelope($analysis, $config, $started, 'Isolated GNU objdump disassembly of executable sections; function sizes are tentative; symbols come from extraction metadata.', [
            'tool' => [
                'name' => 'GNU objdump',
                'version' => $this->version(),
                'mode' => $mode,
                'machine' => $mode === 'auto' ? 'bfd-auto' : $machine,
                'syntax' => 'intel',
            ],
            'segments' => array_map(fn ($s) => [
                'name' => $s['name'], 'kind' => $s['kind'], 'file_offset' => $s['file_offset'],
                'file_size' => strlen($s['bytes']), 'base_address' => $s['base'], 'length' => strlen($s['bytes']),
            ], $segments),
            'functions' => $functions,
            'xrefs' => $parsed['xrefs'],
            'calls' => $calls,
            'disassembly' => [
                'windows' => $parsed['windows'],
                'total_instructions' => $parsed['instructions'],
                'total_data_bytes' => 0,
                'truncated' => $parsed['truncated'],
            ],
            'warnings' => $warnings,
        ]);
    }

    /**
     * @return array{windows: list<array{name: string, kind: string, base_address: ?int, file_offset: ?int, lines: list<array{offset: ?int, address: int, bytes: string, text: string}>, truncated: bool}>, xrefs: list<array{from: int, to: int, kind: string}>, instructions: int, skipped: int, truncated: bool}
     */
    public function parse(string $output, array $segments, int $base, bool $isRaw, int $maxInstructions, int $maxXrefs): array
    {
        $windows = [];
        $current = null;
        $xrefs = [];
        $instructions = 0;
        $skipped = 0;
        $truncated = false;

        $flush = function () use (&$windows, &$current): void {
            if ($current !== null) {
                $windows[] = $current;
                $current = null;
            }
        };

        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^Disassembly of section (\S+):/', $line, $m) === 1) {
                $flush();
                $meta = $this->segmentMeta($segments, $m[1]);
                $current = [
                    'name' => $m[1], 'kind' => $meta['kind'] ?? 'objdump-section',
                    'base_address' => $meta['base'] ?? null, 'file_offset' => $meta['file_offset'] ?? null,
                    'lines' => [], 'truncated' => false,
                ];

                continue;
            }
            // Symbol headers, banners and zero-run markers carry no
            // instructions.
            if (preg_match('/^[0-9a-f]+ <[^>]*>:$/', $line) === 1
                || str_contains($line, 'file format')
                || trim($line) === '...'
                || ($line !== '' && $line[0] === '/')
                || trim($line) === '') {
                continue;
            }
            if (preg_match('/^\s*([0-9a-f]+):\s+((?:[0-9a-f]{2} )+)\s*(.*?)\s*$/', $line, $m) !== 1) {
                $skipped++;

                continue;
            }
            if ($instructions >= $maxInstructions) {
                $truncated = true;
                if ($current !== null) {
                    $current['truncated'] = true;
                }

                continue;
            }
            if ($current === null) {
                $current = ['name' => 'output', 'kind' => 'objdump-section', 'base_address' => null, 'file_offset' => null, 'lines' => [], 'truncated' => false];
            }

            $address = hexdec($m[1]);
            $text = trim((string) preg_replace('/\s+/', ' ', $m[3]));
            $current['lines'][] = [
                'offset' => $isRaw ? $address - $base : null,
                'address' => $address,
                'bytes' => str_replace(' ', '', $m[2]),
                'text' => $text,
            ];
            $instructions++;

            $xref = $this->branchTarget($text);
            if ($xref !== null && count($xrefs) < $maxXrefs) {
                $xrefs[] = ['from' => $address, 'to' => $xref[1], 'kind' => $xref[0]];
            }
        }
        $flush();

        // Raw and record formats report no useful section map: anchor the
        // window base on the first line when no segment matched.
        foreach ($windows as &$window) {
            if ($window['base_address'] === null && isset($window['lines'][0])) {
                $window['base_address'] = $window['lines'][0]['address'];
            }
        }
        unset($window);

        return [
            'windows' => $windows, 'xrefs' => $xrefs, 'instructions' => $instructions,
            'skipped' => $skipped, 'truncated' => $truncated,
        ];
    }

    /** @return array{0: string, 1: int}|null kind + absolute target */
    private function branchTarget(string $text): ?array
    {
        if (preg_match('/^(call|jmp|ja|jae|jb|jbe|jc|je|jz|jg|jge|jl|jle|jna|jnae|jnb|jnbe|jnc|jne|jng|jnge|jnl|jnle|jno|jnp|jns|jnz|jo|jp|jpe|jpo|js|loop|loope|loopne|loopnz|loopz)\s+(?:0x)?([0-9a-f]+)\b/', $text, $m) !== 1) {
            return null;
        }
        $kind = $m[1] === 'call' ? 'call' : ($m[1] === 'jmp' ? 'jump' : 'branch');

        return [$kind, hexdec($m[2])];
    }

    /** @return array{kind: string, base: int, file_offset: ?int}|array{} */
    private function segmentMeta(array $segments, string $name): array
    {
        foreach ($segments as $segment) {
            if ($segment['name'] === $name) {
                return ['kind' => $segment['kind'], 'base' => $segment['base'], 'file_offset' => $segment['file_offset']];
            }
        }

        return [];
    }
}
