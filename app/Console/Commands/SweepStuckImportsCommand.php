<?php

namespace App\Console\Commands;

use App\Jobs\ImportRepositoryJob;
use App\Models\Repository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fail imports abandoned without an active worker.
 *
 * Without this sweep a repository can sit on "importing" forever: the job
 * row may be gone (flushed queue, restored database) or overdue without a
 * worker running. The sweep only ever touches status/outlook columns —
 * stored evidence is never modified, so the last valid snapshot survives.
 */
class SweepStuckImportsCommand extends Command
{
    protected $signature = 'revival:sweep-stuck-imports';

    protected $description = 'Fail stuck imports so they show an actionable error instead of hanging on Importing.';

    public function handle(): int
    {
        $grace = now()->subMinutes((int) config('revival.import_stuck_grace_minutes', 15));
        $startTimeout = now()->subMinutes((int) config('revival.import_start_timeout_minutes', 30));

        $indexed = $this->indexImportJobs();

        if ($indexed['unparseable']) {
            $this->warn('A queued import job could not be parsed; skipping sweep rather than risk a false stuck verdict.');

            return 0;
        }

        $stuck = 0;

        $candidates = Repository::where('import_status', 'importing')
            ->where('updated_at', '<', $grace)
            ->get();

        foreach ($candidates as $repository) {
            $jobs = $indexed['map'][$repository->id] ?? [];

            if ($jobs === []) {
                $this->failStuck($repository, 'Import stalled: no queue worker is holding this job. Re-import to retry.');

                $stuck++;

                continue;
            }

            if (collect($jobs)->contains(fn (object $job) => $job->available_at > now()->timestamp)) {
                continue; // Scheduled retry or backoff wait: a worker will pick it up.
            }

            if (collect($jobs)->contains(fn (object $job) => $job->reserved_at !== null && $job->reserved_at > now()->subMinutes(10)->timestamp)) {
                continue; // Recently reserved: a worker may still be running it.
            }

            if ($repository->updated_at >= $startTimeout) {
                continue;
            }

            $this->failStuck($repository, 'Import did not start within '.(int) config('revival.import_start_timeout_minutes', 30).' minutes; the queue worker may be down. Re-import to retry.');

            $stuck++;
        }

        $this->info("Sweep complete: {$stuck} stuck import(s) failed.");

        return 0;
    }

    /**
     * @return array{map: array<int, list<object>>, unparseable: bool}
     */
    private function indexImportJobs(): array
    {
        $map = [];
        $unparseable = false;

        foreach (DB::table('jobs')->get() as $row) {
            $payload = json_decode((string) $row->payload, true);

            if (($payload['data']['commandName'] ?? null) !== ImportRepositoryJob::class) {
                continue;
            }

            try {
                $command = unserialize(
                    (string) ($payload['data']['command'] ?? ''),
                    ['allowed_classes' => [ImportRepositoryJob::class]]
                );
            } catch (Throwable) {
                $command = false;
            }

            if (! $command instanceof ImportRepositoryJob) {
                $unparseable = true;

                continue;
            }

            $map[$command->repositoryId][] = $row;
        }

        return ['map' => $map, 'unparseable' => $unparseable];
    }

    private function failStuck(Repository $repository, string $message): void
    {
        $staleSince = $repository->updated_at->toIso8601String();

        $repository->forceFill([
            'import_status' => 'failed',
            'import_error' => $message,
            'next_retry_at' => null,
            'import_stage' => null,
        ])->save();

        Log::info('Stuck import swept to failed.', [
            'repository_id' => $repository->id,
            'project_id' => $repository->project_id,
            'owner' => $repository->owner,
            'name' => $repository->name,
            'stale_since' => $staleSince,
        ]);
    }
}
