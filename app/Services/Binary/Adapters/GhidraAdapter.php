<?php

namespace App\Services\Binary\Adapters;

use App\Models\BinaryAnalysis;
use App\Models\ProjectArtifact;

/**
 * Ghidra headless adapter. Ships with probing, capability reporting,
 * and a validated argv builder — but no verified end-to-end run has
 * been possible on any installation in this environment (Ghidra is
 * not installed and installs need owner approval), so analyze()
 * refuses with backend_unverified until the documented verification
 * protocol passes. The UI reports dependency_unavailable, never a
 * fake analysis.
 */
final class GhidraAdapter extends ExternalBackend implements BinaryAdapter
{
    public function id(): string
    {
        return 'ghidra';
    }

    public function name(): string
    {
        return 'Ghidra (headless)';
    }

    public function version(): ?string
    {
        return $this->probeVersion($this->binaryPath(), [], '/\d+\.\d+(\.\d+)?/');
    }

    public function probe(): array
    {
        if ($this->binaryPath() === '' || ! is_executable($this->binaryPath())) {
            return ['available' => false, 'reason' => 'Ghidra headless binary not found. Owner setup required (docs/binary-analysis-setup.md).', 'setup_doc' => 'docs/binary-analysis-setup.md'];
        }

        return ['available' => false, 'reason' => 'Ghidra binary present but the headless export integration has no verified run on this installation.', 'setup_doc' => 'docs/binary-analysis-setup.md'];
    }

    public function binaryPath(): string
    {
        return (string) config('artifacts.ghidra_headless');
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
        return [];
    }

    public function candidateTargets(): array
    {
        // Documented Ghidra language IDs — UNVERIFIED against any
        // installed Ghidra here. Shown in the UI only with the
        // unverified note and dependency_unavailable level.
        $ids = [
            'x86' => 'x86:LE:32:default',
            'x86-64' => 'x86:LE:64:default',
            'arm32' => 'ARM:LE:32:v8',
            'aarch64' => 'AARCH64:LE:64:v8A',
            'mips32' => 'MIPS:LE:32:default',
            'riscv32' => 'RISCV:LE:32:default',
            'riscv64' => 'RISCV:LE:64:default',
            'ppc32' => 'PowerPC:BE:32:default',
            'avr' => 'AVR:LE:16:default',
        ];
        $out = [];
        foreach ($ids as $target => $language) {
            $out[] = ['id' => $target, 'backend_id' => $language, 'note' => 'Documented Ghidra language id; unverified on this installation.'];
        }

        return $out;
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
        throw new AnalysisFailedException('backend_unverified', 'The Ghidra integration has no verified run on this installation. See docs/binary-analysis-setup.md for the owner verification protocol.');
    }

    /**
     * Build the headless argv. All paths confined to $jobDir; the
     * language id must come from candidateTargets(); nothing
     * user-supplied reaches the command line unvalidated.
     *
     * @param  array{job_dir: string, input: string, language: string, project: string, script: string, output: string}  $params
     */
    public function buildCommand(array $params): ?array
    {
        $binary = $this->binaryPath();
        if ($binary === '' || ! is_executable($binary)) {
            return null;
        }
        $jobDir = $params['job_dir'] ?? '';
        $input = isset($params['input']) ? $this->confine($jobDir, $params['input']) : null;
        $project = isset($params['project']) ? $this->confine($jobDir, $params['project']) : null;
        $output = isset($params['output']) ? $this->confine($jobDir, $params['output']) : null;
        if ($input === null || $project === null || $output === null) {
            return null;
        }

        $languages = array_column($this->candidateTargets(), 'backend_id', 'id');
        $language = $params['language'] ?? '';
        if (! in_array($language, $languages, true)) {
            return null;
        }

        // The export script is a fixed reviewed file, never user input.
        $script = $params['script'] ?? '';
        if ($script !== 'ExportEvidence.java') {
            return null;
        }

        return [
            $binary, $project, '-import', $input, '-overwrite',
            '-processor', $language, '-cspec', 'default',
            '-postScript', $script, $output, '-deleteProject',
        ];
    }
}
