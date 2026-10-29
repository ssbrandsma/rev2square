<?php

namespace App\Services\Binary\Adapters;

use App\Models\BinaryAnalysis;
use App\Models\ProjectArtifact;

/**
 * ILSpy (ilspycmd) adapter for managed .NET assemblies. Same honesty
 * contract as GhidraAdapter: probing + argv validation ship;
 * analysis refuses until a verified run exists.
 */
final class IlspyAdapter extends ExternalBackend implements BinaryAdapter
{
    public function id(): string
    {
        return 'ilspy';
    }

    public function name(): string
    {
        return 'ILSpy (ilspycmd)';
    }

    public function version(): ?string
    {
        return $this->probeVersion($this->binaryPath(), ['--version'], '/\d+\.\d+(\.\d+)?/');
    }

    public function probe(): array
    {
        if ($this->binaryPath() === '' || ! is_executable($this->binaryPath())) {
            return ['available' => false, 'reason' => 'ilspycmd binary not found. Owner setup required (docs/binary-analysis-setup.md).', 'setup_doc' => 'docs/binary-analysis-setup.md'];
        }

        return ['available' => false, 'reason' => 'ilspycmd binary present but the integration has no verified run on this installation.', 'setup_doc' => 'docs/binary-analysis-setup.md'];
    }

    public function binaryPath(): string
    {
        return (string) config('artifacts.ilspy_bin');
    }

    public function formats(): array
    {
        return [ProjectArtifact::FORMAT_PE];
    }

    public function targets(): array
    {
        return [];
    }

    public function candidateTargets(): array
    {
        return [
            ['id' => 'clr', 'backend_id' => 'cil', 'note' => 'Documented ilspycmd input; unverified on this installation.'],
        ];
    }

    public function capabilities(string $format, ?string $target): array
    {
        if ($format !== ProjectArtifact::FORMAT_PE || $target !== 'clr') {
            return array_fill_keys(Capability::all(), Capability::UNSUPPORTED);
        }

        $caps = array_fill_keys(Capability::all(), Capability::DEPENDENCY_UNAVAILABLE);
        $caps[Capability::DETECTION] = Capability::SUPPORTED;
        $caps[Capability::METADATA] = Capability::SUPPORTED;

        return $caps;
    }

    public function analyze(BinaryAnalysis $analysis, string $bytes, array $snapshot, array $config, callable $heartbeat): array
    {
        throw new AnalysisFailedException('backend_unverified', 'The ILSpy integration has no verified run on this installation. See docs/binary-analysis-setup.md for the owner verification protocol.');
    }

    /**
     * @param  array{job_dir: string, input: string, output: string}  $params
     */
    public function buildCommand(array $params): ?array
    {
        $binary = $this->binaryPath();
        if ($binary === '' || ! is_executable($binary)) {
            return null;
        }
        $jobDir = $params['job_dir'] ?? '';
        $input = isset($params['input']) ? $this->confine($jobDir, $params['input']) : null;
        $output = isset($params['output']) ? $this->confine($jobDir, $params['output']) : null;
        if ($input === null || $output === null) {
            return null;
        }

        return [$binary, '-p', '-o', $output, $input];
    }
}
