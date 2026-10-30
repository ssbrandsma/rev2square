<?php

namespace App\Console\Commands;

use App\Models\BinaryAnalysis;
use App\Models\WorkerCapability;
use App\Services\Binary\Adapters\AdapterRegistry;
use App\Services\Binary\Adapters\SandboxedBackend;
use App\Services\Ops\DeployHints;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only isolated-analysis diagnostics for admins and the owner
 * runbook. Shows backend versions, execution readiness, queue depth,
 * the current job, resource-limit failures, stale spool claims, and
 * stale capability warnings. Never prints credentials, file bytes,
 * or stack traces.
 */
class AnalysisDiagnosticsCommand extends Command
{
    protected $signature = 'analysis:diagnostics';

    protected $description = 'Show isolated-analysis backend readiness, queue depth, and spool health.';

    public function handle(): int
    {
        $this->backends();
        $this->queueDepth();
        $this->currentJobs();
        $this->failures();
        $this->spool();

        return self::SUCCESS;
    }

    private function backends(): void
    {
        $rows = [];
        $needsRefresh = false;
        foreach (AdapterRegistry::all() as $adapter) {
            $check = WorkerCapability::verifiedFor($adapter);
            $extra = '';
            if ($adapter instanceof SandboxedBackend) {
                $row = WorkerCapability::where('adapter', $adapter->id())->first();
                $details = $row?->details ?? [];
                $extra = 'runner='.(is_string($details['runner_arch'] ?? null) ? $details['runner_arch'] : '?')
                    .' image='.(is_string($details['image_digest'] ?? null) ? substr($details['image_digest'], 0, 19) : '?');
                $needsRefresh = $needsRefresh || ! $check['verified'];
            }
            $rows[] = [
                $adapter->id(),
                $check['verified'] ? 'ready' : 'NOT READY',
                $check['version'] ?? '—',
                $extra,
                $check['note'],
            ];
        }
        $this->table(['Backend', 'Ready', 'Version', 'Runner', 'Note'], $rows);
        if ($needsRefresh) {
            $this->info(DeployHints::refreshSnapshotsHint());
        }
    }

    private function queueDepth(): void
    {
        $queue = (string) config('artifacts.binary_analysis_queue', 'analysis');
        try {
            $waiting = DB::table('jobs')->where('queue', $queue)->count();
            $reserved = DB::table('jobs')->where('queue', $queue)->whereNotNull('reserved_at')->count();
        } catch (\Throwable) {
            $this->warn('Queue tables unreachable from here.');

            return;
        }
        $this->table(['Analysis queue', 'Waiting', 'Reserved'], [[$queue, $waiting, $reserved]]);
    }

    private function currentJobs(): void
    {
        $rows = [];
        foreach (BinaryAnalysis::where('status', BinaryAnalysis::STATUS_ANALYZING)->orderBy('started_at')->limit(5)->get() as $analysis) {
            $rows[] = [
                $analysis->id,
                $analysis->adapter,
                (string) $analysis->target,
                $analysis->started_at?->toIso8601String() ?? '—',
                (string) ($analysis->progress_note ?? ''),
            ];
        }
        if ($rows === []) {
            $this->info('No analysis currently running.');

            return;
        }
        $this->table(['Run', 'Backend', 'Target', 'Started', 'Note'], $rows);
    }

    private function failures(): void
    {
        try {
            $rows = BinaryAnalysis::where('status', BinaryAnalysis::STATUS_FAILED)
                ->where('finished_at', '>=', now()->subDay())
                ->selectRaw('error_code, COUNT(*) AS n')
                ->groupBy('error_code')
                ->orderByDesc('n')
                ->limit(10)
                ->get();
        } catch (\Throwable) {
            $this->warn('Analysis table unreachable from here.');

            return;
        }
        if ($rows->isEmpty()) {
            $this->info('No failed runs in the last 24h.');

            return;
        }
        $this->table(
            ['Error', 'Runs (24h)'],
            $rows->map(fn ($row) => [(string) ($row->error_code ?? 'unknown'), $row->n])->all()
        );
    }

    private function spool(): void
    {
        $spool = (string) config('artifacts.binary_spool_dir');
        $results = (string) config('artifacts.binary_results_dir');
        $pending = is_dir($spool) ? count(array_diff(scandir($spool) ?: [], ['.', '..'])) : null;
        $claims = is_dir($results) ? count(array_diff(scandir($results) ?: [], ['.', '..', 'global.lock'])) : null;
        $stale = 0;
        if (is_dir($results)) {
            $wall = (int) config('artifacts.binary_spool_wall_seconds', 480);
            foreach (scandir($results) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === 'global.lock') {
                    continue;
                }
                $status = $results.'/'.$entry.'/status.json';
                $mtime = is_file($status) ? @filemtime($status) : false;
                if ($mtime !== false && (time() - $mtime) > $wall + 300) {
                    $stale++;
                }
            }
        }
        $this->table(
            ['Spool dir', 'Pending jobs', 'Results dir', 'Open claims', 'Stale claims'],
            [[$spool, $pending ?? 'n/a (no mount here)', $results, $claims ?? 'n/a (no mount here)', $claims === null ? 'n/a' : $stale]]
        );
        if ($stale > 0) {
            $this->warn($stale.' stale claim(s): a runner may have died mid-job. They fail closed at the coordinator and are pruned hourly; investigate the runner logs if this recurs.');
        }
        if ($pending === null || $claims === null) {
            $this->info('Live spool counts need the coordinator mounts: '.DeployHints::coordinatorArtisan('analysis:diagnostics'));
        }
    }
}
