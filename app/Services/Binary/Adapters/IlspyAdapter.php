<?php

namespace App\Services\Binary\Adapters;

use App\Models\BinaryAnalysis;
use App\Models\ProjectArtifact;

/**
 * ILSpy (ilspycmd) adapter (isolated runner) for managed .NET
 * assemblies. Output is C#-like reconstruction, never original
 * source. Native/unmanaged PEs decompile to nothing useful — the
 * runner output records that honestly via an empty manifest.
 */
final class IlspyAdapter extends SandboxedBackend
{
    public function id(): string
    {
        return 'ilspy';
    }

    public function name(): string
    {
        return 'ILSpy (isolated)';
    }

    public function tool(): string
    {
        return 'ilspy';
    }

    public function formats(): array
    {
        return [ProjectArtifact::FORMAT_PE];
    }

    public function targets(): array
    {
        return $this->probe()['available'] ? ['clr'] : [];
    }

    public function candidateTargets(): array
    {
        return [];
    }

    public function capabilities(string $format, ?string $target): array
    {
        if ($format !== ProjectArtifact::FORMAT_PE || $target !== 'clr') {
            return array_fill_keys(Capability::all(), Capability::UNSUPPORTED);
        }

        return [
            Capability::DETECTION => Capability::SUPPORTED,
            Capability::METADATA => Capability::SUPPORTED,
            Capability::STRINGS => Capability::SUPPORTED,
            Capability::SEGMENTS => Capability::UNSUPPORTED,
            Capability::DISASSEMBLY => Capability::UNSUPPORTED,
            Capability::FUNCTIONS => Capability::PARTIAL,
            Capability::XREFS => Capability::UNSUPPORTED,
            Capability::CALLGRAPH => Capability::UNSUPPORTED,
            Capability::DECOMPILATION => Capability::SUPPORTED,
            Capability::EXTRACTION => Capability::UNSUPPORTED,
            Capability::AI_EXPLAIN => Capability::SUPPORTED,
            Capability::COMPARISON => Capability::PARTIAL,
        ];
    }

    public function analyze(BinaryAnalysis $analysis, string $bytes, array $snapshot, array $config, callable $heartbeat): array
    {
        $started = microtime(true);
        if (! $heartbeat()) {
            throw new AnalysisFailedException('cancelled_race', 'The run was cancelled before the isolated backend started.');
        }

        $result = $this->runSandbox('assembly', [], 'input.exe', $bytes, $heartbeat);
        $tree = $this->normalizeTree($result['evidence']);

        $warnings = [];
        if ($tree['truncated']) {
            $warnings[] = 'reconstruction_capped';
        }
        if ($tree['files'] === []) {
            $warnings[] = 'no_types_reconstructed';
        }

        return $this->envelope($analysis, $config, $started, 'ILSpy reconstruction of managed .NET types (C#-like output, never original source).', [
            'tool' => [
                'name' => 'ILSpy (ilspycmd)',
                'version' => $this->version(),
                'profile' => 'assembly',
            ],
            'reconstruction' => [
                'files' => $tree['files'],
                'inline' => $tree['inline'],
                'classes' => JadxAdapter::classes($tree['files'], '.cs'),
                'truncated' => $tree['truncated'],
            ],
            'warnings' => $warnings,
        ]);
    }

    /**
     * Documented ilspycmd argv contract (runner-side paths). Enforcement
     * lives in the runner; this builder exists so the PHP side and the
     * conformance tests share one shape. Null when inputs are rejected.
     *
     * @param  array{job_dir: string, input: string, output: string}  $params
     */
    public function buildCommand(array $params): ?array
    {
        foreach (['job_dir', 'input', 'output'] as $key) {
            if (! isset($params[$key]) || ! is_string($params[$key]) || $params[$key] === '') {
                return null;
            }
        }

        return ['/opt/ilspy/ilspycmd', '-p', '-o', $params['output'], $params['input']];
    }
}
