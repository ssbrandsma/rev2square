<?php

namespace App\Jobs;

use App\Models\Repository;
use App\Services\GitHub\ArchiveInvalidException;
use App\Services\GitHub\GitHubTimeoutException;
use App\Services\GitHub\RateLimitedException;
use App\Services\GitHub\RepositoryNotFoundException;
use App\Services\GitHub\RepositoryPrivateException;
use App\Services\Revival\ArchiveRepositoryImporter;
use App\Services\Revival\GitHubRepositoryImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportRepositoryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(
        public readonly int $repositoryId,
        public readonly ?string $branch = null,
    ) {}

    public function handle(GitHubRepositoryImporter $rest, ArchiveRepositoryImporter $archive): void
    {
        $repository = Repository::findOrFail($this->repositoryId);

        $repository->forceFill([
            'import_status' => 'importing',
            'import_error' => null,
            'next_retry_at' => null,
            'import_stage' => null,
            'import_started_at' => $repository->import_started_at ?? now(),
        ])->save();

        try {
            $driver = config('revival.github.import_driver', 'archive');

            ($driver === 'rest' ? $rest : $archive)->import($repository, $this->branch);
        } catch (RepositoryNotFoundException|RepositoryPrivateException|ArchiveInvalidException $e) {
            // Permanent: fail immediately without consuming retries.
            $repository->forceFill(['import_status' => 'failed', 'import_error' => $e->getMessage(), 'next_retry_at' => null, 'import_stage' => null])->save();
            $this->fail($e);
        } catch (RateLimitedException $e) {
            $delay = self::rateLimitDelay($e->resetsAt);

            if ($this->attempts() < $this->tries) {
                $repository->forceFill([
                    'import_error' => 'Rate limited; retrying automatically.',
                    'next_retry_at' => now()->addSeconds($delay),
                ])->save();
                $this->release($delay);
            } else {
                $repository->forceFill(['import_status' => 'failed', 'import_error' => $e->getMessage(), 'next_retry_at' => null, 'import_stage' => null])->save();
                $this->fail($e);
            }
        }
    }

    public function failed(?Throwable $e): void
    {
        if ($repository = Repository::find($this->repositoryId)) {
            $message = $e instanceof GitHubTimeoutException
                ? 'GitHub API request timed out.'
                : 'Import failed: '.($e?->getMessage() ?? 'unknown error');

            $repository->forceFill(['import_status' => 'failed', 'import_error' => $message, 'next_retry_at' => null, 'import_stage' => null])->save();
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
