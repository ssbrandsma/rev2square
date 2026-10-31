<?php

namespace App\Jobs;

use App\Services\Binary\Sandbox\SpoolClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Hourly spool hygiene on the coordinator: removes orphaned spool
 * submissions and result claims older than the retention window
 * (crashed runners, coordinator give-ups). Claimed work in flight is
 * young by definition and never touched.
 */
class PruneAnalysisSpool implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct()
    {
        $this->connection = (string) config('artifacts.binary_analysis_connection', 'analysis-database');
        $this->queue = (string) config('artifacts.binary_analysis_queue', 'analysis');
    }

    public function handle(): void
    {
        $wall = (int) config('artifacts.binary_spool_wall_seconds', 480);
        SpoolClient::fromConfig()->prune($wall + 3600);
    }
}
