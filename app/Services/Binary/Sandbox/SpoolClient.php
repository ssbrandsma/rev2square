<?php

namespace App\Services\Binary\Sandbox;

use App\Services\Binary\Adapters\AnalysisFailedException;
use Illuminate\Support\Str;

/**
 * Coordinator side of the spool trust boundary.
 *
 * The coordinator (a Laravel queue worker with database and artifact
 * access, but no tool binaries and no analysis secrets) submits
 * structured job specifications to isolated runner containers:
 *
 *   spool volume   (coordinator writes, runners mount read-only)
 *     <job-id>/job.json        schema + tool + profile + params
 *     <job-id>/<input>         exact input bytes
 *   results volume (runners write, coordinator reads + cleans up)
 *     <job-id>/status.json     claimed/running/done/timeout/...
 *     <job-id>/evidence.json   bounded tool evidence
 *     <job-id>/tool.out        bounded raw text (objdump profile)
 *     <job-id>/cancel          coordinator-written cancel flag
 *
 * No argv, paths, mounts, or environment cross the boundary — only the
 * allowlisted (tool, profile, scalar params) triple, which the runner
 * re-validates before executing anything.
 *
 * Non-final on purpose: tests bind a spool fake subclass. Production
 * code must never subclass this to weaken the protocol.
 */
class SpoolClient
{
    public const SCHEMA = 1;

    public function __construct(
        private readonly string $spoolDir,
        private readonly string $resultsDir,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('artifacts.binary_spool_dir', storage_path('app/analysis-spool')),
            (string) config('artifacts.binary_results_dir', storage_path('app/analysis-results')),
        );
    }

    public function spoolDir(): string
    {
        return $this->spoolDir;
    }

    public function resultsDir(): string
    {
        return $this->resultsDir;
    }

    /**
     * Probe one runner: tool version, architecture, image identity.
     * Never throws — a failed probe is simply an unavailable backend.
     *
     * $claimed reports whether the runner accepted this probe's job:
     * true when an answer arrived or the runner claimed the job but
     * is still working past the budget (caller maps that to a
     * pending qualification, never to a broken backend), false when
     * no runner picked the job up. Set on every return.
     *
     * @return array{tool: string, tool_version: ?string, runner_arch: string, image_digest: string, ...}|null
     */
    public function probe(string $tool, int $timeoutSeconds = 30, ?bool &$claimed = null): ?array
    {
        $claimed = false;
        if (! is_dir($this->spoolDir) || ! is_dir($this->resultsDir)) {
            return null;
        }
        $jobId = (string) Str::uuid();
        if (! $this->writeJob($jobId, ['schema' => self::SCHEMA, 'tool' => $tool, 'kind' => 'probe'])) {
            return null;
        }

        $answer = null;
        try {
            $deadline = microtime(true) + max(1, $timeoutSeconds);
            while (microtime(true) < $deadline) {
                $status = $this->readJson($this->resultsDir.'/'.$jobId.'/status.json', 8192);
                if (is_array($status) && ($status['schema'] ?? null) === self::SCHEMA && isset($status['tool_version'], $status['runner_arch'])) {
                    $answer = $status;
                    break;
                }
                usleep(250000);
            }
        } finally {
            // Read BEFORE any cleanup: the claim dir exists exactly
            // when the runner accepted this job (fresh UUID per probe,
            // so no other probe's claim can collide).
            $claimDir = $this->resultsDir.'/'.$jobId;
            $claimed = $answer !== null || (is_dir($claimDir) && ! is_link($claimDir));
            if ($answer === null) {
                // Timeout: the runner may still be working (slow
                // first-probe qualification, loaded host). Remove only
                // OUR submission side; never the results claim — the
                // runner owns it until answered, and deleting it
                // mid-write would race the runner. A late answer lands
                // harmlessly and the scheduled prune reaps it by age.
                $this->removeJobDir($this->spoolDir.'/'.$jobId);
            } else {
                $this->cleanup($jobId);
            }
        }

        return $answer;
    }

    /**
     * Run one sandboxed analysis and return the terminal status plus
     * decoded evidence (still untrusted — validate before ingesting).
     *
     * @param  array<string, mixed>  $params  scalar-only profile params
     * @param  array{wall_seconds: int}  $limits
     * @param  callable(): bool  $heartbeat
     * @return array{status: array<string, mixed>, evidence: array<string, mixed>|null, tool_output: string|null, claim_dir: string}
     *
     * @throws AnalysisFailedException
     */
    public function run(
        string $tool,
        string $profile,
        array $params,
        string $inputName,
        string $bytes,
        array $limits,
        callable $heartbeat,
    ): array {
        if (preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$#', $inputName) !== 1) {
            throw new AnalysisFailedException('configuration_required', 'The analysis input name was rejected.');
        }
        foreach ($params as $value) {
            if (! is_scalar($value) && $value !== null) {
                throw new AnalysisFailedException('configuration_required', 'The analysis parameters were rejected.');
            }
        }
        $wall = (int) ($limits['wall_seconds'] ?? 60);
        if ($wall < 1 || $wall > 3600) {
            throw new AnalysisFailedException('configuration_required', 'The analysis wall-clock budget was rejected.');
        }

        if (! is_dir($this->spoolDir) || ! is_dir($this->resultsDir)) {
            throw new AnalysisFailedException('spool_unavailable', 'The analysis spool is not mounted here. Queue analysis only where the coordinator and runners share the spool volumes.');
        }
        $jobId = (string) Str::uuid();
        $jobDir = $this->spoolDir.'/'.$jobId;
        if (! @mkdir($jobDir, 0755, true)) {
            throw new AnalysisFailedException('spool_unavailable', 'The analysis spool is not writable. Start the analysis runners first.');
        }
        if (@file_put_contents($jobDir.'/'.$inputName, $bytes) === false) {
            $this->cleanup($jobId);

            throw new AnalysisFailedException('spool_unavailable', 'The analysis spool is not writable. Start the analysis runners first.');
        }
        $job = [
            'schema' => self::SCHEMA,
            'tool' => $tool,
            'kind' => 'analyze',
            'profile' => $profile,
            'params' => $params,
            'input' => $inputName,
            'input_size' => strlen($bytes),
            'input_sha256' => hash('sha256', $bytes),
            'limits' => ['wall_seconds' => $wall],
        ];
        if (! $this->writeJsonAtomic($jobDir.'/job.json', $job)) {
            $this->cleanup($jobId);

            throw new AnalysisFailedException('spool_unavailable', 'The analysis spool is not writable. Start the analysis runners first.');
        }

        // The runner owns the wall clock; the coordinator waits a grace
        // period longer so runner-side timeouts report first.
        $grace = max(1, (int) config('artifacts.binary_spool_grace_seconds', 30));
        $deadline = microtime(true) + $wall + $grace;
        $claimDir = $this->resultsDir.'/'.$jobId;
        $cancelled = false;
        try {
            while (true) {
                if (! $heartbeat()) {
                    @file_put_contents($claimDir.'/cancel', "cancelled\n");
                    $cancelled = true;
                }
                $status = $this->readJson($claimDir.'/status.json', 8192);
                $state = is_array($status) ? ($status['state'] ?? null) : null;
                if (in_array($state, ['done', 'failed', 'timeout', 'cancelled'], true)) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    @file_put_contents($claimDir.'/cancel', "coordinator-giveup\n");

                    throw new AnalysisFailedException('environmental', 'The isolated runner did not answer in time. Its output was discarded; retry from the analysis page.');
                }
                usleep(500000);
            }

            /** @var array<string, mixed> $status */
            if ($state === 'cancelled' || $cancelled) {
                throw new AnalysisFailedException('cancelled_race', 'The run was cancelled.');
            }
            if ($state === 'timeout') {
                throw new AnalysisFailedException('analysis_timeout', 'Analysis exceeded its wall-clock budget ('.$wall.'s) and the isolated process tree was terminated. Narrow the target range and retry.');
            }
            if ($state !== 'done') {
                $error = is_string($status['error'] ?? null) ? substr($status['error'], 0, 200) : 'unknown runner error';

                throw new AnalysisFailedException('runner_failed', 'The isolated backend failed: '.$error);
            }

            $evidence = $this->readJson($claimDir.'/evidence.json', 512 * 1024 + 1);
            if (! is_array($evidence)) {
                throw new AnalysisFailedException('evidence_rejected', 'The isolated backend returned unreadable evidence. Its output was discarded.');
            }
            $toolOut = null;
            if (is_file($claimDir.'/tool.out')) {
                $toolOut = $this->readBounded($claimDir.'/tool.out', 8 * 1024 * 1024 + 1);
                if ($toolOut === null) {
                    throw new AnalysisFailedException('evidence_rejected', 'The isolated backend returned oversized output. Its output was discarded.');
                }
            }
            $storedFiles = $this->collectStoredFiles($claimDir, $evidence);

            return ['status' => $status, 'evidence' => $evidence, 'tool_output' => $toolOut, 'claim_dir' => $claimDir, 'stored_files' => $storedFiles];
        } finally {
            if (! $cancelled) {
                $this->cleanup($jobId);
            } else {
                // Leave cancel delivery + runner cleanup a moment, then
                // remove our side; the scheduled prune covers strays.
                usleep(500000);
                $this->cleanup($jobId);
            }
        }
    }

    /** Remove both sides of one job. Best-effort, never throws. */
    public function cleanup(string $jobId): void
    {
        if (preg_match('#^[A-Za-z0-9-]{1,64}$#', $jobId) !== 1) {
            return;
        }
        $this->removeJobDir($this->spoolDir.'/'.$jobId);
        $this->removeJobDir($this->resultsDir.'/'.$jobId);
    }

    /** Remove one side of one job. Best-effort, never throws. */
    private function removeJobDir(string $dir): void
    {
        if (is_link($dir) || ! is_dir($dir)) {
            return;
        }
        foreach (['job.json', 'status.json', 'evidence.json', 'tool.out', 'cancel'] as $known) {
            if (is_file($dir.'/'.$known) && ! is_link($dir.'/'.$known)) {
                @unlink($dir.'/'.$known);
            }
        }
        // Input + promoted files: unknown names, still confined.
        foreach (@scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_file($path) && ! is_link($path)) {
                @unlink($path);
            }
        }
        // Promotion candidates (extract tool): validated names only.
        $filesDir = $dir.'/files';
        if (is_dir($filesDir) && ! is_link($filesDir)) {
            foreach (@scandir($filesDir) ?: [] as $entry) {
                if (preg_match('#^[0-9a-f]{64}\.bin$#', $entry) === 1) {
                    @unlink($filesDir.'/'.$entry);
                }
            }
            @rmdir($filesDir);
        }
        @rmdir($dir);
    }

    /**
     * Remove stale spool/results entries older than $maxAgeSeconds.
     * Used by the scheduled prune; never throws.
     *
     * @return array{spool: int, results: int}
     */
    public function prune(int $maxAgeSeconds): array
    {
        $removed = ['spool' => 0, 'results' => 0];
        foreach (['spool' => $this->spoolDir, 'results' => $this->resultsDir] as $key => $base) {
            if (! is_dir($base)) {
                continue;
            }
            foreach (@scandir($base) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === 'global.lock') {
                    continue;
                }
                if (preg_match('#^[A-Za-z0-9-]{1,64}$#', $entry) !== 1) {
                    continue;
                }
                $dir = $base.'/'.$entry;
                $mtime = @filemtime($dir);
                if ($mtime !== false && (time() - $mtime) > $maxAgeSeconds) {
                    $this->cleanup($entry);
                    $removed[$key]++;
                }
            }
        }

        return $removed;
    }

    private function writeJob(string $jobId, array $job): bool
    {
        $jobDir = $this->spoolDir.'/'.$jobId;
        if (! @mkdir($jobDir, 0755, true)) {
            return false;
        }

        return $this->writeJsonAtomic($jobDir.'/job.json', $job);
    }

    private function writeJsonAtomic(string $path, array $data): bool
    {
        $encoded = json_encode($data);
        if ($encoded === false) {
            return false;
        }
        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (@file_put_contents($tmp, $encoded) === false) {
            return false;
        }

        return @rename($tmp, $path);
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $path, int $maxBytes): ?array
    {
        if (! is_file($path) || is_link($path)) {
            return null;
        }
        $size = @filesize($path);
        if ($size === false || $size > $maxBytes) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (! is_string($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Copy promotion candidates out of the claim (which is cleaned up
     * on return) into coordinator temp files. Names, sizes, and hashes
     * are re-verified against the evidence manifest.
     *
     * @param  array<string, mixed>  $evidence
     * @return array<string, array{path: string, size: int, tmp: string}>
     */
    private function collectStoredFiles(string $claimDir, array $evidence): array
    {
        $stored = $evidence['stored_files'] ?? [];
        if (! is_array($stored) || $stored === []) {
            return [];
        }
        $out = [];
        foreach (array_slice($stored, 0, 5) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sha = $row['sha256'] ?? null;
            $size = $row['size'] ?? null;
            $path = $row['path'] ?? null;
            if (! is_string($sha) || preg_match('#^[0-9a-f]{64}$#', $sha) !== 1
                || ! is_int($size) || $size <= 0 || $size > 8 * 1024 * 1024
                || ! is_string($path) || $path === '') {
                continue;
            }
            $src = $claimDir.'/files/'.$sha.'.bin';
            if (! is_file($src) || is_link($src) || @filesize($src) !== $size) {
                continue;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'rev2-spool-');
            if ($tmp === false) {
                continue;
            }
            if (@copy($src, $tmp) && hash_file('sha256', $tmp) === $sha) {
                $out[$sha] = ['path' => $path, 'size' => $size, 'tmp' => $tmp];
            } else {
                @unlink($tmp);
            }
        }

        return $out;
    }

    private function readBounded(string $path, int $maxBytes): ?string
    {
        if (! is_file($path) || is_link($path)) {
            return null;
        }
        $size = @filesize($path);
        if ($size === false || $size > $maxBytes) {
            return null;
        }

        $raw = @file_get_contents($path);

        return is_string($raw) ? $raw : null;
    }
}
