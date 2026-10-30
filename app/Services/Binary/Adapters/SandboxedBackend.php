<?php

namespace App\Services\Binary\Adapters;

use App\Models\BinaryAnalysis;
use App\Models\WorkerCapability;
use App\Services\Binary\Sandbox\EvidenceValidator;
use App\Services\Binary\Sandbox\SpoolClient;
use App\Services\Ops\DeployHints;

/**
 * Shared spool boundary for isolated backends (objdump, Ghidra, JADX,
 * ILSpyCmd, extraction). The coordinator never executes tool binaries:
 * probe/version round-trip through the spool, and analyze() submits a
 * structured job the runner re-validates before running anything.
 *
 * Architecture gate: a runner whose architecture differs from the
 * coordinator's is reported unavailable — emulated execution is never
 * silently qualified (see docs/analysis-spool-deployment.md).
 */
abstract class SandboxedBackend implements BinaryAdapter
{
    /** Spool tool name the runner was started with. */
    abstract public function tool(): string;

    private ?array $probeMemo = null;

    private bool $probeDone = false;

    public function version(): ?string
    {
        if (! app()->runningInConsole() || ! $this->hasSpoolMounts()) {
            return WorkerCapability::verifiedFor($this)['version'];
        }

        $probe = $this->spoolProbe();

        return is_string($probe['tool_version'] ?? null) && $probe['tool_version'] !== ''
            ? substr($probe['tool_version'], 0, 200)
            : null;
    }

    public function probe(): array
    {
        // HTTP readers use coordinator snapshots, never submit spool jobs.
        if (! app()->runningInConsole()) {
            return $this->snapshotProbe();
        }

        // Console, but runningInConsole() alone is not a coordinator
        // identity check: only the spool mounts prove it. Any other
        // console context (app/queue/scheduler shells, tests) reads the
        // same coordinator snapshots as HTTP instead of submitting jobs
        // no runner will ever answer.
        if (! $this->hasSpoolMounts()) {
            return $this->snapshotProbe();
        }

        $answer = $this->spoolProbe();
        if ($answer === null) {
            return [
                'available' => false,
                'reason' => 'The '.$this->tool().' runner did not answer its capability probe ('.$this->probeBudgetSeconds().'s budget). '.DeployHints::startRunnersHint(),
                'setup_doc' => 'docs/analysis-spool-deployment.md',
            ];
        }

        $runnerArch = self::normalizeArch((string) ($answer['runner_arch'] ?? ''));
        $ownArch = self::normalizeArch((string) php_uname('m'));
        if ($runnerArch !== $ownArch) {
            return [
                'available' => false,
                'reason' => 'The '.$this->tool().' runner reports architecture '.$runnerArch.' but the coordinator runs '.$ownArch.'; emulated execution is not qualified.',
                'setup_doc' => 'docs/analysis-spool-deployment.md',
            ];
        }

        $qualified = $this->qualifyProbeAnswer($answer);
        if ($qualified !== null) {
            return $qualified;
        }

        $version = $this->version();

        return [
            'available' => true,
            'reason' => 'Isolated '.$this->tool().' runner ('.($version ?? 'unversioned').', '.$runnerArch.', image '.($answer['image_digest'] ?? 'unknown').').',
            'setup_doc' => null,
        ];
    }

    /**
     * Extra per-adapter probe qualification. Return null when the
     * runner answer is sufficient, or an unavailable probe-shaped
     * array when the backend needs more proof (e.g. a fixture
     * decompile) before it may be declared ready.
     *
     * @param array<string,mixed> $answer
     * @return array{available:false,reason:string,setup_doc:string}|null
     */
    protected function qualifyProbeAnswer(array $answer): ?array
    {
        return null;
    }

    public static function normalizeArch(string $arch): string
    {
        return match (strtolower($arch)) {
            'aarch64', 'arm64' => 'arm64',
            'x86_64', 'amd64' => 'amd64',
            default => strtolower($arch),
        };
    }

    /**
     * Safe runner-identity subset for capability snapshots and admin
     * diagnostics. Never contains secrets — only versions, arch, and
     * image identity.
     *
     * @return array{runner_arch: ?string, image_digest: string, tool_image: string, coordinator_arch: string}
     */
    public function runnerDetails(): array
    {
        $probe = $this->spoolProbe();

        return [
            'runner_arch' => is_string($probe['runner_arch'] ?? null) ? $probe['runner_arch'] : null,
            'image_digest' => (string) ($probe['image_digest'] ?? 'unknown'),
            'tool_image' => (string) ($probe['tool_image'] ?? 'unknown'),
            'coordinator_arch' => self::normalizeArch((string) php_uname('m')),
        ];
    }

    /** @return array<string, mixed>|null */
    protected function spoolProbe(): ?array
    {
        if (! $this->probeDone) {
            $this->probeDone = true;
            $this->probeMemo = $this->spool()->probe(
                $this->tool(),
                $this->probeBudgetSeconds(),
            );
        }

        return $this->probeMemo;
    }

    /**
     * Coordinator-side probe budget in seconds. Steady-state probes
     * are version pings (~seconds); adapters whose first probe pays a
     * bounded qualification cost override with margin.
     */
    protected function probeBudgetSeconds(): int
    {
        return (int) config('artifacts.binary_spool_probe_seconds', 10);
    }

    /** Coordinator identity check: the spool mounts, not the SAPI. */
    protected function hasSpoolMounts(): bool
    {
        $spool = $this->spool();

        return is_dir($spool->spoolDir()) && is_dir($spool->resultsDir());
    }

    /** @return array{available:bool,reason:string,setup_doc:?string} */
    private function snapshotProbe(): array
    {
        $worker = WorkerCapability::verifiedFor($this);

        return [
            'available' => $worker['verified'],
            'reason' => $worker['note'],
            'setup_doc' => $worker['verified'] ? null : 'docs/analysis-spool-deployment.md',
        ];
    }

    protected function spool(): SpoolClient
    {
        return app(SpoolClient::class);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  callable(): bool  $heartbeat
     * @return array{status: array<string, mixed>, evidence: array<string, mixed>, tool_output: string|null, stored_files: array<string, array{path: string, size: int, tmp: string}>}
     *
     * @throws AnalysisFailedException
     */
    protected function runSandbox(
        string $profile,
        array $params,
        string $inputName,
        string $bytes,
        callable $heartbeat,
        ?int $wallSeconds = null,
    ): array {
        $result = $this->spool()->run(
            $this->tool(),
            $profile,
            $params,
            $inputName,
            $bytes,
            ['wall_seconds' => $wallSeconds ?? (int) config('artifacts.binary_spool_wall_seconds', 480)],
            $heartbeat,
        );

        $evidence = $result['evidence'] ?? null;
        if (! is_array($evidence) || ($evidence['schema'] ?? null) !== SpoolClient::SCHEMA) {
            throw new AnalysisFailedException('evidence_rejected', 'The isolated backend returned evidence with an unknown schema. Its output was discarded.');
        }

        return ['status' => $result['status'], 'evidence' => $evidence, 'tool_output' => $result['tool_output'], 'stored_files' => $result['stored_files'] ?? []];
    }

    /**
     * Runner evidence uses hex-string addresses; the envelope needs
     * integers. Null on any malformed value (the caller then fails the
     * run — malformed addresses never become zero silently).
     */
    protected static function hexToInt(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $hex = str_starts_with(strtolower($value), '0x') ? substr($value, 2) : $value;
        if ($hex === '' || strlen($hex) > 16 || preg_match('#^[0-9a-fA-F]+$#', $hex) !== 1) {
            return null;
        }
        $int = hexdec($hex);

        return is_int($int) && $int >= 0 ? $int : null;
    }

    /**
     * Normalize runner tree evidence (jadx/ilspy/extract manifests)
     * into validated reconstruction/extraction sections. Paths are
     * re-checked here; the evidence validator checks them again.
     *
     * @param  array<string, mixed>  $raw
     * @return array{files: list<array{path: string, size: int, sha256: string}>, inline: array<string, string>, truncated: bool}
     *
     * @throws AnalysisFailedException
     */
    protected function normalizeTree(array $raw): array
    {
        $files = $raw['files'] ?? null;
        $inline = $raw['inline'] ?? null;
        if (! is_array($files) || ! is_array($inline)) {
            throw new AnalysisFailedException('evidence_rejected', 'The isolated backend returned an invalid file manifest. Its output was discarded.');
        }
        $cleanFiles = [];
        foreach ($files as $row) {
            if (! is_array($row)) {
                throw new AnalysisFailedException('evidence_rejected', 'The isolated backend returned an invalid file manifest. Its output was discarded.');
            }
            $path = $row['path'] ?? null;
            $size = $row['size'] ?? null;
            $sha = $row['sha256'] ?? null;
            if (! is_string($path) || ! self::safeRelativePath($path)
                || ! is_int($size) || $size < 0
                || ! is_string($sha) || preg_match('#^[0-9a-f]{64}$#', $sha) !== 1) {
                throw new AnalysisFailedException('evidence_rejected', 'The isolated backend returned an unsafe file path. Its output was discarded.');
            }
            $cleanFiles[] = ['path' => $path, 'size' => $size, 'sha256' => $sha];
        }
        $cleanInline = [];
        foreach ($inline as $path => $content) {
            if (! is_string($path) || ! self::safeRelativePath($path) || ! is_string($content)) {
                throw new AnalysisFailedException('evidence_rejected', 'The isolated backend returned unsafe inline content. Its output was discarded.');
            }
            $cleanInline[$path] = $content;
        }

        return ['files' => $cleanFiles, 'inline' => $cleanInline, 'truncated' => (bool) ($raw['truncated'] ?? false)];
    }

    public static function safeRelativePath(mixed $value): bool
    {
        if (! is_string($value) || $value === '' || strlen($value) > 255) {
            return false;
        }
        if (str_starts_with($value, '/') || str_contains($value, '\\') || str_contains($value, "\0")) {
            return false;
        }
        foreach (explode('/', $value) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Shared envelope fields every sandboxed adapter fills around its
     * normalized sections.
     *
     * @param  array<string, mixed>  $sections
     * @return array<string, mixed>
     */
    protected function envelope(BinaryAnalysis $analysis, array $config, float $started, string $methodNote, array $sections): array
    {
        return array_merge([
            'schema' => 'binary-analysis/1',
            'adapter' => $this->id(),
            'adapter_version' => $this->version(),
            'artifact' => [
                'id' => $analysis->artifact->id,
                'sha256' => $analysis->artifact->sha256,
                'format' => $analysis->artifact->format,
                'member' => $config['member_info'] ?? null,
            ],
            'target' => $analysis->target,
            'config' => $config,
            'analyzed_at' => now()->toIso8601String(),
            'elapsed_ms' => (int) ((microtime(true) - $started) * 1000),
            'method_note' => $methodNote,
            'segments' => [],
            'functions' => [],
            'xrefs' => [],
            'calls' => [],
            'disassembly' => ['windows' => [], 'total_instructions' => 0, 'total_data_bytes' => 0, 'truncated' => false],
            'vectors' => [],
            'io_notes' => [],
            'warnings' => [],
            'limits' => [
                'max_instructions' => (int) config('artifacts.binary_max_instructions'),
                'max_functions' => (int) config('artifacts.binary_max_functions'),
                'max_xrefs' => (int) config('artifacts.binary_max_xrefs'),
            ],
        ], $sections);
    }
}
