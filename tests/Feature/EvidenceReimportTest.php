<?php

namespace Tests\Feature;

use App\Models\Repository;
use App\Services\Revival\GitHubRepositoryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Repeated imports of the same commit with changing budgets.
 *
 * Regression coverage for the production mismatch (Excluded files header
 * vs. per-reason pills) and for budget increases that never took effect.
 */
class EvidenceReimportTest extends TestCase
{
    use RefreshDatabase;

    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    /** @param  array<string, string>  $files */
    private function fakeGitHub(array $files): void
    {
        Http::fake(function (Request $request) use ($files) {
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

    /** @return array<string, string> */
    private function files(): array
    {
        $files = ['README.md' => "# Widgets\n"];

        for ($i = 1; $i <= 11; $i++) {
            $files["app/module{$i}.py"] = "def handler{$i}():\n    return {$i}\n";
        }

        return $files;
    }

    private function repository(): Repository
    {
        return Repository::factory()->create([
            'owner' => 'acme',
            'name' => 'widgets',
            'canonical_url' => 'https://github.com/acme/widgets',
        ]);
    }

    private function assertSummaryConsistent(Repository $repository): void
    {
        $repository->refresh();
        $summary = $repository->last_import_summary;

        $skipped = $summary['skipped'] ?? [];
        $tally = $summary['excluded_by_reason'] ?? [];

        // The header count and the per-reason pills share one source of truth.
        $this->assertSame(count($skipped), array_sum($tally), 'excluded_by_reason must sum to the skipped count');

        $storedPaths = $repository->currentEvidence()->pluck('path')->all();
        $skippedPaths = array_column($skipped, 'path');

        // No path may be both stored evidence and an exclusion.
        $this->assertSame([], array_intersect($storedPaths, $skippedPaths));

        // The stored-files inventory stat matches the evidence rows.
        $this->assertSame(count($storedPaths), $summary['inventory']['stored'] ?? null);

        // No stored inventory row may carry a stale skip reason.
        $this->assertSame(
            0,
            $repository->currentInventory()->where('status', 'stored')->whereNotNull('skip_reason')->count()
        );
    }

    public function test_reimport_with_increased_budgets_imports_more(): void
    {
        config(['revival.github.max_files' => 3]);
        $this->fakeGitHub($this->files());
        $repository = $this->repository();
        $importer = app(GitHubRepositoryImporter::class);

        $importer->import($repository);

        $this->assertSame(3, $repository->fresh()->currentEvidence()->count());
        $firstIds = $repository->fresh()->currentEvidence()->orderBy('path')->pluck('id', 'path')->all();
        $this->assertSummaryConsistent($repository);

        // Raising budgets and re-importing the same commit must take effect.
        config(['revival.github.max_files' => 50]);
        $result = $importer->import($repository->fresh());

        $this->assertFalse($result['up_to_date']);
        $repository->refresh();

        $this->assertSame(12, $repository->currentEvidence()->count());
        $this->assertSame([], $repository->last_import_summary['skipped']);

        // Existing evidence rows are reused, never duplicated or replaced.
        $secondIds = $repository->currentEvidence()->orderBy('path')->pluck('id', 'path')->all();

        foreach ($firstIds as $path => $id) {
            $this->assertSame($id, $secondIds[$path] ?? null, "evidence id changed for {$path}");
        }

        $this->assertSame(12, $repository->evidenceSources()->count());
        $this->assertSame(12, $repository->currentInventory()->count());
        $this->assertSummaryConsistent($repository);
    }

    public function test_reimport_with_same_budgets_is_a_noop(): void
    {
        $this->fakeGitHub($this->files());
        $repository = $this->repository();
        $importer = app(GitHubRepositoryImporter::class);

        $importer->import($repository);
        $summary = $repository->fresh()->last_import_summary;

        $result = $importer->import($repository->fresh());

        $this->assertTrue($result['up_to_date']);
        $this->assertSame($summary, $repository->fresh()->last_import_summary);
        $this->assertSame(12, $repository->evidenceSources()->count());
    }

    public function test_budget_decrease_retains_existing_evidence(): void
    {
        config(['revival.github.max_files' => 50]);
        $this->fakeGitHub($this->files());
        $repository = $this->repository();
        $importer = app(GitHubRepositoryImporter::class);

        $importer->import($repository);
        $this->assertSame(12, $repository->fresh()->currentEvidence()->count());

        // Tightening budgets must not delete evidence or contradict it.
        config(['revival.github.max_files' => 2]);
        $importer->import($repository->fresh());

        $repository->refresh();
        $this->assertSame(12, $repository->currentEvidence()->count());
        $this->assertSummaryConsistent($repository);
        $this->assertStringContainsString(
            'retained',
            strtolower(implode(' ', $repository->last_import_summary['warnings'] ?? []))
        );
    }

    public function test_fetching_missing_files_keeps_summary_consistent(): void
    {
        config(['revival.github.max_files' => 4]);
        $this->fakeGitHub($this->files());
        $repository = $this->repository();
        $importer = app(GitHubRepositoryImporter::class);

        $importer->import($repository);
        $this->assertSummaryConsistent($repository);

        $importedAt = $repository->fresh()->last_import_summary['imported_at'] ?? null;
        $missing = $repository->fresh()->last_import_summary['missing_relevant'] ?? [];
        $this->assertNotEmpty($missing);

        // Fetch every suggested file one by one, like the production UI flow.
        foreach (array_column($missing, 'path') as $path) {
            $result = $importer->fetchAdditionalPaths($repository->fresh(), [$path]);
            $this->assertSame([$path], $result['stored']);
            $this->assertSummaryConsistent($repository);
        }

        $repository->refresh();
        $this->assertSame($importedAt, $repository->last_import_summary['imported_at']);
        $this->assertSame(
            $repository->currentEvidence()->count(),
            $repository->last_import_summary['files']
        );

        // Already-imported fetches change nothing.
        $before = $repository->last_import_summary;
        $repeat = $importer->fetchAdditionalPaths($repository, [$missing[0]['path']]);
        $this->assertSame([], $repeat['stored']);
        $this->assertSame($before, $repository->fresh()->last_import_summary);
    }

    public function test_planner_reports_the_specific_exhausted_budget(): void
    {
        $this->fakeGitHub($this->files());
        $repository = $this->repository();

        config(['revival.github.max_files' => 2]);
        app(GitHubRepositoryImporter::class)->import($repository);

        $reasons = collect($repository->fresh()->last_import_summary['skipped'])->pluck('reason')->unique()->all();
        $this->assertContains('file count limit reached', $reasons);
        $this->assertNotContains('evidence budget exhausted', $reasons);
        $this->assertNotContains('low relevance', $reasons);
    }
}
