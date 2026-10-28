<?php

namespace Tests\Feature;

use App\Jobs\ImportRepositoryJob;
use App\Models\Repository;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubException;
use App\Services\GitHub\RateLimitedException;
use App\Services\GitHub\RepositoryNotFoundException;
use App\Services\Revival\GitHubRepositoryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    private function repository(): Repository
    {
        return Repository::factory()->create([
            'owner' => 'acme',
            'name' => 'widgets',
            'canonical_url' => 'https://github.com/acme/widgets',
        ]);
    }

    /**
     * Single stateful fake per test: Http::fake() merges stubs, so a second
     * fake() call never replaces the first. Switch phases via captured flags.
     *
     * @param  array<string, string>  $files
     */
    private function fakeGitHub(array $files, ?callable $contents = null, ?callable $repo = null): void
    {
        Http::fake(function (Request $request) use ($files, $contents, $repo) {
            $url = strtok($request->url(), '?');

            if (preg_match('#/repos/[^/]+/[^/]+$#', $url)) {
                if ($repo !== null) {
                    return $repo();
                }

                return Http::response(['private' => false, 'default_branch' => 'main']);
            }

            if (str_contains($url, '/git/ref/heads/')) {
                return Http::response(['object' => ['sha' => self::SHA]]);
            }

            if (str_contains($url, '/git/trees/')) {
                $tree = [];

                foreach ($files as $path => $content) {
                    $tree[] = ['path' => $path, 'type' => 'blob', 'size' => strlen($content), 'sha' => sha1($path)];
                }

                return Http::response(['sha' => self::SHA, 'truncated' => false, 'tree' => $tree]);
            }

            if (str_contains($url, '/contents/')) {
                $path = substr($url, strpos($url, '/contents/') + strlen('/contents/'));
                $path = implode('/', array_map(rawurldecode(...), explode('/', $path)));

                if ($contents !== null && ($override = $contents($path)) !== null) {
                    return $override;
                }

                $content = $files[$path] ?? '';

                return Http::response([
                    'encoding' => 'base64',
                    'content' => base64_encode($content),
                    'html_url' => 'https://github.com/acme/widgets/blob/'.self::SHA.'/'.$path,
                    'size' => strlen($content),
                ]);
            }

            return Http::response(null, 404);
        });
    }

    private function useDatabaseQueue(): void
    {
        config(['queue.default' => 'database']);
    }

    private function runWorkerOnce(): void
    {
        Artisan::call('queue:work', ['--once' => true, '--sleep' => 0]);
    }

    private function stale(Repository $repository, int $minutes): void
    {
        DB::table('repositories')->where('id', $repository->id)->update([
            'updated_at' => now()->subMinutes($minutes)->toDateTimeString(),
        ]);
    }

    public function test_429_with_retry_after_only_sets_resets_at(): void
    {
        Http::fake(['*' => Http::response(['message' => 'slow down'], 429, ['retry-after' => '45'])]);

        try {
            GitHubClient::fromConfig()->getRepo('acme', 'widgets');
            $this->fail('Expected RateLimitedException.');
        } catch (RateLimitedException $e) {
            $this->assertEqualsWithDelta(time() + 45, $e->resetsAt, 5);
        }
    }

    public function test_retry_after_takes_precedence_over_reset_window(): void
    {
        Http::fake(['*' => Http::response(['message' => 'slow down'], 429, [
            'retry-after' => '45',
            'x-ratelimit-reset' => '1799999999',
        ])]);

        try {
            GitHubClient::fromConfig()->getRepo('acme', 'widgets');
            $this->fail('Expected RateLimitedException.');
        } catch (RateLimitedException $e) {
            $this->assertEqualsWithDelta(time() + 45, $e->resetsAt, 5);
        }
    }

    public function test_403_with_retry_after_is_rate_limited_despite_remaining_quota(): void
    {
        Http::fake(['*' => Http::response(['message' => 'secondary limit'], 403, [
            'retry-after' => '30',
            'x-ratelimit-remaining' => '42',
        ])]);

        try {
            GitHubClient::fromConfig()->getRepo('acme', 'widgets');
            $this->fail('Expected RateLimitedException.');
        } catch (RateLimitedException $e) {
            $this->assertEqualsWithDelta(time() + 30, $e->resetsAt, 5);
        }
    }

    public function test_403_with_exhausted_quota_is_rate_limited(): void
    {
        Http::fake(['*' => Http::response(['message' => 'quota out'], 403, [
            'x-ratelimit-remaining' => '0',
            'x-ratelimit-reset' => '1799999999',
        ])]);

        try {
            GitHubClient::fromConfig()->getRepo('acme', 'widgets');
            $this->fail('Expected RateLimitedException.');
        } catch (RateLimitedException $e) {
            $this->assertSame(1799999999, $e->resetsAt);
        }
    }

    public function test_plain_403_is_not_rate_limited(): void
    {
        Http::fake(['*' => Http::response(['message' => 'forbidden'], 403)]);

        try {
            GitHubClient::fromConfig()->getRepo('acme', 'widgets');
            $this->fail('Expected GitHubException.');
        } catch (GitHubException $e) {
            $this->assertSame(GitHubException::class, $e::class);
            $this->assertStringContainsString('403', $e->getMessage());
        }
    }

    public function test_404_maps_to_not_found(): void
    {
        Http::fake(['*' => Http::response(['message' => 'nope'], 404)]);

        $this->expectException(RepositoryNotFoundException::class);

        GitHubClient::fromConfig()->getRepo('acme', 'widgets');
    }

    public function test_500_stays_a_generic_error(): void
    {
        Http::fake(['*' => Http::response(['message' => 'boom'], 500)]);

        try {
            GitHubClient::fromConfig()->getRepo('acme', 'widgets');
            $this->fail('Expected GitHubException.');
        } catch (GitHubException $e) {
            $this->assertSame(GitHubException::class, $e::class);
            $this->assertStringContainsString('500', $e->getMessage());
        }
    }

    public function test_rate_limit_delay_math(): void
    {
        $this->assertSame(300, ImportRepositoryJob::rateLimitDelay(null));
        $this->assertSame(60, ImportRepositoryJob::rateLimitDelay(time() - 100));
        $this->assertSame(65, ImportRepositoryJob::rateLimitDelay(time() + 60));
        $this->assertSame(3600, ImportRepositoryJob::rateLimitDelay(time() + 100000));
    }

    public function test_rate_limited_job_releases_then_recovers_after_reset(): void
    {
        config(['revival.github.import_driver' => 'rest']);
        $this->useDatabaseQueue();
        $repository = $this->repository();

        $limited = true;

        $this->fakeGitHub(['README.md' => 'docs'], function () use (&$limited) {
            if (! $limited) {
                return null;
            }

            return Http::response(['message' => 'API rate limit exceeded'], 429, [
                'x-ratelimit-remaining' => '0',
                'x-ratelimit-reset' => (string) (time() + 3500),
            ]);
        });

        ImportRepositoryJob::dispatch($repository->id);
        $this->runWorkerOnce();

        // Released, not failed and not requeued hot: the wait honors the
        // upstream reset window (clamped to an hour).
        $this->assertSame(1, DB::table('jobs')->count());
        $availableAt = (int) DB::table('jobs')->value('available_at');
        $this->assertGreaterThanOrEqual(time() + 3000, $availableAt);

        $repository->refresh();
        $this->assertTrue($repository->isImporting());
        $this->assertSame('Rate limited; retrying automatically.', $repository->import_error);
        $this->assertNotNull($repository->next_retry_at);
        $this->assertEqualsWithDelta($availableAt, $repository->next_retry_at->timestamp, 10);
        $this->assertNotNull($repository->import_started_at);

        // Quota returns: the released job completes the import.
        $limited = false;
        DB::table('jobs')->update(['available_at' => time() - 1]);
        $this->runWorkerOnce();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());

        $repository->refresh();
        $this->assertTrue($repository->isReady());
        $this->assertSame(1, $repository->currentEvidence()->count());
        $this->assertNull($repository->next_retry_at);
        $this->assertNull($repository->import_started_at);
    }

    public function test_exhausted_retries_fail_without_losing_prior_evidence(): void
    {
        config(['revival.github.import_driver' => 'rest']);
        $this->useDatabaseQueue();
        $repository = $this->repository();

        $failing = false;

        $this->fakeGitHub(['README.md' => 'docs'], null, function () use (&$failing) {
            if ($failing) {
                return Http::response(['message' => 'boom'], 500);
            }

            return Http::response(['private' => false, 'default_branch' => 'main']);
        });

        app(GitHubRepositoryImporter::class)->import($repository);
        $this->assertSame(1, $repository->fresh()->currentEvidence()->count());

        $failing = true;

        ImportRepositoryJob::dispatch($repository->id);

        for ($i = 0; $i < 3; $i++) {
            DB::table('jobs')->update(['available_at' => time() - 1]);
            $this->runWorkerOnce();
        }

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());

        $repository->refresh();
        $this->assertSame('failed', $repository->import_status);
        $this->assertStringContainsString('status 500', (string) $repository->import_error);
        $this->assertNull($repository->next_retry_at);
        // The previous valid snapshot is untouched by the failed re-import.
        $this->assertSame(1, $repository->currentEvidence()->count());
        $this->assertSame(1, $repository->last_import_summary['files']);
    }

    public function test_sweep_fails_import_with_no_queued_job(): void
    {
        $repository = $this->repository();
        $this->fakeGitHub(['README.md' => 'docs']);
        app(GitHubRepositoryImporter::class)->import($repository);

        $repository->forceFill(['import_status' => 'importing', 'import_error' => null])->save();
        $this->stale($repository, 60);

        $this->assertSame(0, Artisan::call('revival:sweep-stuck-imports'));

        $repository->refresh();
        $this->assertSame('failed', $repository->import_status);
        $this->assertStringContainsString('stalled', (string) $repository->import_error);
        $this->assertStringContainsString('Re-import to retry', (string) $repository->import_error);
        $this->assertSame(1, $repository->currentEvidence()->count());
    }

    public function test_sweep_leaves_pending_and_scheduled_jobs_alone(): void
    {
        $this->useDatabaseQueue();

        $pending = $this->repository();
        $pending->forceFill(['import_status' => 'importing'])->save();
        $this->stale($pending, 20);
        ImportRepositoryJob::dispatch($pending->id);

        $scheduled = Repository::factory()->create([
            'owner' => 'acme',
            'name' => 'gadgets',
            'canonical_url' => 'https://github.com/acme/gadgets',
            'import_status' => 'importing',
        ]);
        $this->stale($scheduled, 40);
        ImportRepositoryJob::dispatch($scheduled->id);
        DB::table('jobs')->update(['available_at' => time() + 3600]);

        $this->assertSame(0, Artisan::call('revival:sweep-stuck-imports'));

        $this->assertTrue($pending->fresh()->isImporting());
        $this->assertTrue($scheduled->fresh()->isImporting());
    }

    public function test_sweep_fails_overdue_job_when_worker_is_missing(): void
    {
        $this->useDatabaseQueue();
        $repository = $this->repository();
        $repository->forceFill(['import_status' => 'importing'])->save();
        $this->stale($repository, 40);

        ImportRepositoryJob::dispatch($repository->id);
        DB::table('jobs')->update(['available_at' => time() - 1, 'reserved_at' => null]);

        $this->assertSame(0, Artisan::call('revival:sweep-stuck-imports'));

        $repository->refresh();
        $this->assertSame('failed', $repository->import_status);
        $this->assertStringContainsString('worker may be down', (string) $repository->import_error);
        // The queued job is kept: a starting worker still heals the import.
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_sweep_leaves_recently_reserved_job_alone(): void
    {
        $this->useDatabaseQueue();
        $repository = $this->repository();
        $repository->forceFill(['import_status' => 'importing'])->save();
        $this->stale($repository, 40);

        ImportRepositoryJob::dispatch($repository->id);
        DB::table('jobs')->update(['available_at' => time() - 1, 'reserved_at' => time()]);

        $this->assertSame(0, Artisan::call('revival:sweep-stuck-imports'));

        $this->assertTrue($repository->fresh()->isImporting());
    }

    public function test_sweep_ignores_ready_repositories_and_fresh_imports(): void
    {
        $repository = $this->repository();
        $this->fakeGitHub(['README.md' => 'docs']);
        app(GitHubRepositoryImporter::class)->import($repository);

        $fresh = Repository::factory()->create([
            'owner' => 'acme',
            'name' => 'fresh',
            'canonical_url' => 'https://github.com/acme/fresh',
            'import_status' => 'importing',
        ]);

        $this->assertSame(0, Artisan::call('revival:sweep-stuck-imports'));

        $this->assertTrue($repository->fresh()->isReady());
        $this->assertTrue($fresh->fresh()->isImporting());
    }

    public function test_sweep_skips_repositories_with_unparseable_job_payloads(): void
    {
        $repository = $this->repository();
        $repository->forceFill(['import_status' => 'importing'])->save();
        $this->stale($repository, 60);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['data' => [
                'commandName' => ImportRepositoryJob::class,
                'command' => 'not-a-serialized-job',
            ]]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        $this->assertSame(0, Artisan::call('revival:sweep-stuck-imports'));

        $this->assertTrue($repository->fresh()->isImporting());
    }

    public function test_evidence_page_shows_import_timing_outlook(): void
    {
        $repository = $this->repository();
        $repository->forceFill([
            'import_status' => 'importing',
            'import_started_at' => now()->subMinutes(5),
            'next_retry_at' => now()->addMinutes(10),
        ])->save();

        $this->actingAs($repository->project->owner)
            ->get(route('projects.evidence', $repository->project))
            ->assertOk()
            ->assertSee('Started', false)
            ->assertSee('Last activity', false)
            ->assertSee('Next retry', false);
    }
}
