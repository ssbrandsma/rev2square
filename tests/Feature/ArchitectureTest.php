<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Enums\PublicationStatus;
use App\Models\ArchitectureSnapshot;
use App\Models\EvidenceSource;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Services\AI\AIRateLimitedException;
use App\Services\AI\AITimeoutException;
use App\Services\Revival\ArchitectureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MockAIProvider;
use Tests\TestCase;

class ArchitectureTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Project, 1: Repository, 2: User} */
    private function seedProject(string $status = 'ready', array $evidence = []): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->draft()->create(['owner_id' => $owner->id]);
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
                'content_hash' => hash('sha256', $content),
            ]);
        }

        return [$project->fresh(), $repository->fresh(), $owner];
    }

    /** @return list<array{source_id: int, path: string, content_hash: string}> */
    private function cite(Project $project, ?Repository $repository = null, ?string $commitSha = null): array
    {
        return EvidenceSource::where('project_id', $project->id)
            ->when($repository !== null, fn ($query) => $query->where('repository_id', $repository->id))
            ->when($commitSha !== null, fn ($query) => $query->where('commit_sha', $commitSha))
            ->orderBy('id')->get()
            ->map(fn ($row) => [
                'source_id' => $row->id,
                'path' => $row->path,
                'content_hash' => $row->content_hash,
            ])->all();
    }

    public function test_maintainer_runs_analysis_and_views_diagram(): void
    {
        [$project, , $owner] = $this->seedProject('ready', [
            'main.py' => "import os\nprint('hi')\n",
            'lib/util.py' => "def run():\n    return 1\n",
        ]);
        MockAIProvider::fake([MockAIProvider::architecturePayload($this->cite($project))]);

        $response = $this->actingAs($owner)->post(route('projects.architecture.store', $project));

        $response->assertRedirect(route('projects.architecture.show', $project));

        $snapshot = ArchitectureSnapshot::where('project_id', $project->id)->latest('id')->first();
        $this->assertNotNull($snapshot);
        $this->assertSame(AnalysisStatus::Ready, $snapshot->status);
        $this->assertSame('1.0', $snapshot->schema_version);
        $this->assertSame('1.0', $snapshot->prompt_version);
        $this->assertSame('mock', $snapshot->model);
        $this->assertNotNull($snapshot->input_manifest_hash);
        $this->assertNotNull($snapshot->evidence_identity);
        $this->assertSame(150, $snapshot->token_usage['total']);

        $page = $this->actingAs($owner)->get(route('projects.architecture.show', $project));
        $page->assertOk();
        $page->assertSee('CLI entry point');
        $page->assertSee('data-architecture-diagram', false);
        $page->assertSee('Entry imports helper');
    }

    public function test_model_output_is_rendered_escaped(): void
    {
        [$project, , $owner] = $this->seedProject('ready', ['main.py' => "x = 1\n"]);
        MockAIProvider::fake([MockAIProvider::architecturePayload($this->cite($project), [
            'components' => [['label' => '<script>alert(1)</script>']],
        ])]);

        $this->actingAs($owner)->post(route('projects.architecture.store', $project));

        $page = $this->actingAs($owner)->get(route('projects.architecture.show', $project));
        $page->assertOk();
        $page->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $page->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_invalid_json_and_schema_violations_fail_cleanly(): void
    {
        [$project, , $owner] = $this->seedProject('ready', ['main.py' => "x = 1\n"]);

        MockAIProvider::fake(['{not json']);
        $this->actingAs($owner)->post(route('projects.architecture.store', $project));
        $snapshot = ArchitectureSnapshot::where('project_id', $project->id)->latest('id')->first();
        $this->assertSame(AnalysisStatus::FailedValidation, $snapshot->status);
        $this->assertSame('INVALID_JSON', $snapshot->error_code);

        MockAIProvider::fake([MockAIProvider::architecturePayload($this->cite($project), [
            'components' => [['evidence' => [['source_id' => 424242]]]],
        ])]);
        $this->actingAs($owner)->post(route('projects.architecture.store', $project));
        $snapshot = ArchitectureSnapshot::where('project_id', $project->id)->latest('id')->first();
        $this->assertSame(AnalysisStatus::FailedValidation, $snapshot->status);
        $this->assertSame('SCHEMA_VIOLATION', $snapshot->error_code);

        $page = $this->actingAs($owner)->get(route('projects.architecture.show', $project));
        $page->assertOk();
        $page->assertSee('Architecture analysis failed');
    }

    public function test_rate_limits_and_timeouts_bubble_for_retry(): void
    {
        [$project] = $this->seedProject('ready', ['main.py' => "x = 1\n"]);
        $snapshot = ArchitectureSnapshot::create([
            'project_id' => $project->id,
            'repository_id' => $project->repository->id,
            'status' => AnalysisStatus::Queued,
            'cache_key' => 'test-key',
        ]);

        MockAIProvider::fake([new AIRateLimitedException('slow down', 60)]);
        try {
            app(ArchitectureService::class)->analyze($snapshot->fresh());
            $this->fail('Expected AIRateLimitedException.');
        } catch (AIRateLimitedException $e) {
            // Expected: the job retries with backoff.
            $this->assertSame('slow down', $e->getMessage());
        }

        MockAIProvider::fake([new AITimeoutException('timed out')]);
        try {
            app(ArchitectureService::class)->analyze($snapshot->fresh());
            $this->fail('Expected AITimeoutException.');
        } catch (AITimeoutException $e) {
            // Expected: the job retries with backoff.
            $this->assertSame('timed out', $e->getMessage());
        }
    }

    public function test_repeat_request_reuses_up_to_date_snapshot(): void
    {
        [$project, , $owner] = $this->seedProject('ready', ['main.py' => "x = 1\n"]);
        MockAIProvider::fake([MockAIProvider::architecturePayload($this->cite($project))]);

        $this->actingAs($owner)->post(route('projects.architecture.store', $project));
        $this->assertSame(1, ArchitectureSnapshot::where('project_id', $project->id)->count());

        MockAIProvider::fake([]);
        $response = $this->actingAs($owner)->post(route('projects.architecture.store', $project));
        $response->assertRedirect(route('projects.architecture.show', $project));
        $this->assertSame(1, ArchitectureSnapshot::where('project_id', $project->id)->count());
    }

    public function test_new_commit_marks_snapshot_stale_and_allows_refresh(): void
    {
        [$project, $repository, $owner] = $this->seedProject('ready', ['main.py' => "x = 1\n"]);
        MockAIProvider::fake([MockAIProvider::architecturePayload($this->cite($project))]);
        $this->actingAs($owner)->post(route('projects.architecture.store', $project));

        // A re-import updates the same repository row to a new commit.
        $repository->update(['last_commit_sha' => str_repeat('b', 40)]);
        EvidenceSource::factory()->create([
            'project_id' => $project->id,
            'repository_id' => $repository->id,
            'path' => 'main.py',
            'commit_sha' => $repository->last_commit_sha,
            'content' => "x = 2\n",
            'content_hash' => hash('sha256', "x = 2\n"),
        ]);

        $page = $this->actingAs($owner)->get(route('projects.architecture.show', $project));
        $page->assertOk();
        $page->assertSee('Stale snapshot');

        // Historical snapshots are immutable: the old payload is untouched.
        $old = ArchitectureSnapshot::where('project_id', $project->id)->latest('id')->first();
        $this->assertSame(str_repeat('a', 40), $old->manifest_commit_sha);

        MockAIProvider::fake([MockAIProvider::architecturePayload($this->cite($project, $repository, $repository->last_commit_sha))]);
        $this->actingAs($owner)->post(route('projects.architecture.store', $project));
        $this->assertSame(2, ArchitectureSnapshot::where('project_id', $project->id)->count());
        $fresh = ArchitectureSnapshot::where('project_id', $project->id)->latest('id')->first();
        $this->assertSame(AnalysisStatus::Ready, $fresh->status);
    }

    public function test_import_states_gate_analysis(): void
    {
        [$project, , $owner] = $this->seedProject('importing');
        $response = $this->actingAs($owner)->post(route('projects.architecture.store', $project));
        $response->assertRedirect();
        $this->assertSame(0, ArchitectureSnapshot::where('project_id', $project->id)->count());

        $owner2 = User::factory()->create();
        $empty = Project::factory()->draft()->create(['owner_id' => $owner2->id]);
        $response = $this->actingAs($owner2)->post(route('projects.architecture.store', $empty));
        $response->assertRedirect();
        $this->assertSame(0, ArchitectureSnapshot::where('project_id', $empty->id)->count());

        $page = $this->actingAs($owner2)->get(route('projects.architecture.show', $empty));
        $page->assertOk();
        $page->assertSee('No architecture yet');
    }

    public function test_authorization_follows_project_policy(): void
    {
        [$project, , $owner] = $this->seedProject('ready', ['main.py' => "x = 1\n"]);
        $stranger = User::factory()->create();

        $this->get(route('projects.architecture.show', $project))->assertForbidden();
        $this->post(route('projects.architecture.store', $project))->assertRedirect(route('login'));

        $this->actingAs($stranger)->get(route('projects.architecture.show', $project))->assertForbidden();
        $this->actingAs($stranger)->post(route('projects.architecture.store', $project))->assertForbidden();

        $project->update(['publication_status' => PublicationStatus::Published]);
        $this->get(route('projects.architecture.show', $project))->assertOk();
        $this->assertTrue($owner->can('update', $project));
    }

    public function test_partial_evidence_citations_are_validated(): void
    {
        [$project, $repository, $owner] = $this->seedProject('ready');
        $content = "line1\nline2\nline3\n";
        EvidenceSource::factory()->create([
            'project_id' => $project->id,
            'repository_id' => $repository->id,
            'path' => 'big/file.py',
            'commit_sha' => $repository->last_commit_sha,
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'is_partial' => true,
            'line_ranges' => [['start' => 100, 'end' => 102]],
            'original_lines' => 500,
        ]);

        $cite = $this->cite($project);
        $payload = MockAIProvider::architecturePayload($cite);
        $payload['components'][0]['evidence'] = [[
            'source_id' => $cite[0]['source_id'],
            'locator' => 'big/file.py:L100-L102',
            'content_hash' => $cite[0]['content_hash'],
        ]];
        // The helper's second component cites the same single source.
        $payload['components'][1]['evidence'] = $payload['components'][0]['evidence'];
        $payload['relationships'][0]['evidence'] = $payload['components'][0]['evidence'];

        MockAIProvider::fake([$payload]);
        $this->actingAs($owner)->post(route('projects.architecture.store', $project));

        $snapshot = ArchitectureSnapshot::where('project_id', $project->id)->latest('id')->first();
        $this->assertSame(AnalysisStatus::Ready, $snapshot->status);
        $this->assertSame(100, $snapshot->payload['components'][0]['evidence'][0]['from_line']);
    }
}
