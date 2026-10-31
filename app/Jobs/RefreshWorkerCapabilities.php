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

    /**
     * Capture every backend, isolating per-backend failures: one
     * backend's probe crash or persistence error must not strand the
     * remaining backends. Failed backends keep their previous
     * snapshot as-is — a capture failure is neither ready nor proven
     * broken, so it is reported, never stored.
     *
     * @return array{pending: list<string>, failed: list<string>}
     */
    public function handle(): array
    {
        // This job MUST run on the analysis worker: the captures are
        // only meaningful from the execution environment. Probes are
        // read-only version/architecture checks, never analysis runs.
        $worker = gethostname() ?: null;
        $pending = [];
        $failed = [];
        foreach (AdapterRegistry::all() as $adapter) {
            $started = microtime(true);
            try {
                if (WorkerCapability::capture($adapter, $worker) === null) {
                    $pending[] = $adapter->id();
                    $outcome = 'pending';
                } else {
                    $outcome = 'stored';
                }
            } catch (\Throwable $e) {
                $failed[] = $adapter->id();
                $outcome = 'failed';
                Log::error('Capability capture failed; previous snapshot kept.', [
                    'adapter' => $adapter->id(), 'worker' => $worker,
                    'exception' => get_class($e),
                    'message' => mb_substr($e->getMessage(), 0, 500),
                ]);
            }
            Log::info('Capability capture finished.', [
                'adapter' => $adapter->id(), 'worker' => $worker,
                'outcome' => $outcome,
                'elapsed_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
        }
        if ($pending !== []) {
            Log::info('Capability refresh left backends still qualifying; previous snapshots kept.', ['adapters' => $pending, 'worker' => $worker]);
        }
        if ($failed !== []) {
            Log::warning('Capability refresh failed for some backends; their previous snapshots were kept.', ['adapters' => $failed, 'worker' => $worker]);
        }

        return ['pending' => $pending, 'failed' => $failed];
    }
}
