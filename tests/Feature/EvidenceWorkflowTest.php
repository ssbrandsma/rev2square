<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Jobs\ImportRepositoryJob;
use App\Livewire\Projects\EvidenceManager;
use App\Models\AgentConversation;
use App\Models\AnalysisRun;
use App\Models\EvidenceSource;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class EvidenceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    private function seedProject(string $status = 'ready', array $evidence = []): array
    {
        $project = Project::factory()->create();
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'owner' => 'acme',
            'name' => 'widgets',
            'canonical_url' => 'https://github.com/acme/widgets',
            'import_status' => $status,
            'last_commit_sha' => str_repeat('a', 40),
        ]);

        foreach ($evidence as $path => $content) {
            EvidenceSource::factory()->create([
                'project_id' => $project->id,
                'repository_id' => $repository->id,
                'path' => $path,
                'commit_sha' => $repository->last_commit_sha,
                'content' => $content,
            ]);
        }

        return [$project->fresh(), $repository->fresh()];
    }

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

    public function test_source_viewer_shows_file_commit_and_content(): void
    {
        [$project] = $this->seedProject('ready', [
            'cmd/soundtouch-backup/README.md' => "# Backup\nRestore steps.\n",
        ]);
        $source = EvidenceSource::where('project_id', $project->id)->first();

        $page = $this->actingAs($project->owner)
            ->get(route('projects.evidence.show', [$project, $source]));

        $page->assertOk();
        $page->assertSee('cmd/soundtouch-backup/README.md');
        $page->assertSee(substr($source->commit_sha, 0, 12));
        $page->assertSee(substr($source->content_hash, 0, 16));
        $page->assertSee('Restore steps.');
        $page->assertSee('Complete file');
        $page->assertSee('Evidence manifest');
        $page->assertDontSee('Earlier import');
    }

    public function test_source_viewer_marks_earlier_import_and_links_back_to_conversation(): void
    {
        [$project] = $this->seedProject('ready', ['main.py' => "x = 1\n"]);
        $source = EvidenceSource::where('project_id', $project->id)->first();
        $source->forceFill(['commit_sha' => str_repeat('b', 40)])->save();

        $conversation = AgentConversation::create([
            'project_id' => $project->id,
            'user_id' => $project->owner->id,
            'title' => 'Cloud question',
        ]);

        $page = $this->actingAs($project->owner)->get(
            route('projects.evidence.show', [$project, $source, 'from' => 'agent:'.$conversation->id])
        );

        $page->assertOk();
        $page->assertSee('Earlier import');
        $page->assertSee('Back to conversation');
        $page->assertSee(route('projects.agent.show', [$project, $conversation]), false);

        // Forged or foreign return targets are ignored, never followed.
        $other = Project::factory()->create();
        $foreign = AgentConversation::create([
            'project_id' => $other->id,
            'user_id' => $other->owner->id,
            'title' => 'Elsewhere',
        ]);

        $this->actingAs($project->owner)->get(
            route('projects.evidence.show', [$project, $source, 'from' => 'agent:'.$foreign->id])
        )->assertOk()->assertDontSee('Back to conversation');

        $this->actingAs($project->owner)->get(
            route('projects.evidence.show', [$project, $source, 'from' => 'agent:999999'])
        )->assertOk()->assertDontSee('Back to conversation');
    }

    public function test_source_viewer_reports_partial_ranges_honestly(): void
    {
        [$project] = $this->seedProject('ready', ['big.go' => "package big\n"]);
        $source = EvidenceSource::where('project_id', $project->id)->first();
        $source->forceFill([
            'is_partial' => true,
            'line_ranges' => [['start' => 10, 'end' => 30]],
            'original_lines' => 200,
        ])->save();

        $this->actingAs($project->owner)
            ->get(route('projects.evidence.show', [$project, $source]))
            ->assertOk()
            ->assertSee('Partial excerpt')
            ->assertSee('L10–L30')
            ->assertSee('200 original lines');

        $source->forceFill(['line_ranges' => null, 'original_lines' => null])->save();

        $this->actingAs($project->owner)
            ->get(route('projects.evidence.show', [$project, $source]))
            ->assertOk()
            ->assertSee('Original line numbers unavailable for this excerpt.');
    }

    public function test_source_viewer_requires_maintainer_and_own_project(): void
    {
        [$project] = $this->seedProject('ready', ['main.py' => "x = 1\n"]);
        $source = EvidenceSource::where('project_id', $project->id)->first();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get(route('projects.evidence.show', [$project, $source]))
            ->assertForbidden();

        [$other] = $this->seedProject('ready', ['other.py' => "y = 2\n"]);

        $this->actingAs($other->owner)
            ->get(route('projects.evidence.show', [$other, $source]))
            ->assertNotFound();
    }

    public function test_reimport_prefills_tracked_url(): void
    {
        Queue::fake();
        [$project] = $this->seedProject();

        Livewire::actingAs($project->owner)
            ->test(EvidenceManager::class, ['project' => $project])
            ->assertSet('url', 'https://github.com/acme/widgets')
            ->call('import')
            ->assertHasNoErrors()
            // The tracked source stays visible; it is no longer cleared.
            ->assertSet('url', 'https://github.com/acme/widgets');

        Livewire::actingAs($project->owner)
            ->test(EvidenceManager::class, ['project' => Project::factory()->create()])
            ->assertSet('url', '');
    }

    public function test_reimport_dispatches_without_retyping(): void
    {
        Queue::fake();
        [$project] = $this->seedProject();

        // Fresh mount (reload, dashboard return, new session): the prefilled
        // input submits without touching the keyboard.
        Livewire::actingAs($project->owner)
            ->test(EvidenceManager::class, ['project' => $project])
            ->call('import')
            ->assertHasNoErrors();

        Queue::assertPushed(ImportRepositoryJob::class, 1);
    }

    public function test_canonical_variants_resolve_to_same_row(): void
    {
        Queue::fake();
        [$project, $repository] = $this->seedProject();

        $variants = [
            'https://github.com/ACME/Widgets',
            'https://github.com/acme/widgets/',
            'https://github.com/acme/widgets.git',
            'https://github.com/acme/widgets/tree/main/src',
            'http://github.com/acme/widgets',
        ];

        foreach ($variants as $variant) {
            Livewire::actingAs($project->owner)
                ->test(EvidenceManager::class, ['project' => $project])
                ->set('url', $variant)
                ->call('import')
                ->assertHasNoErrors();

            $this->assertSame(1, Repository::where('project_id', $project->id)->count());
            $this->assertSame('https://github.com/acme/widgets', $repository->fresh()->canonical_url);

            Repository::whereKey($repository->id)->update(['import_status' => 'ready']);
        }
    }

    public function test_new_source_creates_explicit_second_row(): void
    {
        Queue::fake();
        [$project] = $this->seedProject();
        DB::table('repositories')->update(['created_at' => now()->subHour()]);

        Livewire::actingAs($project->owner)
            ->test(EvidenceManager::class, ['project' => $project])
            ->set('url', 'https://github.com/other/gadgets')
            ->call('import')
            ->assertHasNoErrors()
            ->assertSet('url', 'https://github.com/other/gadgets');

        $this->assertSame(2, Repository::where('project_id', $project->id)->count());
        $this->assertSame(
            'https://github.com/other/gadgets',
            Repository::where('project_id', $project->id)->latest()->firstOrFail()->canonical_url
        );
    }

    public function test_invalid_url_still_rejected(): void
    {
        Queue::fake();
        [$project] = $this->seedProject();

        Livewire::actingAs($project->owner)
            ->test(EvidenceManager::class, ['project' => $project])
            ->set('url', 'https://example.com/not-github')
            ->call('import')
            ->assertHasErrors(['url' => fn ($rules, $messages) => in_array('URL must be a public https://github.com/owner/repository address.', $messages, true)]);

        Queue::assertNotPushed(ImportRepositoryJob::class);
    }

    public function test_repeat_import_while_running_is_rejected_without_reset(): void
    {
        Queue::fake();
        [$project, $repository] = $this->seedProject('importing');
        $repository->forceFill(['import_error' => 'Rate limited; retrying automatically.'])->save();

        Livewire::actingAs($project->owner)
            ->test(EvidenceManager::class, ['project' => $project])
            ->call('import')
            ->assertHasErrors(['url' => fn ($rules, $messages) => in_array('An import for https://github.com/acme/widgets is already running.', $messages, true)]);

        Queue::assertNotPushed(ImportRepositoryJob::class);
        $this->assertSame('Rate limited; retrying automatically.', $repository->fresh()->import_error);
        $this->assertSame(1, Repository::where('project_id', $project->id)->count());
    }

    public function test_two_tabs_cannot_dispatch_duplicates(): void
    {
        Queue::fake();
        [$project] = $this->seedProject();

        $first = Livewire::actingAs($project->owner)
            ->test(EvidenceManager::class, ['project' => $project]);
        $second = Livewire::actingAs($project->owner)
            ->test(EvidenceManager::class, ['project' => $project]);

        $first->call('import')->assertHasNoErrors();
        $second->call('import')->assertHasErrors('url');

        Queue::assertPushed(ImportRepositoryJob::class, 1);
    }

    public function test_different_source_blocked_while_project_importing(): void
    {
        Queue::fake();
        [$project] = $this->seedProject('importing');

        Livewire::actingAs($project->owner)
            ->test(EvidenceManager::class, ['project' => $project])
            ->set('url', 'https://github.com/other/gadgets')
            ->call('import')
            ->assertHasErrors('url');

        Queue::assertNotPushed(ImportRepositoryJob::class);
        $this->assertSame(1, Repository::where('project_id', $project->id)->count());
    }

    public function test_reimport_allowed_after_terminal_state(): void
    {
        Queue::fake();
        [$project] = $this->seedProject('failed');

        Livewire::actingAs($project->owner)
            ->test(EvidenceManager::class, ['project' => $project])
            ->call('import')
            ->assertHasNoErrors();

        Queue::assertPushed(ImportRepositoryJob::class, 1);
        $this->assertTrue(Repository::where('project_id', $project->id)->firstOrFail()->isImporting());
    }

    public function test_importing_notice_identifies_active_job(): void
    {
        [$project] = $this->seedProject('importing');

        $this->actingAs($project->owner)
            ->get(route('projects.evidence', $project))
            ->assertOk()
            ->assertSee('already running', false)
            ->assertSee('https://github.com/acme/widgets', false);

        [$readyProject] = $this->seedProject();

        $this->actingAs($readyProject->owner)
            ->get(route('projects.evidence', $readyProject))
            ->assertOk()
            ->assertDontSee('already running', false);
    }

    public function test_double_dispatch_yields_single_evidence_set(): void
    {
        config(['revival.github.import_driver' => 'rest', 'queue.default' => 'database']);
        $this->fakeGitHub(['README.md' => 'docs']);
        [$project, $repository] = $this->seedProject('pending');

        // Two jobs for the same source (e.g. dispatched around a crash):
        // identity-keyed upserts converge instead of duplicating.
        ImportRepositoryJob::dispatch($repository->id);
        ImportRepositoryJob::dispatch($repository->id);

        Artisan::call('queue:work', ['--once' => true, '--sleep' => 0]);
        DB::table('jobs')->update(['available_at' => time() - 1]);
        Artisan::call('queue:work', ['--once' => true, '--sleep' => 0]);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertTrue($repository->fresh()->isReady());
        $this->assertSame(1, $repository->fresh()->currentEvidence()->count());
        $this->assertSame(1, $repository->fresh()->currentInventory()->count());
    }

    public function test_analyze_explanation_per_evidence_state(): void
    {
        [$empty] = $this->seedProject();
        Repository::where('project_id', $empty->id)->delete();

        $this->actingAs($empty->owner)
            ->get(route('projects.evidence', $empty))
            ->assertOk()
            ->assertSee('Import a repository above before running an analysis.', false);

        [$importing] = $this->seedProject('importing');

        $this->actingAs($importing->owner)
            ->get(route('projects.evidence', $importing))
            ->assertOk()
            ->assertSee('Analysis unlocks when the import finishes.', false);

        [$failed] = $this->seedProject('failed');

        $this->actingAs($failed->owner)
            ->get(route('projects.evidence', $failed))
            ->assertOk()
            ->assertSee('The last import failed', false);

        [$busy, $busyRepo] = $this->seedProject('ready', ['README.md' => 'docs']);
        AnalysisRun::factory()->create([
            'project_id' => $busy->id,
            'user_id' => $busy->owner_id,
            'repository_id' => $busyRepo->id,
            'status' => AnalysisStatus::Queued,
        ]);

        $this->actingAs($busy->owner)
            ->get(route('projects.evidence', $busy))
            ->assertOk()
            ->assertSee('An analysis is already running for this project.', false);

        [$storeless] = $this->seedProject();

        $this->actingAs($storeless->owner)
            ->get(route('projects.evidence', $storeless))
            ->assertOk()
            ->assertSee('stored no usable files', false);

        [$ready] = $this->seedProject('ready', ['README.md' => 'docs', 'app/main.py' => 'code']);

        $this->actingAs($ready->owner)
            ->get(route('projects.evidence', $ready))
            ->assertOk()
            ->assertSee('Will analyze 2 evidence files at commit aaaaaaaaaaaa.', false);
    }

    public function test_backend_rejects_analyze_while_importing(): void
    {
        [$project] = $this->seedProject('importing');

        Livewire::actingAs($project->owner)
            ->test(EvidenceManager::class, ['project' => $project])
            ->call('analyze')
            ->assertHasErrors(['analyze' => 'Import repository evidence before running an analysis.']);
    }
}
