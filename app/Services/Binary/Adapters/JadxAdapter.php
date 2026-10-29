<?php

namespace App\Services\Binary\Adapters;

use App\Models\BinaryAnalysis;
use App\Models\ProjectArtifact;

/**
 * JADX adapter (DEX/APK/Java reconstruction). Same honesty contract
 * as GhidraAdapter: probing + argv validation ship; analysis refuses
 * until a verified run exists. Reconstructed output would be
 * labelled Java-like code, never original source.
 */
final class JadxAdapter extends ExternalBackend implements BinaryAdapter
{
    public function id(): string
    {
        return 'jadx';
    }

    public function name(): string
    {
        return 'JADX';
    }

    public function version(): ?string
    {
        return $this->probeVersion($this->binaryPath(), ['--version'], '/\d+\.\d+(\.\d+)?/');
    }

    public function probe(): array
    {
        if ($this->binaryPath() === '' || ! is_executable($this->binaryPath())) {
            return ['available' => false, 'reason' => 'JADX binary not found. Owner setup required (docs/binary-analysis-setup.md).', 'setup_doc' => 'docs/binary-analysis-setup.md'];
        }

        return ['available' => false, 'reason' => 'JADX binary present but the integration has no verified run on this installation.', 'setup_doc' => 'docs/binary-analysis-setup.md'];
    }

    public function binaryPath(): string
    {
        return (string) config('artifacts.jadx_bin');
    }

    public function formats(): array
    {
        return [ProjectArtifact::FORMAT_DEX, ProjectArtifact::FORMAT_APK, ProjectArtifact::FORMAT_CLASS, ProjectArtifact::FORMAT_ARCHIVE];
    }

    public function targets(): array
    {
        return [];
    }

    public function candidateTargets(): array
    {
        return [
            ['id' => 'dalvik', 'backend_id' => 'dalvik', 'note' => 'Documented JADX input; unverified on this installation.'],
            ['id' => 'jvm', 'backend_id' => 'jvm', 'note' => 'Documented JADX input; unverified on this installation.'],
        ];
    }

    public function capabilities(string $format, ?string $target): array
    {
        if (! in_array($format, $this->formats(), true)) {
            return array_fill_keys(Capability::all(), Capability::UNSUPPORTED);
        }

        $caps = array_fill_keys(Capability::all(), Capability::DEPENDENCY_UNAVAILABLE);
        $caps[Capability::DETECTION] = Capability::SUPPORTED;
        $caps[Capability::METADATA] = Capability::SUPPORTED;

        return $caps;
    }

    public function analyze(BinaryAnalysis $analysis, string $bytes, array $snapshot, array $config, callable $heartbeat): array
    {
        throw new AnalysisFailedException('backend_unverified', 'The JADX integration has no verified run on this installation. See docs/binary-analysis-setup.md for the owner verification protocol.');
    }

    /**
     * @param  array{job_dir: string, input: string, output: string, threads?: int}  $params
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

        $threads = (int) ($params['threads'] ?? 2);
        if ($threads < 1 || $threads > 4) {
            return null;
        }

        return [$binary, '--no-res', '--no-src-save-cache', '-j', (string) $threads, '-d', $output, $input];
    }
}
