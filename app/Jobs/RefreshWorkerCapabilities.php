<?php

namespace App\Jobs;

use App\Models\WorkerCapability;
use App\Services\Binary\Adapters\AdapterRegistry;
use App\Services\Binary\Adapters\SandboxedBackend;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RefreshWorkerCapabilities implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        // Coordinator-only: captures are only meaningful from the
        // restricted execution environment (spool + runners).
        $this->connection = (string) config('artifacts.binary_analysis_connection', 'analysis-database');
        $this->queue = (string) config('artifacts.binary_analysis_queue', 'analysis');
        $this->timeout = self::timeoutFor();
    }

    /**
     * Sequential captures: each sandboxed adapter burns up to its
     * full probe budget against a dead runner, so the timeout must
     * exceed the SUM of budgets plus scheduling margin — or the
     * worker kills the job mid-refresh and snapshots go stale.
     * Derived from the live budgets (not hardcoded) so budget
     * changes cannot silently strand this job.
     */
    public static function timeoutFor(): int
    {
        $budgets = 0;
        foreach (AdapterRegistry::all() as $adapter) {
            if ($adapter instanceof SandboxedBackend) {
                $budgets += $adapter->probeBudgetSeconds();
            }
        }

        return max(120, $budgets + 60);
    }

    public function handle(): void
    {
        // This job MUST run on the analysis worker: the captures are
        // only meaningful from the execution environment. Probes are
        // read-only version/architecture checks, never analysis runs.
        $worker = gethostname() ?: null;
        $pending = [];
        foreach (AdapterRegistry::all() as $adapter) {
            if (WorkerCapability::capture($adapter, $worker) === null) {
                $pending[] = $adapter->id();
            }
        }
        if ($pending !== []) {
            Log::info('Capability refresh left backends still qualifying; previous snapshots kept.', ['adapters' => $pending, 'worker' => $worker]);
        }
    }
}
