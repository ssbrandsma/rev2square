<?php

namespace Tests\Feature;

use App\Enums\OfferStatus;
use App\Enums\ProjectRole;
use App\Enums\PublicationStatus;
use App\Models\ContributionOffer;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JoinProjectTest extends TestCase
{
    use RefreshDatabase;

    private function acceptedOffer(Project $project, User $applicant): ContributionOffer
    {
        return ContributionOffer::factory()
            ->for($project, 'project')
            ->for($applicant, 'user')
            ->create(['status' => OfferStatus::Accepted]);
    }

    public function test_accepted_applicant_joins_as_contributor_without_manage_rights(): void
    {
        $project = Project::factory()->create(['publication_status' => PublicationStatus::Published]);
        $applicant = User::factory()->create();
        $offer = $this->acceptedOffer($project, $applicant);

        // Acceptance alone grants nothing.
        $this->assertFalse($applicant->can('update', $project));

        $this->actingAs($applicant)
            ->post(route('projects.offers.join', [$project, $offer]))
            ->assertRedirect();

        $member = ProjectMember::where(['project_id' => $project->id, 'user_id' => $applicant->id])->firstOrFail();
        $this->assertSame(ProjectRole::Contributor, $member->role);
        $this->assertSame('active', $member->status);
        $this->assertDatabaseHas('audit_events', ['event' => 'team.joined']);

        // Contributors still cannot manage the project.
        $this->assertFalse($applicant->fresh()->can('update', $project));
        $this->actingAs($applicant)->get(route('projects.edit', $project))->assertForbidden();

        // Joining again is a harmless no-op.
        $this->actingAs($applicant)
            ->post(route('projects.offers.join', [$project, $offer]))
            ->assertRedirect();
        $this->assertSame(1, ProjectMember::where(['project_id' => $project->id, 'user_id' => $applicant->id])->count());
    }

    public function test_join_guards_status_owner_and_revocation(): void
    {
        $project = Project::factory()->create(['publication_status' => PublicationStatus::Published]);
        $applicant = User::factory()->create();

        $pending = ContributionOffer::factory()->for($project, 'project')->for($applicant, 'user')->create();
        $this->actingAs($applicant)
            ->post(route('projects.offers.join', [$project, $pending]))
            ->assertStatus(422);

        $offer = $this->acceptedOffer($project, $applicant);
        $this->actingAs(User::factory()->create())
            ->post(route('projects.offers.join', [$project, $offer]))
            ->assertForbidden();

        // Revoked members cannot self-rejoin on a stale acceptance.
        ProjectMember::factory()->for($project, 'project')->for($applicant, 'user')->create([
            'role' => ProjectRole::Contributor, 'status' => 'revoked',
        ]);
        $this->actingAs($applicant)
            ->post(route('projects.offers.join', [$project, $offer]))
            ->assertSessionHasErrors('offer');
        $this->assertSame('revoked', ProjectMember::where([
            'project_id' => $project->id, 'user_id' => $applicant->id,
        ])->firstOrFail()->status);
    }

    public function test_joined_member_appears_in_working_on_and_team(): void
    {
        $project = Project::factory()->create(['publication_status' => PublicationStatus::Published]);
        $applicant = User::factory()->create();
        $offer = $this->acceptedOffer($project, $applicant);

        $this->actingAs($applicant)->post(route('projects.offers.join', [$project, $offer]));

        $content = $this->get($applicant->profileUrl())->assertOk()->getContent();
        $this->assertStringContainsString($project->title, $content);
        $this->assertStringContainsString('Working on <span class="text-zinc-400">(1)</span>', $content);

        $this->get(route('projects.team.index', $project))->assertOk()->assertSee($applicant->name, false);
    }
}
