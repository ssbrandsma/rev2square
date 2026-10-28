<?php

namespace Tests\Feature;

use App\Jobs\ImportRepositoryJob;
use App\Models\AnalysisRun;
use App\Models\Repository;
use App\Services\GitHub\ArchiveDownloader;
use App\Services\GitHub\ArchiveInvalidException;
use App\Services\GitHub\GitHubException;
use App\Services\GitHub\RateLimitedException;
use App\Services\Revival\AnalysisRunRepository;
use App\Services\Revival\ArchiveRepositoryImporter;
use App\Services\Revival\GitHubRepositoryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ArchiveImportTest extends TestCase
{
    use RefreshDatabase;

    private const SHA1 = '0123456789abcdef0123456789abcdef01234567';

    private const SHA2 = 'abcdef0123456789abcdef0123456789abcdef01';

    private function repository(): Repository
    {
        return Repository::factory()->create([
            'owner' => 'acme',
            'name' => 'widgets',
            'canonical_url' => 'https://github.com/acme/widgets',
        ]);
    }

    /**
     * Build codeload-shaped archive bytes: every entry under "<repo>-<sha>/".
     *
     * @param  array<string, string>  $files  path => content
     * @param  array<string, string>  $symlinks  path => target
     */
    private function buildZip(array $files, string $sha = self::SHA1, array $symlinks = [], bool $duplicateFirst = false): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'arc').'.zip';
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $prefix = 'widgets-'.substr($sha, 0, 7).'/';

        foreach ($files as $path => $content) {
            $zip->addFromString($prefix.$path, $content);
        }

        if ($duplicateFirst && $files !== []) {
            // Equivalent spelling of the first path: ZipArchive stores both
            // entries, and inspection must dedupe them to one.
            $first = array_key_first($files);
            $zip->addFromString($prefix.'./'.$first, 'second copy wins nothing');
        }

        foreach ($symlinks as $path => $target) {
            $zip->addFromString($prefix.$path, $target);
            $zip->setExternalAttributesName($prefix.$path, ZipArchive::OPSYS_UNIX, (0120000 | 0777) << 16);
        }

        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        unlink($tmp);

        return $bytes;
    }

    /**
     * Single stateful fake per test (Http::fake merges stubs): metadata plus
     * archive endpoints, with per-endpoint overrides for failure phases.
     *
     * @param  array<string, string>  $zips  sha => archive bytes
     */
    private function fakeArchiveGitHub(array $zips, ?string $headSha, ?callable $meta = null, ?callable $archive = null, ?callable $contents = null): void
    {
        Http::fake(function (Request $request) use ($zips, $headSha, $meta, $archive, $contents) {
            $url = strtok($request->url(), '?');

            if (str_contains($url, '/archive/')) {
                if ($archive !== null) {
                    return $archive($url);
                }

                foreach ($zips as $sha => $bytes) {
                    if (str_ends_with($url, "/archive/{$sha}.zip")) {
                        return Http::response($bytes, 200, ['Content-Type' => 'application/zip']);
                    }
                }

                return Http::response(['message' => 'nope'], 404);
            }

            if (str_contains($url, 'codeload.github.com')) {
                if ($archive !== null) {
                    return $archive($url);
                }

                return Http::response(reset($zips), 200, ['Content-Type' => 'application/zip']);
            }

            if (preg_match('#/repos/[^/]+/[^/]+$#', $url)) {
                if ($meta !== null && ($override = $meta('repo')) !== null) {
                    return $override;
                }

                return Http::response(['private' => false, 'default_branch' => 'main']);
            }

            if (str_contains($url, '/git/ref/heads/')) {
                if ($meta !== null && ($override = $meta('ref')) !== null) {
                    return $override;
                }

                return Http::response(['object' => ['sha' => $headSha]]);
            }

            if (str_contains($url, '/contents/')) {
                if ($contents !== null) {
                    return $contents($url);
                }

                return Http::response(null, 404);
            }

            return Http::response(null, 404);
        });
    }

    /** @return list<string> */
    private function recordedUrls(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0]->url())->all();
    }

    private function refreshArchiveSingletons(): void
    {
        app()->forgetInstance(ArchiveDownloader::class);
        app()->forgetInstance(ArchiveRepositoryImporter::class);
    }

    public function test_archive_import_makes_no_per_file_requests(): void
    {
        Storage::fake('local');
        $zip = $this->buildZip(['README.md' => 'docs', 'app/main.py' => 'code']);
        $this->fakeArchiveGitHub([self::SHA1 => $zip], self::SHA1);

        $repository = $this->repository();
        $result = app(ArchiveRepositoryImporter::class)->import($repository);

        $this->assertFalse($result['up_to_date']);
        $this->assertTrue($repository->fresh()->isReady());

        // Instrumented request count: two metadata calls plus one archive
        // download, and nothing per file.
        $urls = $this->recordedUrls();
        $this->assertCount(3, $urls);

        foreach ($urls as $url) {
            $this->assertStringNotContainsString('/contents/', $url);
        }

        $this->assertSame(2, $repository->currentEvidence()->count());
        $this->assertSame('docs', $repository->currentEvidence()->where('path', 'README.md')->firstOrFail()->content);

        $summary = $repository->fresh()->last_import_summary;
        $this->assertSame('archive', $summary['retrieval']['method']);
        $this->assertSame('branch-head', $summary['retrieval']['resolution']);
        $this->assertSame('main', $summary['retrieval']['requested_ref']);
        $this->assertSame(self::SHA1, $summary['retrieval']['resolved_sha']);
        $this->assertSame(hash('sha256', $zip), $summary['retrieval']['archive_sha256']);
        $this->assertFalse($summary['retrieval']['archive_cached']);

        // Commit-pinned provenance on every citation.
        foreach ($result['manifest'] as $entry) {
            $this->assertStringContainsString('/blob/'.self::SHA1.'/', $entry['url']);
            $this->assertSame(self::SHA1, $entry['commit_sha']);
            $this->assertNotEmpty($entry['content_hash']);
        }

        // Nothing extracted beside the cached artifact and its sidecar.
        $this->assertSame(
            ['import-archives/acme/widgets/'.self::SHA1.'.zip', 'import-archives/acme/widgets/'.self::SHA1.'.zip.json'],
            collect(Storage::disk('local')->allFiles('import-archives'))->sort()->values()->all()
        );
    }

    public function test_cached_rebuild_with_known_sha_makes_no_requests(): void
    {
        Storage::fake('local');
        $zip = $this->buildZip(['README.md' => 'docs', 'app/main.py' => 'code']);

        $deny = false;
        $this->fakeArchiveGitHub([self::SHA1 => $zip], self::SHA1, function () use (&$deny) {
            if ($deny) {
                throw new \RuntimeException('network disabled');
            }

            return null;
        }, function () use (&$deny, $zip) {
            if ($deny) {
                throw new \RuntimeException('network disabled');
            }

            return Http::response($zip, 200, ['Content-Type' => 'application/zip']);
        });

        $repository = $this->repository();
        app(ArchiveRepositoryImporter::class)->import($repository);
        $this->assertCount(3, $this->recordedUrls());

        // Changed budgets force a rebuild rather than the up-to-date no-op.
        config(['revival.github.max_files' => 5]);
        $deny = true;

        $result = app(ArchiveRepositoryImporter::class)->import($repository->fresh(), self::SHA1);

        $this->assertFalse($result['up_to_date']);
        $this->assertCount(3, $this->recordedUrls());
        $this->assertTrue($repository->fresh()->last_import_summary['retrieval']['archive_cached']);
        $this->assertSame(2, $repository->fresh()->currentEvidence()->count());
    }

    public function test_known_sha_works_with_rest_down_but_archive_up(): void
    {
        Storage::fake('local');
        $zip = $this->buildZip(['README.md' => 'docs']);
        $this->fakeArchiveGitHub([self::SHA1 => $zip], self::SHA1, function () {
            return Http::response(['message' => 'boom'], 500);
        });

        $repository = $this->repository();
        app(ArchiveRepositoryImporter::class)->import($repository, self::SHA1);

        $this->assertTrue($repository->fresh()->isReady());

        foreach ($this->recordedUrls() as $url) {
            $this->assertStringContainsString('/archive/', $url);
        }
    }

    public function test_unresolved_ref_with_dead_metadata_makes_no_archive_request(): void
    {
        Storage::fake('local');
        $zip = $this->buildZip(['README.md' => 'docs']);
        $this->fakeArchiveGitHub([self::SHA1 => $zip], self::SHA1, function () {
            return Http::response(['message' => 'boom'], 500);
        });

        try {
            app(ArchiveRepositoryImporter::class)->import($this->repository(), 'main');
            $this->fail('Expected GitHubException.');
        } catch (GitHubException $e) {
            $this->assertStringContainsString('500', $e->getMessage());
        }

        foreach ($this->recordedUrls() as $url) {
            $this->assertStringNotContainsString('/archive/', $url);
        }
    }

    public function test_rate_limit_during_resolution_reports_its_stage(): void
    {
        Storage::fake('local');
        $zip = $this->buildZip(['README.md' => 'docs']);
        $this->fakeArchiveGitHub([self::SHA1 => $zip], self::SHA1, function () {
            return Http::response(['message' => 'slow'], 429, ['retry-after' => '30']);
        });

        $repository = $this->repository();

        try {
            app(ArchiveRepositoryImporter::class)->import($repository);
            $this->fail('Expected RateLimitedException.');
        } catch (RateLimitedException $e) {
            $this->assertEqualsWithDelta(time() + 30, $e->resetsAt, 5);
        }

        $this->assertSame('resolving revision', $repository->fresh()->import_stage);
    }

    public function test_rate_limit_during_download_reports_its_stage(): void
    {
        Storage::fake('local');
        $zip = $this->buildZip(['README.md' => 'docs']);
        $this->fakeArchiveGitHub([self::SHA1 => $zip], self::SHA1, null, function () {
            return Http::response(['message' => 'slow'], 429, ['retry-after' => '45']);
        });

        $repository = $this->repository();

        try {
            app(ArchiveRepositoryImporter::class)->import($repository);
            $this->fail('Expected RateLimitedException.');
        } catch (RateLimitedException $e) {
            $this->assertEqualsWithDelta(time() + 45, $e->resetsAt, 5);
            $this->assertStringContainsString('download', $e->getMessage());
        }

        $this->assertSame('downloading archive', $repository->fresh()->import_stage);
    }

    public function test_archive_redirect_to_codeload_is_followed(): void
    {
        Storage::fake('local');
        $zip = $this->buildZip(['README.md' => 'docs']);
        $redirected = false;

        $this->fakeArchiveGitHub([self::SHA1 => $zip], self::SHA1, null, function (string $url) use (&$redirected, $zip) {
            if (str_contains($url, 'codeload.github.com')) {
                return Http::response($zip, 200, ['Content-Type' => 'application/zip']);
            }

            $redirected = true;

            return Http::response(null, 302, ['Location' => 'https://codeload.github.com/acme/widgets/zip/'.self::SHA1]);
        });

        $repository = $this->repository();
        app(ArchiveRepositoryImporter::class)->import($repository);

        $this->assertTrue($redirected);
        $this->assertTrue($repository->fresh()->isReady());
        $this->assertSame(2 + 2, count($this->recordedUrls()));
    }

    public function test_archive_redirect_elsewhere_is_rejected(): void
    {
        Storage::fake('local');
        $zip = $this->buildZip(['README.md' => 'docs']);
        $this->fakeArchiveGitHub([self::SHA1 => $zip], self::SHA1, null, function () {
            return Http::response(null, 302, ['Location' => 'https://evil.example/oops.zip']);
        });

        $this->expectException(GitHubException::class);

        try {
            app(ArchiveRepositoryImporter::class)->import($this->repository());
        } finally {
            foreach ($this->recordedUrls() as $url) {
                $this->assertStringNotContainsString('evil.example', $url);
            }
        }
    }

    public function test_corrupt_archive_fails_terminally_without_retry(): void
    {
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $this->fakeArchiveGitHub([self::SHA1 => 'not a zip at all'], self::SHA1);

        $repository = $this->repository();
        ImportRepositoryJob::dispatch($repository->id);
        Artisan::call('queue:work', ['--once' => true, '--sleep' => 0]);

        // Immediate terminal failure: the same revision would download the
        // same bytes, so no attempt is spent retrying.
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());

        $repository->refresh();
        $this->assertSame('failed', $repository->import_status);
        $this->assertStringContainsString('malformed', (string) $repository->import_error);
        $this->assertNull($repository->import_stage);
    }

    public function test_oversized_archives_are_rejected(): void
    {
        Storage::fake('local');
        $zip = $this->buildZip(['README.md' => str_repeat('x', 5000)]);
        $this->fakeArchiveGitHub([self::SHA1 => $zip], self::SHA1);

        config(['revival.github.max_archive_bytes' => 10]);

        try {
            app(ArchiveRepositoryImporter::class)->import($this->repository());
            $this->fail('Expected ArchiveInvalidException.');
        } catch (ArchiveInvalidException $e) {
            $this->assertStringContainsString('budget', $e->getMessage());
        }

        config(['revival.github.max_archive_bytes' => 100000000, 'revival.github.max_expanded_bytes' => 2]);
        $this->refreshArchiveSingletons();

        try {
            app(ArchiveRepositoryImporter::class)->import($this->repository());
            $this->fail('Expected ArchiveInvalidException.');
        } catch (ArchiveInvalidException $e) {
            $this->assertStringContainsString('expands', $e->getMessage());
        }

        config(['revival.github.max_expanded_bytes' => 512000000, 'revival.github.max_archive_ratio' => 1]);
        $this->refreshArchiveSingletons();

        try {
            app(ArchiveRepositoryImporter::class)->import($this->repository());
            $this->fail('Expected ArchiveInvalidException.');
        } catch (ArchiveInvalidException $e) {
            $this->assertStringContainsString('compression ratio', $e->getMessage());
        }
    }

    public function test_unsafe_and_special_entries_get_explicit_reasons(): void
    {
        Storage::fake('local');
        $zip = $this->buildZip(
            [
                'README.md' => 'docs',
                'dup.md' => 'first wins',
                '../evil.md' => 'x',
                '/abs.md' => 'y',
                '.gitmodules' => "[submodule \"lib\"]\n\tpath = vendor/lib\n",
                'vendor/lib/code.md' => 'external',
                'model.txt' => "version https://git-lfs.github.com/spec/v1\noid sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\nsize 12\n",
                'empty.md' => '',
                'bin.md' => "a\0b",
            ],
            self::SHA1,
            ['link.md' => 'README.md'],
            true
        );
        $this->fakeArchiveGitHub([self::SHA1 => $zip], self::SHA1);

        $repository = $this->repository();
        $result = app(ArchiveRepositoryImporter::class)->import($repository);

        $this->assertTrue($repository->fresh()->isReady());

        $reasons = collect($result['skipped'])->mapWithKeys(fn ($s) => [$s['path'] => $s['reason']]);
        $this->assertSame('unsafe path', $reasons['../evil.md']);
        $this->assertSame('unsafe path', $reasons['/abs.md']);
        $this->assertSame('symlink not followed', $reasons['link.md']);
        $this->assertSame('submodule content not included', $reasons['vendor/lib/code.md']);
        $this->assertSame('lfs pointer content not stored', $reasons['model.txt']);
        $this->assertSame('empty file', $reasons['empty.md']);
        $this->assertSame('binary content', $reasons['bin.md']);

        // The first duplicate still imports exactly once; the stored-path
        // invariant drops its skip row, so the warning carries the record.
        $this->assertSame('first wins', $repository->currentEvidence()->where('path', 'dup.md')->firstOrFail()->content);
        $this->assertArrayNotHasKey('dup.md', $reasons);
        $this->assertStringContainsString('Duplicate archive entries ignored', implode(' ', $result['warnings']));
        $this->assertStringContainsString('Submodules are not expanded', implode(' ', $result['warnings']));

        // None of these are fetchable, so they stay out of missing_relevant.
        $missing = array_column($repository->fresh()->last_import_summary['missing_relevant'] ?? [], 'path');

        foreach (['../evil.md', '/abs.md', 'dup.md', 'link.md', 'vendor/lib/code.md', 'model.txt'] as $path) {
            $this->assertNotContains($path, $missing);
        }
    }

    public function test_same_sha_reimport_is_idempotent(): void
    {
        Storage::fake('local');
        $zip = $this->buildZip(['README.md' => 'docs']);
        $this->fakeArchiveGitHub([self::SHA1 => $zip], self::SHA1);

        $repository = $this->repository();
        app(ArchiveRepositoryImporter::class)->import($repository);

        $evidence = $repository->currentEvidence()->count();
        $inventory = $repository->currentInventory()->count();

        $result = app(ArchiveRepositoryImporter::class)->import($repository->fresh());

        $this->assertTrue($result['up_to_date']);
        $this->assertSame($evidence, $repository->fresh()->currentEvidence()->count());
        $this->assertSame($inventory, $repository->fresh()->currentInventory()->count());
    }

    public function test_new_sha_produces_snapshot_with_diff(): void
    {
        Storage::fake('local');
        $zips = [
            self::SHA1 => $this->buildZip(['keep.md' => 'v1', 'gone.md' => 'old'], self::SHA1),
            self::SHA2 => $this->buildZip(['keep.md' => 'v2', 'new.md' => 'fresh'], self::SHA2),
        ];

        $head = self::SHA1;
        $this->fakeArchiveGitHub($zips, self::SHA1, function (string $which) use (&$head) {
            if ($which === 'ref') {
                return Http::response(['object' => ['sha' => $head]]);
            }

            return null;
        });

        $repository = $this->repository();
        app(ArchiveRepositoryImporter::class)->import($repository);
        $this->assertNull($repository->fresh()->last_import_summary['diff']);

        $head = self::SHA2;
        $result = app(ArchiveRepositoryImporter::class)->import($repository->fresh());

        $this->assertFalse($result['up_to_date']);
        $this->assertSame(self::SHA2, $repository->fresh()->last_commit_sha);

        $diff = $repository->fresh()->last_import_summary['diff'];
        $this->assertSame(self::SHA1, $diff['old_sha']);
        $this->assertSame(['new.md'], $diff['added']);
        $this->assertSame(['keep.md'], $diff['changed']);
        $this->assertSame(['gone.md'], $diff['deleted']);

        // Both snapshots coexist; history is never rewritten.
        $this->assertSame(2, $repository->evidenceSources()->where('commit_sha', self::SHA1)->count());
        $this->assertSame(2, $repository->evidenceSources()->where('commit_sha', self::SHA2)->count());
    }

    public function test_failed_download_preserves_prior_evidence(): void
    {
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $zip = $this->buildZip(['README.md' => 'docs']);

        $down = false;
        $this->fakeArchiveGitHub([self::SHA1 => $zip], self::SHA1, null, function () use (&$down, $zip) {
            if ($down) {
                return Http::response(['message' => 'boom'], 500);
            }

            return Http::response($zip, 200, ['Content-Type' => 'application/zip']);
        });

        $repository = $this->repository();
        app(ArchiveRepositoryImporter::class)->import($repository);
        $this->assertSame(1, $repository->fresh()->currentEvidence()->count());

        // A new revision whose archive never arrives: error, not silence.
        Storage::disk('local')->deleteDirectory('import-archives');
        $down = true;

        ImportRepositoryJob::dispatch($repository->id, self::SHA2);

        for ($i = 0; $i < 3; $i++) {
            DB::table('jobs')->update(['available_at' => time() - 1]);
            Artisan::call('queue:work', ['--once' => true, '--sleep' => 0]);
        }

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());

        $repository->refresh();
        $this->assertSame('failed', $repository->import_status);
        $this->assertStringContainsString('archive download failed', (string) $repository->import_error);
        $this->assertSame(1, $repository->currentEvidence()->count());
        $this->assertSame(self::SHA1, $repository->last_commit_sha);
    }

    public function test_rest_driver_fallback_still_uses_per_file_requests(): void
    {
        config(['revival.github.import_driver' => 'rest', 'queue.default' => 'database']);
        Storage::fake('local');

        Http::fake(function (Request $request) {
            $url = strtok($request->url(), '?');

            if (preg_match('#/repos/[^/]+/[^/]+$#', $url)) {
                return Http::response(['private' => false, 'default_branch' => 'main']);
            }

            if (str_contains($url, '/git/ref/heads/')) {
                return Http::response(['object' => ['sha' => self::SHA1]]);
            }

            if (str_contains($url, '/git/trees/')) {
                return Http::response(['sha' => self::SHA1, 'truncated' => false, 'tree' => [
                    ['path' => 'README.md', 'type' => 'blob', 'size' => 4, 'sha' => sha1('r')],
                ]]);
            }

            if (str_contains($url, '/contents/')) {
                return Http::response([
                    'encoding' => 'base64',
                    'content' => base64_encode('docs'),
                    'html_url' => 'https://github.com/acme/widgets/blob/'.self::SHA1.'/README.md',
                    'size' => 4,
                ]);
            }

            return Http::response(null, 404);
        });

        $repository = $this->repository();
        ImportRepositoryJob::dispatch($repository->id);
        Artisan::call('queue:work', ['--once' => true, '--sleep' => 0]);

        $this->assertTrue($repository->fresh()->isReady());

        $contents = array_filter(
            $this->recordedUrls(),
            fn ($url) => str_contains($url, '/contents/')
        );
        $this->assertNotEmpty($contents);
    }

    public function test_missing_relevant_and_second_pass_work_on_archive_path(): void
    {
        Storage::fake('local');
        config(['revival.github.max_files' => 1]);
        $zip = $this->buildZip(['README.md' => 'docs', 'app/main.py' => 'code']);
        $this->fakeArchiveGitHub([self::SHA1 => $zip], self::SHA1, null, null, function (string $url) {
            $path = substr($url, strpos($url, '/contents/') + strlen('/contents/'));
            $content = str_ends_with($path, '.py') ? 'code' : 'docs';

            return Http::response([
                'encoding' => 'base64',
                'content' => base64_encode($content),
                'html_url' => 'https://github.com/acme/widgets/blob/'.self::SHA1.'/'.$path,
                'size' => strlen($content),
            ]);
        });

        $repository = $this->repository();
        app(ArchiveRepositoryImporter::class)->import($repository);

        $missing = $repository->fresh()->last_import_summary['missing_relevant'];
        $this->assertNotEmpty($missing);

        $result = app(GitHubRepositoryImporter::class)
            ->fetchAdditionalPaths($repository->fresh(), [$missing[0]['path']]);

        $this->assertSame([$missing[0]['path']], $result['stored']);
        $this->assertSame(2, $repository->fresh()->currentEvidence()->count());
    }

    public function test_analysis_marks_stale_after_new_evidence(): void
    {
        $repository = $this->repository();
        $run = AnalysisRun::factory()->create([
            'project_id' => $repository->project_id,
            'repository_id' => $repository->id,
        ]);

        app(AnalysisRunRepository::class)->recordInputManifest($run, [], [], self::SHA1);

        $repository->forceFill(['import_status' => 'ready', 'last_commit_sha' => self::SHA1])->save();
        $this->assertFalse($run->fresh()->isEvidenceStale());

        $repository->forceFill(['last_commit_sha' => self::SHA2])->save();
        $this->assertTrue($run->fresh()->isEvidenceStale());

        $this->actingAs($repository->project->owner)
            ->get(route('projects.evidence', $repository->project))
            ->assertOk()
            ->assertSee('Evidence changed', false);
    }
}
