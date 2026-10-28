<?php

namespace App\Jobs;

use App\Enums\AnalysisStatus;
use App\Models\ArchitectureSnapshot;
use App\Services\AI\AIRateLimitedException;
use App\Services\AI\AITimeoutException;
use App\Services\Revival\ArchitectureService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AnalyzeArchitectureJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    /** @return list<int> */
    public function backoff(): array
    {
        return [120, 600];
    }

    public function __construct(
        public readonly int $snapshotId,
    ) {}

    public function handle(ArchitectureService $service): void
    {
        $snapshot = ArchitectureSnapshot::findOrFail($this->snapshotId);

        try {
            $service->analyze($snapshot);
        } catch (AIRateLimitedException $e) {
            if ($this->attempts() < $this->tries) {
                $this->release(AnalyzeProjectJob::rateLimitDelay($e->resetsAt));
            } else {
                $this->markFailed($snapshot, 'RATE_LIMITED', $e->getMessage());
                $this->fail($e);
            }
        } catch (AITimeoutException $e) {
            // Requeue via normal backoff while attempts remain.
            if ($this->attempts() >= $this->tries) {
                $this->markFailed($snapshot, 'TIMEOUT', $e->getMessage());
            }

            throw $e;
        }
    }

    public function failed(?Throwable $e): void
    {
        if ($snapshot = ArchitectureSnapshot::find($this->snapshotId)) {
            $this->markFailed(
                $snapshot,
                'JOB_FAILED',
                'Architecture analysis failed: '.substr($e?->getMessage() ?? 'unknown error', 0, 300)
            );
        }
    }

    private function markFailed(ArchitectureSnapshot $snapshot, string $code, string $message): void
    {
        $snapshot->forceFill([
            'status' => AnalysisStatus::FailedModel,
            'error_code' => $code,
            'error_message' => substr($message, 0, 1900),
            'finished_at' => now(),
        ])->save();
    }
}
