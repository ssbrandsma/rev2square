<?php

namespace Tests\Feature;

use App\Livewire\Projects\EvidenceManager;
use App\Models\Repository;
use App\Services\GitHub\RateLimitedException;
use App\Services\Revival\GitHubRepositoryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ImportRobustnessTest extends TestCase
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

    /** @param  array<string, string>  $files */
    private function fakeGitHub(array $files, ?callable $contents = null): void
    {
        Http::fake(function (Request $request) use ($files, $contents) {
            $url = strtok($request->url(), '?');

            if (preg_match('#/repos/[^/]+/[^/]+$#', $url)) {
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

                if ($contents !== null) {
                    return $contents($path);
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

    public function test_truncated_tree_falls_back_to_per_subtree_recovery(): void
    {
        $subSha = str_repeat('2', 40);

        Http::fake(function (Request $request) use ($subSha) {
            $url = strtok($request->url(), '?');
            $full = $request->url();

            if (preg_match('#/repos/[^/]+/[^/]+$#', $url)) {
                return Http::response(['private' => false, 'default_branch' => 'main']);
            }

            if (str_contains($url, '/git/ref/heads/')) {
                return Http::response(['object' => ['sha' => self::SHA]]);
            }

            if (str_contains($url, '/git/trees/'.$subSha)) {
                return Http::response([
                    'sha' => $subSha,
                    'truncated' => false,
                    'tree' => [
                        ['path' => 'driver.c', 'type' => 'blob', 'size' => 10, 'sha' => sha1('driver')],
                    ],
                ]);
            }

            if (str_contains($url, '/git/trees/')) {
                if (str_contains($full, 'recursive=1')) {
                    return Http::response([
                        'sha' => self::SHA,
                        'truncated' => true,
                        'tree' => [
                            ['path' => 'README.md', 'type' => 'blob', 'size' => 4],
                        ],
                    ]);
                }

                return Http::response([
                    'sha' => self::SHA,
                    'truncated' => false,
                    'tree' => [
                        ['path' => 'README.md', 'type' => 'blob', 'size' => 4],
                        ['path' => 'tools', 'type' => 'tree', 'sha' => $subSha],
                    ],
                ]);
            }

            if (str_contains($url, '/contents/')) {
                $path = substr($url, strpos($url, '/contents/') + strlen('/contents/'));
                $path = implode('/', array_map(rawurldecode(...), explode('/', $path)));
                $content = $path === 'README.md' ? 'docs' : 'int main;';

                return Http::response([
                    'encoding' => 'base64',
                    'content' => base64_encode($content),
                    'html_url' => 'https://github.com/acme/widgets/blob/'.self::SHA.'/'.$path,
                    'size' => strlen($content),
                ]);
            }

            return Http::response(null, 404);
        });

        $repository = $this->repository();
        $result = app(GitHubRepositoryImporter::class)->import($repository);

        $this->assertFalse($result['up_to_date']);
        $this->assertSame(
            ['README.md', 'tools/driver.c'],
            $repository->currentInventory()->orderBy('path')->pluck('path')->all()
        );
        $this->assertSame(
            ['README.md', 'tools/driver.c'],
            array_column($result['manifest'], 'path')
        );
        $this->assertStringContainsString('truncated', implode(' ', $result['warnings']));
    }

    public function test_rate_limit_during_fetch_bubbles_for_job_retry(): void
    {
        $this->fakeGitHub(['README.md' => 'docs'], function () {
            return Http::response(['message' => 'API rate limit exceeded'], 429, [
                'x-ratelimit-remaining' => '0',
                'x-ratelimit-reset' => '1799999999',
            ]);
        });

        try {
            app(GitHubRepositoryImporter::class)->import($this->repository());
            $this->fail('Expected RateLimitedException.');
        } catch (RateLimitedException $e) {
            $this->assertSame(1799999999, $e->resetsAt);
        }
    }

    public function test_single_download_failure_does_not_abort_import(): void
    {
        $this->fakeGitHub(['README.md' => 'docs', 'app/main.py' => 'code'], function (string $path) {
            if ($path === 'app/main.py') {
                return Http::response(['message' => 'boom'], 500);
            }

            return Http::response([
                'encoding' => 'base64',
                'content' => base64_encode('docs'),
                'html_url' => 'https://github.com/acme/widgets/blob/x/README.md',
                'size' => 4,
            ]);
        });

        $repository = $this->repository();
        $result = app(GitHubRepositoryImporter::class)->import($repository);

        $this->assertTrue($repository->fresh()->isReady());
        $this->assertCount(1, $result['manifest']);

        $reasons = collect($result['skipped'])->mapWithKeys(fn ($s) => [$s['path'] => $s['reason']]);
        $this->assertSame('download failed', $reasons['app/main.py']);
    }

    public function test_unsafe_paths_are_inventoried_but_never_fetched(): void
    {
        $this->fakeGitHub(['README.md' => 'docs', '../evil.c' => 'x', '/abs.c' => 'y']);

        $repository = $this->repository();
        $result = app(GitHubRepositoryImporter::class)->import($repository);

        $reasons = collect($result['skipped'])->mapWithKeys(fn ($s) => [$s['path'] => $s['reason']]);
        $this->assertSame('unsafe path', $reasons['../evil.c']);
        $this->assertSame('unsafe path', $reasons['/abs.c']);

        foreach (Http::recorded() as [$request]) {
            $this->assertStringNotContainsString('/contents/..', $request->url());
        }
    }

    public function test_inventory_cap_warns_and_stays_deterministic(): void
    {
        config(['revival.github.max_inventory_entries' => 2]);
        $this->fakeGitHub(['c.c' => 'c', 'a.c' => 'a', 'b.c' => 'b']);

        $repository = $this->repository();
        $result = app(GitHubRepositoryImporter::class)->import($repository);

        $this->assertTrue($result['inventory']['capped']);
        $this->assertSame(1, $result['inventory']['over_cap']);
        $this->assertStringContainsString('capped', implode(' ', $result['warnings']));
        // Deterministic: first two paths win regardless of traversal order.
        $this->assertSame(['a.c', 'b.c'], array_column($result['manifest'], 'path'));
    }

    public function test_second_pass_refuses_ineligible_paths(): void
    {
        $this->fakeGitHub(['README.md' => 'docs', '.env' => 'SECRET=x']);
        $repository = $this->repository();
        $importer = app(GitHubRepositoryImporter::class);

        $importer->import($repository);

        $result = $importer->fetchAdditionalPaths($repository->fresh(), ['.env']);

        $this->assertSame([], $result['stored']);
        $this->assertSame('possible secret — excluded from evidence', $result['skipped'][0]['reason']);
    }

    public function test_fetch_missing_action_stores_relevant_file(): void
    {
        config(['revival.github.max_files' => 1]);
        $this->fakeGitHub(['README.md' => 'docs', 'app/main.py' => 'code']);

        $repository = $this->repository();
        app(GitHubRepositoryImporter::class)->import($repository);
        $repository->refresh();

        $this->assertCount(1, $repository->currentEvidence);
        $missing = $repository->last_import_summary['missing_relevant'];
        $this->assertNotEmpty($missing);

        Livewire::actingAs($repository->project->owner)
            ->test(EvidenceManager::class, ['project' => $repository->project])
            ->call('fetchMissing', $missing[0]['path'])
            ->assertHasNoErrors();

        $this->assertSame(2, $repository->fresh()->currentEvidence()->count());
    }

    public function test_evidence_page_shows_coverage_and_exclusions(): void
    {
        config(['revival.github.max_files' => 1]);
        $this->fakeGitHub(['README.md' => 'docs', 'app/main.py' => 'code']);

        $repository = $this->repository();
        app(GitHubRepositoryImporter::class)->import($repository);

        $this->actingAs($repository->project->owner)
            ->get(route('projects.evidence', $repository->project))
            ->assertOk()
            ->assertSee('Discovered', false)
            ->assertSee('Subsystem coverage', false)
            ->assertSee('Excluded files', false)
            ->assertSee('low relevance', false)
            ->assertSee('Relevant files not imported', false);
    }
}
