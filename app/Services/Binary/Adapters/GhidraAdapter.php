<?php

namespace App\Services\Binary\Adapters;

use App\Models\BinaryAnalysis;
use App\Models\ProjectArtifact;

/**
 * Ghidra headless adapter (isolated runner).
 *
 * Execution crosses the spool boundary: the runner imports the file
 * with analyzeHeadless, runs auto-analysis, and executes the reviewed
 * ExportEvidence.java post-script. The coordinator never runs Ghidra
 * itself — probe/version/targets all round-trip through the spool.
 *
 * Language-id verification (Ghidra 12.2 tag, .ldefs sources):
 * VERIFIED maps hold ids confirmed in the 12.2 tree; DOCUMENTED holds
 * plausible ids the runner refuses until a verified run qualifies
 * them. AVR is deliberately absent: Ghidra 12.2 ships no built-in AVR
 * language (39 built-in modules verified, no AVR), so an AVR mapping
 * would need a vetted extension first.
 */
final class GhidraAdapter extends SandboxedBackend
{
    /**
     * Verified target => Ghidra 12.2 language id. Source-verified in
     * the Ghidra_12.2_build .ldefs files; extended only with a
     * qualified run on the isolated runner. The runner enforces this
     * same set — display-only candidates can never execute.
     */
    public const VERIFIED_LANGUAGES = [
        'x86' => 'x86:LE:32:default',
        'x86-64' => 'x86:LE:64:default',
        'arm32' => 'ARM:LE:32:v8',
        'arm32-thumb' => 'ARM:LE:32:v8T',
        'aarch64' => 'AARCH64:LE:64:v8A',
    ];

    /**
     * Documented-but-unverified ids. Shown with an explicit note, never
     * routed: the runner rejects them until qualified.
     */
    public const DOCUMENTED_LANGUAGES = [
        'mips32' => 'MIPS:LE:32:default',
        'riscv32' => 'RISCV:LE:32:default',
        'riscv64' => 'RISCV:LE:64:default',
        'ppc32' => 'PowerPC:BE:32:default',
    ];

    public function id(): string
    {
        return 'ghidra';
    }

    public function name(): string
    {
        return 'Ghidra (isolated headless)';
    }

    public function tool(): string
    {
        return 'ghidra';
    }

    public function formats(): array
    {
        return [
            ProjectArtifact::FORMAT_ELF,
            ProjectArtifact::FORMAT_PE,
            ProjectArtifact::FORMAT_MACHO,
            ProjectArtifact::FORMAT_RAW,
        ];
    }

    public function targets(): array
    {
        return $this->probe()['available'] ? array_keys(self::VERIFIED_LANGUAGES) : [];
    }

    public function candidateTargets(): array
    {
        $out = [];
        foreach (self::DOCUMENTED_LANGUAGES as $target => $language) {
            $out[] = [
                'id' => $target,
                'backend_id' => $language,
                'note' => 'Documented Ghidra language id; unverified on this installation and refused by the runner until qualified.',
            ];
        }

        return $out;
    }

    public function capabilities(string $format, ?string $target): array
    {
        if (! in_array($format, $this->formats(), true)) {
            return array_fill_keys(Capability::all(), Capability::UNSUPPORTED);
        }

        if ($target === null || ! isset(self::VERIFIED_LANGUAGES[$target])) {
            $caps = array_fill_keys(Capability::all(), Capability::DEPENDENCY_UNAVAILABLE);
            $caps[Capability::DETECTION] = Capability::SUPPORTED;
            $caps[Capability::METADATA] = Capability::SUPPORTED;

            return $caps;
        }

        if ($format === ProjectArtifact::FORMAT_RAW) {
            // Base address still decides at request time; the map marks
            // the pair configurable rather than ready.
            $caps = array_fill_keys(Capability::all(), Capability::CONFIGURATION_REQUIRED);
            $caps[Capability::DETECTION] = Capability::SUPPORTED;
            $caps[Capability::METADATA] = Capability::SUPPORTED;
            $caps[Capability::AI_EXPLAIN] = Capability::SUPPORTED;

            return $caps;
        }

        return [
            Capability::DETECTION => Capability::SUPPORTED,
            Capability::METADATA => Capability::SUPPORTED,
            Capability::STRINGS => Capability::SUPPORTED,
            Capability::SEGMENTS => Capability::SUPPORTED,
            Capability::DISASSEMBLY => Capability::SUPPORTED,
            Capability::FUNCTIONS => Capability::SUPPORTED,
            Capability::XREFS => Capability::SUPPORTED,
            Capability::CALLGRAPH => Capability::SUPPORTED,
            Capability::DECOMPILATION => Capability::SUPPORTED,
            Capability::EXTRACTION => Capability::UNSUPPORTED,
            Capability::AI_EXPLAIN => Capability::SUPPORTED,
            Capability::COMPARISON => Capability::PARTIAL,
        ];
    }

    public function analyze(BinaryAnalysis $analysis, string $bytes, array $snapshot, array $config, callable $heartbeat): array
    {
        $started = microtime(true);
        $target = (string) ($analysis->target ?? '');
        $language = self::VERIFIED_LANGUAGES[$target] ?? null;
        if ($language === null) {
            if (isset(self::DOCUMENTED_LANGUAGES[$target])) {
                throw new AnalysisFailedException('target_unsupported', 'The “'.$target.'” language mapping is documented but has no qualified run on the isolated runner yet.');
            }

            throw new AnalysisFailedException('target_unsupported', 'Ghidra has no language mapping for the “'.$target.'” target.');
        }

        $format = (string) ($config['member_format'] ?? $analysis->artifact->format);
        $base = (int) ($config['base_address'] ?? 0);
        if ($format === ProjectArtifact::FORMAT_RAW && ! isset($config['base_address'])) {
            throw new AnalysisFailedException('configuration_required', 'A raw image needs a base address before Ghidra can place it.');
        }
        $entry = $config['entry'] ?? null;
        if ($entry !== null && (! is_int($entry) || $entry < 0)) {
            throw new AnalysisFailedException('configuration_required', 'The entry address was rejected.');
        }
        if (! $heartbeat()) {
            throw new AnalysisFailedException('cancelled_race', 'The run was cancelled before the isolated backend started.');
        }

        $ext = match ($format) {
            ProjectArtifact::FORMAT_ELF => '.elf',
            ProjectArtifact::FORMAT_PE => '.exe',
            ProjectArtifact::FORMAT_MACHO => '.macho',
            default => '.bin',
        };
        $result = $this->runSandbox(
            'headless',
            ['language' => $language, 'base' => $base, 'entry' => $entry],
            'input'.$ext,
            $bytes,
            $heartbeat,
        );

        if (! $heartbeat()) {
            throw new AnalysisFailedException('cancelled_race', 'The run was cancelled during isolated analysis.');
        }

        $raw = $result['evidence'];
        $warnings = $this->stringList($raw['warnings'] ?? null);
        $functions = $this->functions($raw['functions'] ?? null);
        $xrefs = $this->xrefs($raw['xrefs'] ?? null);

        return $this->envelope($analysis, $config, $started, 'Ghidra headless auto-analysis with tool-generated decompilation; addresses rebased to the configured base.', [
            'tool' => [
                'name' => 'Ghidra headless',
                'version' => is_string($raw['tool_version'] ?? null) ? substr($raw['tool_version'], 0, 100) : $this->version(),
                'language' => $language,
                'compiler_spec' => is_string($raw['compiler_spec'] ?? null) ? substr($raw['compiler_spec'], 0, 100) : 'default',
            ],
            'segments' => $this->segments($raw['memory'] ?? null),
            'functions' => $functions,
            'xrefs' => $xrefs,
            'calls' => PhpNativeAdapter::callGraph($functions, $xrefs),
            'disassembly' => $this->disassembly($raw['disassembly'] ?? null),
            'decompilation' => $this->decompilation($raw['decompilation'] ?? null),
            'warnings' => $warnings,
        ]);
    }

    /**
     * Documented headless argv contract (runner-side paths). Enforcement
     * lives in the runner, which re-validates the language against its
     * own allowlist; this builder exists so the PHP side and the
     * conformance tests share one shape. Null when inputs are rejected.
     *
     * @param  array{job_dir: string, input: string, language: string, project: string, script: string, output: string, base?: int}  $params
     */
    public function buildCommand(array $params): ?array
    {
        $language = $params['language'] ?? '';
        if (! in_array($language, self::VERIFIED_LANGUAGES, true)) {
            return null;
        }
        if (($params['script'] ?? '') !== 'ExportEvidence.java') {
            return null;
        }
        foreach (['job_dir', 'input', 'project', 'output'] as $key) {
            if (! isset($params[$key]) || ! is_string($params[$key]) || $params[$key] === '') {
                return null;
            }
        }
        $base = $params['base'] ?? 0;
        if (! is_int($base) || $base < 0) {
            return null;
        }

        return [
            '/opt/ghidra/support/analyzeHeadless', $params['project'], 'analysis', '-import', $params['input'], '-overwrite',
            '-processor', $language, '-cspec', 'default',
            '-postScript', 'ExportEvidence.java', $params['output'], sprintf('0x%x', $base), 'none', '-deleteProject',
        ];
    }

    /** @return list<array{name: string, kind: string, file_offset: null, file_size: int, base_address: int|null, length: int}> */
    private function segments(mixed $value): array
    {
        if (! is_array($value)) {
            throw new AnalysisFailedException('evidence_rejected', 'The isolated backend returned invalid memory evidence. Its output was discarded.');
        }
        $out = [];
        foreach ($value as $block) {
            if (! is_array($block)) {
                throw new AnalysisFailedException('evidence_rejected', 'The isolated backend returned invalid memory evidence. Its output was discarded.');
            }
            $start = self::hexToInt($block['start'] ?? null);
            if ($start === null) {
                throw new AnalysisFailedException('evidence_rejected', 'The isolated backend returned an invalid memory address. Its output was discarded.');
            }
            $size = $block['size'] ?? 0;
            $out[] = [
                'name' => (string) ($block['name'] ?? ''),
                'kind' => 'memory-block',
                'file_offset' => null,
                'file_size' => is_int($size) && $size >= 0 ? $size : 0,
                'base_address' => $start,
                'length' => is_int($size) && $size >= 0 ? $size : 0,
            ];
        }

        return $out;
    }

    /** @return list<array{id: string, address: int, file_offset: null, name: string|null, source: string, size: int|null, size_tentative: bool}> */
    private function functions(mixed $value): array
    {
        if (! is_array($value)) {
            throw new AnalysisFailedException('evidence_rejected', 'The isolated backend returned invalid function evidence. Its output was discarded.');
        }
        $out = [];
        foreach ($value as $fn) {
            if (! is_array($fn)) {
                throw new AnalysisFailedException('evidence_rejected', 'The isolated backend returned invalid function evidence. Its output was discarded.');
            }
            $entry = self::hexToInt($fn['entry'] ?? null);
            if ($entry === null) {
                throw new AnalysisFailedException('evidence_rejected', 'The isolated backend returned an invalid function address. Its output was discarded.');
            }
            $size = $fn['size'] ?? null;
            $out[] = [
                'id' => 'f'.dechex($entry),
                'address' => $entry,
                'file_offset' => null,
                'name' => (string) ($fn['name'] ?? ''),
                'source' => 'ghidra',
                'size' => is_int($size) && $size >= 0 ? $size : null,
                'size_tentative' => true,
            ];
        }

        return $out;
    }

    /** @return list<array{from: int, to: int, kind: string}> */
    private function xrefs(mixed $value): array
    {
        if (! is_array($value)) {
            throw new AnalysisFailedException('evidence_rejected', 'The isolated backend returned invalid reference evidence. Its output was discarded.');
        }
        $out = [];
        foreach ($value as $xref) {
            if (! is_array($xref) || ($xref['kind'] ?? null) !== 'call') {
                throw new AnalysisFailedException('evidence_rejected', 'The isolated backend returned invalid reference evidence. Its output was discarded.');
            }
            $from = self::hexToInt($xref['from'] ?? null);
            $to = self::hexToInt($xref['to'] ?? null);
            if ($from === null || $to === null) {
                throw new AnalysisFailedException('evidence_rejected', 'The isolated backend returned an invalid reference address. Its output was discarded.');
            }
            $out[] = ['from' => $from, 'to' => $to, 'kind' => 'call'];
        }

        return $out;
    }

    /** @return array{windows: list<array{name: string, kind: string, base_address: int|null, file_offset: null, lines: list<array{offset: null, address: int, bytes: string, text: string}>, truncated: bool}>, total_instructions: int, total_data_bytes: int, truncated: bool} */
    private function disassembly(mixed $value): array
    {
        if (! is_array($value) || ! is_array($value['lines'] ?? null)) {
            throw new AnalysisFailedException('evidence_rejected', 'The isolated backend returned invalid disassembly evidence. Its output was discarded.');
        }
        $lines = [];
        foreach ($value['lines'] as $line) {
            if (! is_array($line)) {
                throw new AnalysisFailedException('evidence_rejected', 'The isolated backend returned invalid disassembly evidence. Its output was discarded.');
            }
            $address = self::hexToInt($line['address'] ?? null);
            $bytes = $line['bytes'] ?? null;
            if ($address === null || ! is_string($bytes) || preg_match('#^[0-9a-f]*$#', $bytes) !== 1) {
                throw new AnalysisFailedException('evidence_rejected', 'The isolated backend returned an invalid disassembly line. Its output was discarded.');
            }
            $lines[] = ['offset' => null, 'address' => $address, 'bytes' => $bytes, 'text' => (string) ($line['text'] ?? '')];
        }

        return [
            'windows' => [[
                'segment' => 'ghidra-auto',
                'name' => 'ghidra-auto',
                'kind' => 'ghidra-auto-analysis',
                'base_address' => count($lines) > 0 ? $lines[0]['address'] : null,
                'file_offset' => null,
                'lines' => $lines,
                'truncated' => (bool) ($value['truncated'] ?? false),
            ]],
            'total_instructions' => count($lines),
            'total_data_bytes' => 0,
            'truncated' => (bool) ($value['truncated'] ?? false),
        ];
    }

    /** @return array{functions: list<array{name: string, address: int, c?: string, error?: string}>, truncated: bool} */
    private function decompilation(mixed $value): array
    {
        if (! is_array($value) || ! is_array($value['functions'] ?? null)) {
            throw new AnalysisFailedException('evidence_rejected', 'The isolated backend returned invalid decompilation evidence. Its output was discarded.');
        }
        $out = [];
        foreach ($value['functions'] as $fn) {
            if (! is_array($fn)) {
                throw new AnalysisFailedException('evidence_rejected', 'The isolated backend returned invalid decompilation evidence. Its output was discarded.');
            }
            $entry = self::hexToInt($fn['entry'] ?? null);
            if ($entry === null) {
                throw new AnalysisFailedException('evidence_rejected', 'The isolated backend returned an invalid decompilation address. Its output was discarded.');
            }
            $row = ['name' => (string) ($fn['name'] ?? ''), 'address' => $entry];
            if (array_key_exists('c', $fn)) {
                if (! is_string($fn['c'])) {
                    throw new AnalysisFailedException('evidence_rejected', 'The isolated backend returned invalid decompilation evidence. Its output was discarded.');
                }
                $row['c'] = $fn['c'];
            } else {
                $row['error'] = (string) ($fn['error'] ?? 'unknown');
            }
            $out[] = $row;
        }

        return ['functions' => $out, 'truncated' => (bool) ($value['truncated'] ?? false)];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $warning) {
            $out[] = (string) $warning;
        }

        return $out;
    }
}
