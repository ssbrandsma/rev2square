<?php

namespace Tests\Feature;

use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Enums\PublicationStatus;
use App\Enums\TaskStatus;
use App\Livewire\Projects\TaskDirectory;
use App\Models\AuditEvent;
use App\Models\ContributionOffer;
use App\Models\ContributorTask;
use App\Models\Observation;
use App\Models\Project;
use App\Models\TestReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DiscoveryExportTest extends TestCase
{
    use RefreshDatabase;

    private function publishedProject(array $overrides = []): Project
    {
        return Project::factory()->create([
            'publication_status' => PublicationStatus::Published,
            ...$overrides,
        ]);
    }

    public function test_task_directory_filters_and_excludes_private_work(): void
    {
        $project = $this->publishedProject();
        ContributorTask::factory()->create([
            'project_id' => $project->id,
            'title' => 'Documented Wiring Guide Task',
            'skills' => ['documentation'],
            'requires_hardware' => false,
            'status' => TaskStatus::Open,
        ]);
        ContributorTask::factory()->create([
            'project_id' => $project->id,
            'title' => 'Soldering Repair Task',
            'skills' => ['soldering'],
            'requires_hardware' => true,
            'status' => TaskStatus::Open,
        ]);

        // Draft projects and retired tasks never appear.
        $draft = Project::factory()->draft()->create();
        ContributorTask::factory()->create(['project_id' => $draft->id, 'title' => 'Hidden Draft Task']);
        ContributorTask::factory()->create([
            'project_id' => $project->id,
            'title' => 'Hidden Retired Task',
            'status' => TaskStatus::Retired,
        ]);

        // The acceptance journey: an open documentation task without hardware.
        Livewire::withQueryParams(['skill' => 'documentation', 'hardware' => 'no', 'status' => TaskStatus::Open->value])
            ->test(TaskDirectory::class)
            ->assertSee('Documented Wiring Guide Task')
            ->assertDontSee('Soldering Repair Task')
            ->assertDontSee('Hidden Draft Task')
            ->assertDontSee('Hidden Retired Task')
            ->assertSee('1 task found', false);
    }

    public function test_task_directory_rejects_invalid_query_values(): void
    {
        $project = $this->publishedProject();
        ContributorTask::factory()->create(['project_id' => $project->id, 'title' => 'Visible Directory Task']);

        Livewire::withQueryParams(['hardware' => 'maybe', 'status' => 'NOPE', 'project' => 'no-such-slug', 'sort' => 'NOPE'])
            ->test(TaskDirectory::class)
            ->assertSet('hardware', '')
            ->assertSet('status', '')
            ->assertSet('project', '')
            ->assertSet('sort', 'newest')
            ->assertSee('Visible Directory Task');
    }

    public function test_home_task_cta_leads_to_directory(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee(route('tasks.index'), false);

        $this->get(route('tasks.index'))->assertOk()->assertSee('Find a task', false);
    }

    public function test_directory_describes_all_status_scope(): void
    {
        // The default view lists every active status (not only Open), so
        // the header must say so instead of promising "open work".
        $this->get(route('tasks.index'))
            ->assertOk()
            ->assertSee('Contributor tasks across published revival projects, newest first.')
            ->assertDontSee('Open work across published revival projects.');
    }

    public function test_export_includes_evidence_and_excludes_private_data(): void
    {
        $project = $this->publishedProject();
        Observation::factory()->create(['project_id' => $project->id, 'title' => 'Exported Observation Title']);
        TestReport::factory()->create(['project_id' => $project->id, 'title' => 'Exported Report Title']);
        AuditEvent::record('observation.created', $project->owner, $project);

        $applicant = User::factory()->create();
        ContributionOffer::factory()->create([
            'project_id' => $project->id,
            'user_id' => $applicant->id,
            'type' => OfferType::OfferHelp,
            'status' => OfferStatus::Pending,
            'message' => 'Secret application message text.',
        ]);

        $json = $this->get(route('projects.export', [$project, 'json']))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="'.$project->slug.'-revival.json"')
            ->json();

        $this->assertSame('Exported Observation Title', $json['observations'][0]['title']);
        $this->assertSame('Exported Report Title', $json['test_reports'][0]['title']);
        $this->assertNotEmpty($json['history']);

        $raw = $this->get(route('projects.export', [$project, 'json']))->getContent();
        $this->assertStringNotContainsString('Secret application message text.', $raw);

        $md = $this->get(route('projects.export', [$project, 'md']))->assertOk()->getContent();
        $this->assertStringContainsString('Exported Observation Title', $md);
        $this->assertStringContainsString('Exported Report Title', $md);
        $this->assertStringNotContainsString('Secret application message text.', $md);
    }

    public function test_export_respects_draft_privacy(): void
    {
        $draft = Project::factory()->draft()->create();

        $this->get(route('projects.export', [$draft, 'json']))->assertForbidden();
        $this->get(route('projects.export', [$draft, 'md']))->assertForbidden();

        $this->actingAs($draft->owner)
            ->get(route('projects.export', [$draft, 'json']))
            ->assertOk();
    }

    public function test_project_history_shows_public_events_only(): void
    {
        $project = $this->publishedProject();
        AuditEvent::record('task.created', $project->owner, $project);
        AuditEvent::record('offer.submitted', User::factory()->create(), $project);

        $this->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Project history', false)
            ->assertSee('created a task', false)
            ->assertDontSee('offer.submitted', false);
    }

    public function test_edit_page_shows_publication_checklist(): void
    {
        $project = $this->publishedProject();

        $this->actingAs($project->owner)
            ->get(route('projects.edit', $project))
            ->assertOk()
            ->assertSee('Publication checklist', false)
            ->assertSee('Maintainer identity', false)
            ->assertSee('Preview the public page', false);
    }

    public function test_login_returns_to_intended_page(): void
    {
        $project = $this->publishedProject();
        $user = User::factory()->create();

        // Visiting a protected page remembers it; login returns there.
        $this->get(route('projects.offers.create', $project))->assertRedirect(route('login'));

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('projects.offers.create', $project));
    }
}
