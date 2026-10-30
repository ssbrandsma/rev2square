<?php

namespace App\Jobs;

use App\Models\WorkerCapability;
use App\Services\Binary\Adapters\AdapterRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
    }

    public function handle(): void
    {
        // This job MUST run on the analysis worker: the captures are
        // only meaningful from the execution environment. Probes are
        // read-only version/architecture checks, never analysis runs.
        $worker = gethostname() ?: null;
        foreach (AdapterRegistry::all() as $adapter) {
            WorkerCapability::capture($adapter, $worker);
        }
    }
}
