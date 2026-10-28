<?php

namespace Tests\Feature;

use App\Models\EvidenceSource;
use App\Models\Project;
use App\Models\Repository;
use App\Services\Revival\AnalysisRunRepository;
use App\Services\Revival\RevivalAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FrontendConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_alpine_runtime(): void
    {
        // Livewire v4 bundles and starts Alpine; the app entry must not
        // bootstrap a second copy.
        $entry = (string) file_get_contents(resource_path('js/app.js'));
        $this->assertStringNotContainsString("from 'alpinejs'", $entry);
        $this->assertStringNotContainsString('Alpine.start()', $entry);

        $layout = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertSame(1, substr_count($layout, '@livewireScripts'));
    }

    public function test_explorer_controls_have_stable_labels(): void
    {
        $html = $this->get(route('projects.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<label for="explorer-search" class="sr-only">Search projects</label>', $html);
        $this->assertStringContainsString('<label for="explorer-phase" class="sr-only">Phase</label>', $html);
        $this->assertStringContainsString('<label for="explorer-activity" class="sr-only">Activity</label>', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        // The placeholder stays as a hint, not the name.
        $this->assertStringContainsString('placeholder="Search projects or devices…', $html);
    }

    public function test_import_status_uses_live_region(): void
    {
        $project = Project::factory()->create();
        Repository::factory()->create([
            'project_id' => $project->id,
            'import_status' => 'importing',
            'import_error' => 'Rate limited; retrying automatically.',
        ]);

        $this->actingAs($project->owner)
            ->get(route('projects.evidence', $project))
            ->assertOk()
            ->assertSee('role="status"', false);

        $failed = Project::factory()->create();
        Repository::factory()->create([
            'project_id' => $failed->id,
            'import_status' => 'failed',
            'import_error' => 'Import failed: boom.',
        ]);

        $this->actingAs($failed->owner)
            ->get(route('projects.evidence', $failed))
            ->assertOk()
            ->assertSee('role="status"', false);
    }

    public function test_public_pages_describe_experimental_ai_consistently(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Experimental', false)
            ->assertSee('Analyze with AI', false)
            ->assertDontSee('In development', false);

        $this->get(route('about'))
            ->assertOk()
            ->assertSee('Experimental', false)
            ->assertSee('Analyze with AI', false)
            ->assertDontSee('In development', false);
    }

    public function test_unconfigured_provider_gives_actionable_error(): void
    {
        config(['ai.default' => 'meta', 'ai.meta.api_key' => null]);

        $project = Project::factory()->create();
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'import_status' => 'ready',
            'last_commit_sha' => str_repeat('b', 40),
        ]);
        EvidenceSource::factory()->create([
            'project_id' => $project->id,
            'repository_id' => $repository->id,
            'path' => 'README.md',
            'commit_sha' => $repository->last_commit_sha,
            'content' => 'docs',
        ]);

        $run = app(AnalysisRunRepository::class)->createRun(
            $project, $project->owner, $repository, 'meta', 'muse-spark-1.3'
        );
        app(RevivalAnalysisService::class)->analyze($run);

        $run->refresh();
        $this->assertSame('PROVIDER_ERROR', $run->error_code);
        $this->assertStringContainsString('not configured', (string) $run->error_message);
        $this->assertStringContainsString('MODEL_API_KEY', (string) $run->error_message);

        $this->actingAs($project->owner)
            ->get(route('projects.evidence', $project))
            ->assertOk()
            ->assertSee('MODEL_API_KEY', false);
    }
}
