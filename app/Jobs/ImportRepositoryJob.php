<?php

namespace App\Jobs;

use App\Models\Repository;
use App\Services\GitHub\GitHubTimeoutException;
use App\Services\GitHub\RateLimitedException;
use App\Services\GitHub\RepositoryNotFoundException;
use App\Services\GitHub\RepositoryPrivateException;
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

    public function handle(GitHubRepositoryImporter $importer): void
    {
        $repository = Repository::findOrFail($this->repositoryId);

        $repository->forceFill(['import_status' => 'importing', 'import_error' => null])->save();

        try {
            $importer->import($repository, $this->branch);
        } catch (RepositoryNotFoundException|RepositoryPrivateException $e) {
            // Permanent: fail immediately without consuming retries.
            $repository->forceFill(['import_status' => 'failed', 'import_error' => $e->getMessage()])->save();
            $this->fail($e);
        } catch (RateLimitedException $e) {
            $delay = $e->resetsAt !== null
                ? min(max($e->resetsAt - time() + 5, 60), 3600)
                : 300;

            if ($this->attempts() < $this->tries) {
                $repository->forceFill([
                    'import_error' => 'Rate limited; retrying automatically.',
                ])->save();
                $this->release($delay);
            } else {
                $repository->forceFill(['import_status' => 'failed', 'import_error' => $e->getMessage()])->save();
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

            $repository->forceFill(['import_status' => 'failed', 'import_error' => $message])->save();
        }
    }
}
