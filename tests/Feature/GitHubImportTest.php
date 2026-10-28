<?php

namespace Tests\Feature;

use App\Jobs\ImportRepositoryJob;
use App\Livewire\Projects\EvidenceManager;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Services\GitHub\GitHubException;
use App\Services\GitHub\GitHubTimeoutException;
use App\Services\GitHub\RateLimitedException;
use App\Services\GitHub\RepositoryNotFoundException;
use App\Services\GitHub\RepositoryPrivateException;
use App\Services\Revival\EvidencePackage;
use App\Services\Revival\GitHubRepositoryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class GitHubImportTest extends TestCase
{
    use RefreshDatabase;

    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    private const SHA2 = 'abcdef0123456789abcdef0123456789abcdef01';

    /**
     * Fake the four GitHub endpoints used by the importer.
     *
     * @param  array<string, string>  $files  path => content
     */
    private function fakeGitHub(
        array $files,
        array $repo = [],
        string $sha = self::SHA,
        ?callable $contents = null,
    ): void {
        Http::fake(function (Request $request) use ($files, $repo, $sha, $contents) {
            $url = strtok($request->url(), '?');

            if (preg_match('#/repos/[^/]+/[^/]+$#', $url)) {
                return Http::response(array_merge([
                    'private' => false,
                    'default_branch' => 'main',
                    'description' => 'Fake repo',
                    'language' => 'Python',
                    'size' => 10,
                    'stargazers_count' => 0,
                    'forks_count' => 0,
                    'open_issues_count' => 0,
                    'pushed_at' => '2026-01-01T00:00:00Z',
                ], $repo));
            }

            if (str_contains($url, '/git/ref/heads/')) {
                return Http::response(['object' => ['sha' => $sha]]);
            }

            if (str_contains($url, '/git/trees/')) {
                $tree = [];

                foreach ($files as $path => $content) {
                    $tree[] = ['path' => $path, 'type' => 'blob', 'size' => strlen($content)];
                }

                return Http::response(['sha' => $sha, 'truncated' => false, 'tree' => $tree]);
            }

            if (str_contains($url, '/contents/')) {
                $path = substr($url, strpos($url, '/contents/') + strlen('/contents/'));
                $path = implode('/', array_map(rawurldecode(...), explode('/', $path)));

                if ($contents !== null) {
                    return $contents($path);
                }

                $content = $files[$path] ?? '';

                return Http::response([
                    'encoding' => 'base64',
                    'content' => base64_encode($content),
                    'html_url' => "https://github.com/acme/widgets/blob/{$sha}/{$path}",
                    'size' => strlen($content),
                ]);
            }

            return Http::response(null, 404);
        });
    }

    private function repository(): Repository
    {
        return Repository::factory()->create([
            'owner' => 'acme',
            'name' => 'widgets',
            'canonical_url' => 'https://github.com/acme/widgets',
        ]);
    }

    public function test_successful_import_persists_manifest(): void
    {
        $this->fakeGitHub([
            'README.md' => "# Widgets\n\nDoes things.\n",
            'docs/guide.md' => 'Guide text.',
            'tools/app.py' => "print('hi')\n",
        ]);

        $repository = $this->repository();

        $result = app(GitHubRepositoryImporter::class)->import($repository);

        $this->assertFalse($result['up_to_date']);
        $this->assertCount(3, $result['manifest']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame([], $result['warnings']);

        $repository->refresh();
        $this->assertTrue($repository->isReady());
        $this->assertSame(self::SHA, $repository->last_commit_sha);
        $this->assertSame(3, $repository->currentEvidence()->count());

        $readme = $repository->currentEvidence()->where('path', 'README.md')->firstOrFail();
        $this->assertSame(hash('sha256', "# Widgets\n\nDoes things.\n"), $readme->content_hash);
        $this->assertNotNull($readme->fetched_at);
        $this->assertSame(3, $repository->last_import_summary['files']);

        // Outbound calls stay on the allowlisted host.
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('api.github.com', parse_url($request->url(), PHP_URL_HOST));
        }
    }

    public function test_reimport_at_same_commit_is_idempotent(): void
    {
        $this->fakeGitHub(['README.md' => 'v1']);
        $repository = $this->repository();
        $importer = app(GitHubRepositoryImporter::class);

        $importer->import($repository);
        $result = $importer->import($repository->fresh());

        $this->assertTrue($result['up_to_date']);
        $this->assertSame(1, $repository->evidenceSources()->count());
    }

    public function test_new_commit_creates_new_versions_and_keeps_history(): void
    {
        $repository = $this->repository();
        $importer = app(GitHubRepositoryImporter::class);

        // One stateful fake: first import sees SHA/v1, second sees SHA2/v2.
        // (Http::fake stubs merge, so a second fake() call would be shadowed.)
        $imports = 0;
        Http::fake(function (Request $request) use (&$imports) {
            $url = strtok($request->url(), '?');

            if (preg_match('#/repos/[^/]+/[^/]+$#', $url)) {
                return Http::response(['private' => false, 'default_branch' => 'main']);
            }

            if (str_contains($url, '/git/ref/heads/')) {
                return Http::response(['object' => ['sha' => $imports === 0 ? self::SHA : self::SHA2]]);
            }

            if (str_contains($url, '/git/trees/')) {
                $imports++;

                return Http::response([
                    'sha' => $imports === 1 ? self::SHA : self::SHA2,
                    'truncated' => false,
                    'tree' => [['path' => 'README.md', 'type' => 'blob', 'size' => 2]],
                ]);
            }

            $content = $imports === 1 ? 'v1' : 'v2';

            return Http::response([
                'encoding' => 'base64',
                'content' => base64_encode($content),
                'html_url' => 'https://github.com/acme/widgets/blob/x/README.md',
                'size' => strlen($content),
            ]);
        });

        $importer->import($repository);
        $result = $importer->import($repository->fresh());

        $this->assertFalse($result['up_to_date']);
        $this->assertSame(2, $repository->evidenceSources()->count());
        $this->assertSame(1, $repository->fresh()->currentEvidence()->count());
        $this->assertSame('v2', $repository->fresh()->currentEvidence()->first()->content);
    }

    public function test_supports_html_partial_extraction_and_skips_binary(): void
    {
        $repository = $this->repository();

        config(['revival.github.max_extraction_bytes' => 100]);
        $this->fakeGitHub([
            'README.md' => 'ok',
            'app/viewer.html' => '<html></html>',
            'tools/blob.py' => "a\0b",
            'docs/big.md' => str_repeat('x', 200),
            'assets/logo.png' => 'binary',
        ]);

        $result = app(GitHubRepositoryImporter::class)->import($repository);

        // HTML is supported evidence; the oversized doc contributes a partial
        // excerpt instead of being skipped outright.
        $this->assertCount(3, $result['manifest']);

        $big = $repository->currentEvidence()->where('path', 'docs/big.md')->firstOrFail();
        $this->assertTrue($big->isPartial());
        $this->assertSame([['start' => 1, 'end' => 1]], $big->line_ranges);
        $this->assertSame(200, $big->original_bytes);

        $reasons = collect($result['skipped'])->mapWithKeys(fn ($s) => [$s['path'] => $s['reason']]);
        $this->assertSame('binary content', $reasons['tools/blob.py']);
        $this->assertSame('unsupported format', $reasons['assets/logo.png']);
        $this->assertCount(2, $repository->last_import_summary['skipped']);

        // Inventory still records every discovered blob.
        $this->assertSame(5, $repository->currentInventory()->count());
        $this->assertSame(5, $repository->last_import_summary['inventory']['blobs']);
    }

    public function test_empty_selection_records_warning(): void
    {
        $this->fakeGitHub(['image.png' => 'binary']);
        $repository = $this->repository();

        $result = app(GitHubRepositoryImporter::class)->import($repository);

        $this->assertSame([], $result['manifest']);
        $this->assertNotSame([], $result['warnings']);
        $this->assertTrue($repository->fresh()->isReady());
    }

    public function test_404_maps_to_not_found(): void
    {
        Http::fake(['*' => Http::response(null, 404)]);

        $this->expectException(RepositoryNotFoundException::class);

        app(GitHubRepositoryImporter::class)->import($this->repository());
    }

    public function test_private_repository_is_rejected(): void
    {
        $this->fakeGitHub([], ['private' => true]);

        $this->expectException(RepositoryPrivateException::class);

        app(GitHubRepositoryImporter::class)->import($this->repository());
    }

    public function test_rate_limit_maps_with_reset_time(): void
    {
        Http::fake(['*' => Http::response(
            ['message' => 'API rate limit exceeded'],
            429,
            ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '1799999999']
        )]);

        try {
            app(GitHubRepositoryImporter::class)->import($this->repository());
            $this->fail('Expected RateLimitedException.');
        } catch (RateLimitedException $e) {
            $this->assertSame(1799999999, $e->resetsAt);
        }
    }

    public function test_redirect_is_rejected(): void
    {
        Http::fake(['*' => Http::response(null, 302, ['Location' => 'https://evil.test/x'])]);

        $this->expectException(GitHubException::class);
        $this->expectExceptionMessage('Redirect to untrusted destination rejected.');

        app(GitHubRepositoryImporter::class)->import($this->repository());
    }

    public function test_timeout_maps_to_timeout_exception(): void
    {
        Http::fake(function () {
            throw new ConnectionException('timed out');
        });

        $this->expectException(GitHubTimeoutException::class);

        app(GitHubRepositoryImporter::class)->import($this->repository());
    }

    public function test_job_marks_repository_failed_on_not_found(): void
    {
        Http::fake(['*' => Http::response(null, 404)]);
        $repository = $this->repository();

        ImportRepositoryJob::dispatch($repository->id)->onConnection('database');

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default']);

        $this->assertSame('failed', $repository->fresh()->import_status);
        $this->assertNotNull($repository->fresh()->import_error);
    }

    public function test_job_processes_successful_import(): void
    {
        config(['revival.github.import_driver' => 'rest']);
        $this->fakeGitHub(['README.md' => 'queued']);
        $repository = $this->repository();

        ImportRepositoryJob::dispatch($repository->id)->onConnection('database');

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default']);

        $this->assertTrue($repository->fresh()->isReady());
        $this->assertSame(1, $repository->fresh()->currentEvidence()->count());
    }

    public function test_evidence_package_shape_for_wp3(): void
    {
        $this->fakeGitHub(['README.md' => 'package me']);
        $repository = $this->repository();
        app(GitHubRepositoryImporter::class)->import($repository);

        $package = EvidencePackage::forRepository($repository->fresh());

        $this->assertSame('https://github.com/acme/widgets', $package['repository']['canonical_url']);
        $this->assertSame(self::SHA, $package['repository']['commit_sha']);
        $this->assertCount(1, $package['sources']);
        $this->assertSame(
            ['source_id', 'path', 'url', 'commit_sha', 'content_hash', 'content'],
            array_keys($package['sources'][0])
        );
    }

    public function test_same_repo_stays_isolated_between_projects(): void
    {
        $this->fakeGitHub(['README.md' => 'shared']);
        $importer = app(GitHubRepositoryImporter::class);

        $repoA = Repository::factory()->create([
            'owner' => 'acme', 'name' => 'widgets',
            'canonical_url' => 'https://github.com/acme/widgets',
        ]);
        $repoB = Repository::factory()->create([
            'owner' => 'acme', 'name' => 'widgets',
            'canonical_url' => 'https://github.com/acme/widgets',
        ]);

        $importer->import($repoA);
        $importer->import($repoB);

        $this->assertNotSame($repoA->id, $repoB->id);
        $this->assertSame(1, $repoA->fresh()->currentEvidence()->count());
        $this->assertSame(1, $repoB->fresh()->currentEvidence()->count());
        $this->assertNotSame(
            $repoA->fresh()->currentEvidence()->first()->id,
            $repoB->fresh()->currentEvidence()->first()->id
        );
    }

    public function test_evidence_page_requires_maintainer(): void
    {
        $project = Project::factory()->create();
        $owner = $project->owner;
        $stranger = User::factory()->create();

        $this->get(route('projects.evidence', $project))->assertRedirect(route('login'));
        $this->actingAs($stranger)->get(route('projects.evidence', $project))->assertForbidden();
        $this->actingAs($owner)->get(route('projects.evidence', $project))->assertOk();
    }

    public function test_import_action_validates_url_and_dispatches_job(): void
    {
        Queue::fake();
        $project = Project::factory()->create();

        Livewire::actingAs($project->owner)
            ->test(EvidenceManager::class, ['project' => $project])
            ->set('url', 'https://gitlab.com/acme/widgets')
            ->call('import')
            ->assertHasErrors('url');

        Queue::assertNothingPushed();

        Livewire::actingAs($project->owner)
            ->test(EvidenceManager::class, ['project' => $project])
            ->set('url', 'https://github.com/acme/widgets/tree/main')
            ->call('import')
            ->assertHasNoErrors();

        Queue::assertPushed(ImportRepositoryJob::class);
        $this->assertDatabaseHas('repositories', [
            'project_id' => $project->id,
            'canonical_url' => 'https://github.com/acme/widgets',
            'import_status' => 'importing',
        ]);
    }

    public function test_import_action_forbids_strangers(): void
    {
        $project = Project::factory()->create();
        $stranger = User::factory()->create();

        Livewire::actingAs($stranger)
            ->test(EvidenceManager::class, ['project' => $project])
            ->set('url', 'https://github.com/acme/widgets')
            ->call('import')
            ->assertForbidden();
    }

    public function test_import_action_is_throttled(): void
    {
        config(['revival.import_rate_limit.max_attempts' => 1]);
        Queue::fake();
        $project = Project::factory()->create();

        Livewire::actingAs($project->owner)
            ->test(EvidenceManager::class, ['project' => $project])
            ->set('url', 'https://github.com/acme/widgets')
            ->call('import')
            ->assertHasNoErrors();

        Livewire::actingAs($project->owner)
            ->test(EvidenceManager::class, ['project' => $project])
            ->set('url', 'https://github.com/acme/gadgets')
            ->call('import')
            ->assertHasErrors('url')
            ->assertSee('Too many imports');

        Queue::assertPushed(ImportRepositoryJob::class, 1);
    }
}
