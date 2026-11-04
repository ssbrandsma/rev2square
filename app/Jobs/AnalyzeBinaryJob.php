<?php

namespace App\Jobs;

use App\Models\BinaryAnalysis;
use App\Models\ProjectArtifact;
use App\Services\Artifacts\ArtifactStorage;
use App\Services\Binary\Adapters\AdapterRegistry;
use App\Services\Binary\Adapters\AnalysisFailedException;
use App\Services\Binary\Adapters\BinaryAdapter;
use App\Services\Binary\Adapters\ExternalBackend;
use App\Services\Binary\Adapters\SandboxedBackend;
use App\Services\Binary\ArchiveMember;
use App\Services\Binary\Sandbox\EvidenceValidator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AnalyzeBinaryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $analysisId)
    {
        // Dedicated coordinator routing: analysis jobs must never run
        // on the general-purpose workers.
        $this->connection = (string) config('artifacts.binary_analysis_connection', 'analysis-database');
        $this->queue = (string) config('artifacts.binary_analysis_queue', 'analysis');

        $this->timeout = self::timeoutFor($analysisId);
    }

    /**
     * Sandboxed runs get the execution-time re-probe budget plus the
     * spool wall budget plus coordinator grace, with margin;
     * in-process adapters keep the short budget. Resolved at dispatch
     * so the worker never has to guess.
     */
    public static function timeoutFor(int $analysisId): int
    {
        $default = (int) config('artifacts.binary_timeout_seconds', 120);
        try {
            $analysis = BinaryAnalysis::find($analysisId);
            if ($analysis === null) {
                return $default;
            }
            $adapter = AdapterRegistry::get((string) $analysis->adapter);
            if ($adapter instanceof SandboxedBackend) {
                return (int) config('artifacts.binary_spool_wall_seconds', 480)
                    + (int) config('artifacts.binary_spool_grace_seconds', 30)
                    + $adapter->probeBudgetSeconds()
                    + 60;
            }
        } catch (\Throwable) {
            // Dispatch-time lookup failed; the job itself re-validates.
        }

        return $default;
    }

    public function handle(): void
    {
        $analysis = $this->prepareRun();
        if ($analysis === null) {
            return;
        }

        try {
            $disk = Storage::disk('artifacts');
            $artifact = $analysis->artifact;

            $bytes = $this->loadVerifiedBytes($analysis, $artifact, $disk);
            if ($bytes === null) {
                return;
            }

            $snapshot = $artifact->readSnapshot() ?? [];
            $config = $analysis->config ?? [];

            $member = $this->maybeExtractMember($analysis, $artifact, $bytes, $snapshot, $config);
            if ($member === null) {
                return;
            }
            [$bytes, $snapshot, $config] = $member;

            $adapter = $this->resolveAdapter($analysis);
            if ($adapter === null) {
                return;
            }

            if (! $this->revalidateWorker($analysis, $adapter)) {
                return;
            }

            $this->runAndStore($analysis, $artifact, $adapter, $bytes, $snapshot, $config, $disk);
        } catch (AnalysisFailedException $e) {
            if ($e->errorCode === 'cancelled_race') {
                $analysis->forceFill([
                    'status' => BinaryAnalysis::STATUS_CANCELLED,
                    'progress_note' => null,
                    'finished_at' => now(),
                ])->save();

                return;
            }
            $this->finishFailed($analysis, $e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            $this->finishFailed($analysis, 'environmental', 'Analysis failed unexpectedly. The file was kept; retry from the analysis page.');
        }
    }

    public function failed(): void
    {
        $analysis = BinaryAnalysis::find($this->analysisId);
        if ($analysis !== null && ! $analysis->isTerminal()) {
            $this->finishFailed($analysis, 'job_failed', 'Analysis did not finish (worker timeout or crash). Retry from the analysis page.');
        }
    }

    /**
     * Claim a queued run for this worker. Null when there is nothing
     * to do (missing, already handled, or uploader suspended).
     */
    private function prepareRun(): ?BinaryAnalysis
    {
        $analysis = BinaryAnalysis::find($this->analysisId);
        if ($analysis === null) {
            return null;
        }

        // A cancelled-before-start run stays cancelled: never resurrect.
        if ($analysis->status !== BinaryAnalysis::STATUS_QUEUED) {
            return null;
        }

        $uploader = $analysis->artifact->uploader;
        if ($uploader !== null && $uploader->isSuspended()) {
            $this->finishFailed($analysis, 'access_revoked', 'The uploader account was suspended before analysis ran.');

            return null;
        }

        $analysis->forceFill([
            'status' => BinaryAnalysis::STATUS_ANALYZING,
            'progress_note' => 'Starting deterministic analysis…',
            'started_at' => now(),
        ])->save();

        return $analysis;
    }

    /**
     * Read the stored bytes after availability and integrity checks.
     * Null when the run already failed.
     */
    private function loadVerifiedBytes(BinaryAnalysis $analysis, ProjectArtifact $artifact, FilesystemAdapter $disk): ?string
    {
        $status = ArtifactStorage::status('artifacts', $artifact->rawPath());
        if ($status !== ArtifactStorage::OK) {
            ArtifactStorage::logUnavailable('artifacts', $artifact->rawPath(), $status, [
                'analysis_id' => $analysis->id,
                'artifact_id' => $artifact->id,
                'project_id' => $artifact->project_id,
            ]);
            $this->finishFailed($analysis, $status === ArtifactStorage::MISSING ? 'missing_original' : 'storage_unreadable', ArtifactStorage::unavailableMessage());

            return null;
        }

        $bytes = $disk->get($artifact->rawPath());
        if (strlen($bytes) !== (int) $artifact->byte_size || hash('sha256', $bytes) !== $artifact->sha256) {
            $this->finishFailed($analysis, 'integrity_mismatch', 'The stored file failed its integrity check. Re-upload it to analyze.');

            return null;
        }

        return $bytes;
    }

    /**
     * Narrow bytes/snapshot/config to one archive member when the run
     * targets a member. Returns the (possibly unchanged) triple, or
     * null when the run already failed.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}|null
     */
    private function maybeExtractMember(BinaryAnalysis $analysis, ProjectArtifact $artifact, string $bytes, array $snapshot, array $config): ?array
    {
        $memberName = $config['member'] ?? null;
        if (! in_array($artifact->format, [ProjectArtifact::FORMAT_ARCHIVE, ProjectArtifact::FORMAT_APK], true) || ! is_string($memberName) || $memberName === '') {
            return [$bytes, $snapshot, $config];
        }

        $analysis->forceFill(['progress_note' => 'Extracting archive member…'])->save();
        $staged = ArchiveMember::prepare($bytes, $memberName, $analysis->target, $config);
        if (! ($staged['ok'] ?? false)) {
            $this->finishFailed($analysis, $staged['code'], $staged['message']);

            return null;
        }

        $config = array_merge($config, [
            'member_format' => $staged['format'],
            'member_arch' => $staged['arch'],
            'member_info' => $staged['info'],
        ]);
        if (($analysis->target === null || $analysis->target === '') && ($staged['target'] ?? '') !== '') {
            $analysis->forceFill(['target' => $staged['target']])->save();
        }

        return [$staged['bytes'], $staged['snapshot'], $config];
    }

    private function resolveAdapter(BinaryAnalysis $analysis): ?BinaryAdapter
    {
        $adapter = AdapterRegistry::get($analysis->adapter);
        if ($adapter === null) {
            $this->finishFailed($analysis, 'unknown_adapter', 'The requested backend is no longer registered.');

            return null;
        }

        return $adapter;
    }

    /**
     * Execution-time re-validation: the worker that runs this job
     * re-checks its own backend — never trusts dispatch. False when
     * the run already failed.
     */
    private function revalidateWorker(BinaryAnalysis $analysis, BinaryAdapter $adapter): bool
    {
        $unavailablePrefix = match (true) {
            $adapter instanceof SandboxedBackend => 'The isolated backend is unavailable at execution time: ',
            $adapter instanceof ExternalBackend => 'The worker no longer has this backend: ',
            default => null,
        };

        if ($unavailablePrefix === null) {
            return true;
        }

        // Sandboxed adapters re-probe through the spool (the runner
        // may have been replaced since dispatch).
        $probe = $adapter->probe();
        if (! $probe['available']) {
            $this->finishFailed($analysis, 'worker_unavailable', $unavailablePrefix.$probe['reason']);

            return false;
        }

        $live = $adapter->version();
        if ($live !== $analysis->adapter_version) {
            $this->finishFailed($analysis, 'worker_mismatch', 'Backend version changed between queue and execution (queued '.($analysis->adapter_version ?? 'unknown').', worker has '.($live ?? 'unknown').'). Queue a fresh run.');

            return false;
        }

        return true;
    }

    private function runAndStore(BinaryAnalysis $analysis, ProjectArtifact $artifact, BinaryAdapter $adapter, string $bytes, array $snapshot, array $config, FilesystemAdapter $disk): void
    {
        $analysis->forceFill(['progress_note' => 'Disassembling executable segments…'])->save();
        $heartbeat = fn (): bool => $analysis->fresh()?->status === BinaryAnalysis::STATUS_ANALYZING;

        $evidence = $adapter->analyze($analysis, $bytes, $snapshot, $config, $heartbeat);
        $archive = $adapter instanceof SandboxedBackend ? $adapter->lastOutputArchive() : null;
        try {
            // Single choke point: normalize + bound + reject hostile
            // evidence before anything is stored or rendered.
            $evidence = EvidenceValidator::validate($adapter->id(), $evidence);

            // Retained-output persistence BEFORE fitEvidence: an
            // archive discard is recorded in the stored evidence
            // warnings. The tmp file is unlinked on every path by
            // the finally below; persist itself never throws.
            $outputMeta = $this->persistOutputArchive($analysis, $archive, $disk);
            if ($archive !== null && $outputMeta === null) {
                $evidence['warnings'][] = 'output_archive_discarded';
            }

            $encoded = self::fitEvidence($evidence);
            if ($encoded === null) {
                $this->finishFailed($analysis, 'evidence_too_large', 'Analysis output exceeded the storage cap even after trimming disassembly. Narrow the target range and retry.');

                return;
            }

            $key = Str::random(40);
            $disk->put($artifact->project_id.'/'.$key.'.analysis.json', $encoded);

            $warnings = $evidence['warnings'] ?? [];
            $partial = false;
            foreach ($warnings as $warning) {
                if (str_contains((string) $warning, 'capped') || str_contains((string) $warning, 'trimmed')) {
                    $partial = true;
                    break;
                }
            }

            $analysis->forceFill([
                'status' => $partial ? BinaryAnalysis::STATUS_PARTIAL : BinaryAnalysis::STATUS_READY,
                'progress_note' => null,
                'error_code' => null,
                'error_message' => null,
                'evidence_key' => $key,
                'output_meta' => $outputMeta,
                'finished_at' => now(),
            ])->save();
        } finally {
            if (is_string($archive['tmp'] ?? null)) {
                @unlink($archive['tmp']);
            }
        }
    }

    /**
     * Stream the retained tool-output archive into private project
     * storage at the run's deterministic output path. Bytes are
     * re-verified (size + sha256) before the write; any failure
     * returns null WITHOUT failing the run — the bounded evidence
     * still stands, the run simply offers no download. Never throws
     * and never persists the tmp path itself.
     *
     * @param  array{tmp: string, sha256: string, bytes: int, files: int, files_seen: int, truncated: bool, reason: string|null}|null  $archive
     * @return array{sha256: string, bytes: int, files: int, files_seen: int, truncated: bool, reason: string|null}|null
     */
    private function persistOutputArchive(BinaryAnalysis $analysis, ?array $archive, FilesystemAdapter $disk): ?array
    {
        if ($archive === null) {
            return null;
        }
        $tmp = (string) ($archive['tmp'] ?? '');
        try {
            if ($tmp === '' || ! is_file($tmp) || is_link($tmp)
                || @filesize($tmp) !== (int) $archive['bytes']
                || ! is_string($archive['sha256'] ?? null)
                || hash_file('sha256', $tmp) !== $archive['sha256']) {
                return null;
            }
            $stream = @fopen($tmp, 'rb');
            if (! is_resource($stream)) {
                return null;
            }
            try {
                $disk->put($analysis->outputPath(), $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return [
            'sha256' => (string) $archive['sha256'],
            'bytes' => (int) $archive['bytes'],
            'files' => (int) $archive['files'],
            'files_seen' => (int) $archive['files_seen'],
            'truncated' => (bool) ($archive['truncated'] ?? false),
            'reason' => is_string($archive['reason'] ?? null) ? $archive['reason'] : null,
        ];
    }

    private function finishFailed(BinaryAnalysis $analysis, string $code, string $message): void
    {
        $analysis->forceFill([
            'status' => BinaryAnalysis::STATUS_FAILED,
            'progress_note' => null,
            'error_code' => $code,
            'error_message' => $message,
            'finished_at' => now(),
        ])->save();
    }

    /**
     * Fit evidence under the cap by deterministically shedding
     * disassembly lines (latest windows first). Function and xref
     * tables are preserved — findings survive, listing detail sheds.
     */
    public static function fitEvidence(array &$evidence): ?string
    {
        $cap = (int) config('artifacts.binary_max_evidence_bytes');
        // Unescaped slashes: the runner budgets serialized bytes
        // assuming plain '/' (its json.dump default), so the stored
        // encoding must not add a byte per slash on re-serialization.
        $encoded = json_encode($evidence, JSON_UNESCAPED_SLASHES);
        if ($encoded !== false && strlen($encoded) <= $cap) {
            return $encoded;
        }

        $windows = &$evidence['disassembly']['windows'];
        if (! is_array($windows)) {
            return null;
        }

        while (true) {
            $encoded = json_encode($evidence, JSON_UNESCAPED_SLASHES);
            if ($encoded !== false && strlen($encoded) <= $cap) {
                $evidence['warnings'][] = 'evidence_trimmed';
                $evidence['disassembly']['truncated'] = true;

                return json_encode($evidence, JSON_UNESCAPED_SLASHES) ?: null;
            }

            // Drop the second half of the largest window's lines.
            $largest = null;
            $largestCount = 0;
            foreach ($windows as $i => $window) {
                $count = count($window['lines'] ?? []);
                if ($count > $largestCount) {
                    $largestCount = $count;
                    $largest = $i;
                }
            }
            if ($largest === null || $largestCount <= 50) {
                return null;
            }
            $windows[$largest]['lines'] = array_slice($windows[$largest]['lines'], 0, (int) ($largestCount / 2));
            $windows[$largest]['truncated'] = true;
        }
    }
}
