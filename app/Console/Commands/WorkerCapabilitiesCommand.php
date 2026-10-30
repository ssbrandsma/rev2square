<?php

namespace App\Console\Commands;

use App\Jobs\RefreshWorkerCapabilities;
use App\Models\WorkerCapability;
use App\Services\Binary\Adapters\AdapterRegistry;
use Illuminate\Console\Command;

/**
 * Inspect (and refresh) the worker capability snapshots that gate
 * subprocess-backend dispatch. --refresh dispatches the capture job
 * to the queue so the WORKER environment is measured; --sync captures
 * inline and is only meaningful when run on the worker host itself.
 */
class WorkerCapabilitiesCommand extends Command
{
    protected $signature = 'binary:worker-capabilities {--refresh : Dispatch a capture job to the queue} {--sync : Capture inline in this process}';

    protected $description = 'Show worker backend capability snapshots, optionally refreshing them.';

    public function handle(): int
    {
        if ($this->option('sync')) {
            (new RefreshWorkerCapabilities)->handle();
            $this->info('Captured inline (this process only — meaningful on the worker host).');
        } elseif ($this->option('refresh')) {
            RefreshWorkerCapabilities::dispatch();
            $this->info('Capture job dispatched. Re-run without flags once the worker picks it up.');
        }

        $rows = [];
        foreach (AdapterRegistry::all() as $adapter) {
            $check = WorkerCapability::verifiedFor($adapter);
            $rows[] = [
                $adapter->id(),
                $check['verified'] ? 'yes' : 'NO',
                $check['version'] ?? '—',
                $check['note'],
            ];
        }
        $this->table(['Backend', 'Worker-verified', 'Version', 'Note'], $rows);

        return self::SUCCESS;
    }
}
