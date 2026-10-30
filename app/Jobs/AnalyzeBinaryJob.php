<?php

namespace App\Jobs;

use App\Models\BinaryAnalysis;
use App\Models\ProjectArtifact;
use App\Services\Binary\Adapters\AdapterRegistry;
use App\Services\Binary\Adapters\AnalysisFailedException;
use App\Services\Binary\Adapters\ExternalBackend;
use App\Services\Binary\Adapters\SandboxedBackend;
use App\Services\Binary\ArchiveMember;
use App\Services\Binary\Sandbox\EvidenceValidator;
use Illuminate\Contracts\Queue\ShouldQueue;
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
     * Sandboxed runs get the spool wall budget plus grace; in-process
     * adapters keep the short budget. Resolved at dispatch so the
     * worker never has to guess.
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
                return (int) config('artifacts.binary_spool_wall_seconds', 480) + 60;
            }
        } catch (\Throwable) {
            // Dispatch-time lookup failed; the job itself re-validates.
        }

        return $default;
    }

    public function handle(): void
    {
        $analysis = BinaryAnalysis::find($this->analysisId);
        if ($analysis === null) {
            return;
        }

        // A cancelled-before-start run stays cancelled: never resurrect.
        if ($analysis->status !== BinaryAnalysis::STATUS_QUEUED) {
            return;
        }

        $artifact = $analysis->artifact;
        $uploader = $artifact->uploader;
        if ($uploader !== null && $uploader->isSuspended()) {
            $this->finishFailed($analysis, 'access_revoked', 'The uploader account was suspended before analysis ran.');

            return;
        }

        $analysis->forceFill([
            'status' => BinaryAnalysis::STATUS_ANALYZING,
            'progress_note' => 'Starting deterministic analysis…',
            'started_at' => now(),
        ])->save();

        try {
            $disk = Storage::disk('artifacts');
            if (! $disk->exists($artifact->rawPath())) {
                $this->finishFailed($analysis, 'missing_original', 'The stored file is no longer available. Re-upload it to analyze.');

                return;
            }

            $bytes = $disk->get($artifact->rawPath());
            if (strlen($bytes) !== (int) $artifact->byte_size || hash('sha256', $bytes) !== $artifact->sha256) {
                $this->finishFailed($analysis, 'integrity_mismatch', 'The stored file failed its integrity check. Re-upload it to analyze.');

                return;
            }

            $snapshot = $artifact->readSnapshot() ?? [];
            $config = $analysis->config ?? [];

            $memberName = $config['member'] ?? null;
            if ($artifact->format === ProjectArtifact::FORMAT_ARCHIVE && is_string($memberName) && $memberName !== '') {
                $analysis->forceFill(['progress_note' => 'Extracting archive member…'])->save();
                $staged = ArchiveMember::prepare($bytes, $memberName, $analysis->target, $config);
                if (! ($staged['ok'] ?? false)) {
                    $this->finishFailed($analysis, $staged['code'], $staged['message']);

                    return;
                }
                $bytes = $staged['bytes'];
                $snapshot = $staged['snapshot'];
                $config = array_merge($config, [
                    'member_format' => $staged['format'],
                    'member_arch' => $staged['arch'],
                    'member_info' => $staged['info'],
                ]);
                if (($analysis->target === null || $analysis->target === '') && ($staged['target'] ?? '') !== '') {
                    $analysis->forceFill(['target' => $staged['target']])->save();
                }
            }

            $adapter = AdapterRegistry::get($analysis->adapter);
            if ($adapter === null) {
                $this->finishFailed($analysis, 'unknown_adapter', 'The requested backend is no longer registered.');

                return;
            }

            // Execution-time re-validation: the worker that runs this
            // job re-checks its own backend — never trusts dispatch.
            // Sandboxed adapters re-probe through the spool (the runner
            // may have been replaced since dispatch).
            if ($adapter instanceof SandboxedBackend) {
                $probe = $adapter->probe();
                if (! $probe['available']) {
                    $this->finishFailed($analysis, 'worker_unavailable', 'The isolated backend is unavailable at execution time: '.$probe['reason']);

                    return;
                }
                $live = $adapter->version();
                if ($live !== $analysis->adapter_version) {
                    $this->finishFailed($analysis, 'worker_mismatch', 'Backend version changed between queue and execution (queued '.($analysis->adapter_version ?? 'unknown').', worker has '.($live ?? 'unknown').'). Queue a fresh run.');

                    return;
                }
            }
            if ($adapter instanceof ExternalBackend) {
                $probe = $adapter->probe();
                if (! $probe['available']) {
                    $this->finishFailed($analysis, 'worker_unavailable', 'The worker no longer has this backend: '.$probe['reason']);

                    return;
                }
                $live = $adapter->version();
                if ($live !== $analysis->adapter_version) {
                    $this->finishFailed($analysis, 'worker_mismatch', 'Backend version changed between queue and execution (queued '.($analysis->adapter_version ?? 'unknown').', worker has '.($live ?? 'unknown').'). Queue a fresh run.');

                    return;
                }
            }

            $analysis->forceFill(['progress_note' => 'Disassembling executable segments…'])->save();
            $heartbeat = fn (): bool => $analysis->fresh()?->status === BinaryAnalysis::STATUS_ANALYZING;

            $evidence = $adapter->analyze($analysis, $bytes, $snapshot, $config, $heartbeat);

            // Single choke point: normalize + bound + reject hostile
            // evidence before anything is stored or rendered.
            $evidence = EvidenceValidator::validate($adapter->id(), $evidence);

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
                'finished_at' => now(),
            ])->save();
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
        $encoded = json_encode($evidence);
        if ($encoded !== false && strlen($encoded) <= $cap) {
            return $encoded;
        }

        $windows = &$evidence['disassembly']['windows'];
        if (! is_array($windows)) {
            return null;
        }

        while (true) {
            $encoded = json_encode($evidence);
            if ($encoded !== false && strlen($encoded) <= $cap) {
                $evidence['warnings'][] = 'evidence_trimmed';
                $evidence['disassembly']['truncated'] = true;

                return json_encode($evidence) ?: null;
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
