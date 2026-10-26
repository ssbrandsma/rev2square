<?php

namespace Tests\Feature;

use App\Enums\ProjectActivity;
use App\Enums\ProjectPhase;
use App\Enums\PublicationStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitor_can_browse_published_projects(): void
    {
        $project = Project::factory()->create();

        $this->get(route('home'))->assertOk()->assertSee($project->title);
        $this->get(route('projects.index'))->assertOk()->assertSeeLivewire('projects.explorer');
        $this->get(route('projects.show', $project))->assertOk()->assertSee($project->title);
    }

    public function test_owner_can_create_project(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('projects.store'), [
            'title' => 'ThinkPad Revival',
            'pitch' => 'A test pitch.',
            'body' => "First paragraph.\n\nSecond paragraph.",
            'phase' => ProjectPhase::IdeaDiscovery->value,
            'activity' => ProjectActivity::Active->value,
            'publication_status' => PublicationStatus::Draft->value,
        ]);

        $project = Project::where('title', 'ThinkPad Revival')->firstOrFail();
        $response->assertRedirect(route('projects.show', $project));
        $this->assertSame($user->id, $project->owner_id);
        $this->assertSame('thinkpad-revival', $project->slug);
    }

    public function test_create_requires_title_and_valid_enums(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('projects.store'), [
            'title' => '',
            'phase' => 'NOPE',
            'activity' => ProjectActivity::Active->value,
            'publication_status' => PublicationStatus::Published->value,
        ])->assertSessionHasErrors(['title', 'phase']);

        $this->assertSame(0, Project::count());
    }

    public function test_owner_can_update_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $this->actingAs($user)
            ->put(route('projects.update', $project), [
                'title' => 'Updated title',
                'pitch' => $project->pitch,
                'body' => $project->body,
                'phase' => ProjectPhase::Development->value,
                'activity' => ProjectActivity::Paused->value,
                'publication_status' => PublicationStatus::Published->value,
            ])
            ->assertRedirect(route('projects.show', $project));

        $project->refresh();
        $this->assertSame('Updated title', $project->title);
        $this->assertSame(ProjectPhase::Development, $project->phase);
    }

    public function test_owner_can_delete_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $this->actingAs($user)
            ->delete(route('projects.destroy', $project))
            ->assertRedirect(route('dashboard'));

        $this->assertSame(0, Project::count());
    }

    public function test_guest_cannot_reach_mutation_routes(): void
    {
        $project = Project::factory()->create();

        $this->get(route('projects.create'))->assertRedirect(route('login'));
        $this->post(route('projects.store'), [])->assertRedirect(route('login'));
        $this->get(route('projects.edit', $project))->assertRedirect(route('login'));
        $this->put(route('projects.update', $project), [])->assertRedirect(route('login'));
        $this->delete(route('projects.destroy', $project))->assertRedirect(route('login'));
    }

    public function test_dashboard_lists_owned_projects(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee($project->title);
    }
}
