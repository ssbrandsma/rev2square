<?php

namespace App\Jobs;

use App\Enums\AnalysisStatus;
use App\Models\AnalysisRun;
use App\Services\AI\AIRateLimitedException;
use App\Services\AI\AITimeoutException;
use App\Services\Revival\AnalysisRunRepository;
use App\Services\Revival\RevivalAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AnalyzeProjectJob implements ShouldQueue
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
        public readonly int $runId,
    ) {}

    public function handle(RevivalAnalysisService $service, AnalysisRunRepository $runs): void
    {
        $run = AnalysisRun::findOrFail($this->runId);

        try {
            $service->analyze($run);
        } catch (AIRateLimitedException $e) {
            if ($this->attempts() < $this->tries) {
                $this->release(self::rateLimitDelay($e->resetsAt));
            } else {
                $runs->markFailed($run, AnalysisStatus::FailedModel, 'RATE_LIMITED', $e->getMessage());
                $this->fail($e);
            }
        } catch (AITimeoutException $e) {
            // Requeue via normal backoff while attempts remain.
            if ($this->attempts() >= $this->tries) {
                $runs->markFailed($run, AnalysisStatus::FailedModel, 'TIMEOUT', $e->getMessage());
            }

            throw $e;
        }
    }

    public function failed(?Throwable $e): void
    {
        if ($run = AnalysisRun::find($this->runId)) {
            app(AnalysisRunRepository::class)->markFailed(
                $run,
                AnalysisStatus::FailedModel,
                'JOB_FAILED',
                'Analysis failed: '.substr($e?->getMessage() ?? 'unknown error', 0, 300)
            );
        }
    }

    public static function rateLimitDelay(?int $resetsAt): int
    {
        if ($resetsAt === null) {
            return 300;
        }

        return min(max($resetsAt - time() + 5, 60), 3600);
    }
}
