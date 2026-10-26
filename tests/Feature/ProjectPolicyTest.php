<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitor_cannot_view_draft_project(): void
    {
        $project = Project::factory()->draft()->create();

        $this->get(route('projects.show', $project))->assertForbidden();
    }

    public function test_non_member_cannot_view_draft_project(): void
    {
        $project = Project::factory()->draft()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('projects.show', $project))->assertForbidden();
    }

    public function test_owner_can_view_own_draft(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->draft()->create(['owner_id' => $user->id]);

        $this->actingAs($user)->get(route('projects.show', $project))->assertOk();
    }

    public function test_non_owner_cannot_edit_or_delete(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('projects.edit', $project))->assertForbidden();
        $this->actingAs($user)->put(route('projects.update', $project), [])->assertForbidden();
        $this->actingAs($user)->delete(route('projects.destroy', $project))->assertForbidden();
    }

    public function test_maintainer_member_can_edit_but_contributor_cannot(): void
    {
        $project = Project::factory()->create();
        $maintainer = User::factory()->create();
        $contributor = User::factory()->create();

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $maintainer->id,
            'role' => ProjectRole::Maintainer,
            'status' => 'active',
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $contributor->id,
            'role' => ProjectRole::Contributor,
            'status' => 'active',
        ]);

        $this->actingAs($maintainer)->get(route('projects.edit', $project))->assertOk();
        $this->actingAs($contributor)->get(route('projects.edit', $project))->assertForbidden();
    }

    public function test_draft_project_is_hidden_from_explore_and_home(): void
    {
        $draft = Project::factory()->draft()->create(['title' => 'Secret Draft Project']);

        $this->get(route('home'))->assertOk()->assertDontSee('Secret Draft Project');
        $this->get(route('projects.index'))->assertOk();
        $this->assertSame(0, Project::published()->whereKey($draft->id)->count());
    }
}
