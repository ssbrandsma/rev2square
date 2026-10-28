<?php

namespace Tests\Feature;

use App\Enums\InvitationStatus;
use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Enums\ProjectActivity;
use App\Enums\ProjectRole;
use App\Enums\PublicationStatus;
use App\Models\ContributionOffer;
use App\Models\ContributorTask;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    private function publishedProject(array $overrides = []): Project
    {
        return Project::factory()->create([
            'publication_status' => PublicationStatus::Published,
            ...$overrides,
        ]);
    }

    public function test_apply_to_maintain_requires_seeking_maintainer(): void
    {
        $active = $this->publishedProject(['activity' => ProjectActivity::Active]);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.offers.store', $active), [
                'type' => OfferType::MaintainProject->value,
                'message' => 'Let me maintain this.',
            ])
            ->assertSessionHasErrors('type');

        $this->assertSame(0, ContributionOffer::count());

        $seeking = $this->publishedProject(['activity' => ProjectActivity::SeekingMaintainer]);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.offers.store', $seeking), [
                'type' => OfferType::MaintainProject->value,
                'message' => 'I have the hardware and the time.',
            ])
            ->assertRedirect();

        $this->assertSame(1, ContributionOffer::count());
        $this->assertSame(OfferType::MaintainProject, ContributionOffer::firstOrFail()->type);
    }

    public function test_maintainer_application_cannot_attach_a_task(): void
    {
        $project = $this->publishedProject(['activity' => ProjectActivity::SeekingMaintainer]);
        $task = ContributorTask::factory()->create(['project_id' => $project->id]);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.offers.store', $project), [
                'type' => OfferType::MaintainProject->value,
                'contributor_task_id' => $task->id,
                'message' => 'Trying to attach.',
            ])
            ->assertSessionHasErrors('contributor_task_id');

        $this->assertSame(0, ContributionOffer::count());
    }

    public function test_accepting_application_invites_without_granting_access(): void
    {
        $project = $this->publishedProject(['activity' => ProjectActivity::SeekingMaintainer]);
        $applicant = User::factory()->create();
        $offer = ContributionOffer::factory()->create([
            'project_id' => $project->id,
            'user_id' => $applicant->id,
            'type' => OfferType::MaintainProject,
            'status' => OfferStatus::Pending,
        ]);

        $this->actingAs($project->owner)
            ->post(route('projects.offers.accept', [$project, $offer]))
            ->assertRedirect();

        $this->assertSame(OfferStatus::Accepted, $offer->fresh()->status);

        // Access is NOT granted: only a pending invitation exists.
        $this->assertFalse($project->managedBy($applicant));
        $invitation = TeamInvitation::firstOrFail();
        $this->assertSame(InvitationStatus::Pending, $invitation->status);
        $this->assertSame($applicant->id, $invitation->user_id);

        // Applicant learns about the invitation in their inbox.
        $this->assertSame(1, $applicant->notifications()
            ->where('type', TeamInvitationNotification::class)->count());

        // Accepting grants the Maintainer role.
        $this->actingAs($applicant)
            ->post(route('projects.team.accept', [$project, $invitation]))
            ->assertRedirect();

        $this->assertSame(InvitationStatus::Accepted, $invitation->fresh()->status);
        $this->assertTrue($project->managedBy($applicant));
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $applicant->id,
            'role' => ProjectRole::Maintainer->value,
            'status' => 'active',
        ]);

        // Reuse is impossible.
        $this->actingAs($applicant)
            ->post(route('projects.team.accept', [$project, $invitation]))
            ->assertStatus(422);
    }

    public function test_wrong_account_cannot_accept_invitation(): void
    {
        $project = $this->publishedProject();
        $invitation = TeamInvitation::factory()->create(['project_id' => $project->id]);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.team.accept', [$project, $invitation]))
            ->assertForbidden();

        $this->assertSame(InvitationStatus::Pending, $invitation->fresh()->status);
        $this->assertSame(0, ProjectMember::count());
    }

    public function test_expired_invitation_cannot_be_accepted(): void
    {
        $project = $this->publishedProject();
        $invitation = TeamInvitation::factory()->create([
            'project_id' => $project->id,
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($invitation->user)
            ->post(route('projects.team.accept', [$project, $invitation]))
            ->assertSessionHasErrors('invitation');

        $this->assertSame(InvitationStatus::Expired, $invitation->fresh()->status);
        $this->assertFalse($project->managedBy($invitation->user));
    }

    public function test_owner_can_invite_and_revoke_but_members_cannot(): void
    {
        $project = $this->publishedProject();
        $member = User::factory()->create();
        ProjectMember::factory()->create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Maintainer,
            'status' => 'active',
        ]);
        $candidate = User::factory()->create();

        // Maintainers (non-owners) cannot invite.
        $this->actingAs($member)
            ->post(route('projects.team.invite', $project), ['email' => $candidate->email])
            ->assertForbidden();

        $this->actingAs($project->owner)
            ->post(route('projects.team.invite', $project), ['email' => $candidate->email])
            ->assertRedirect();
        $this->assertSame(1, TeamInvitation::count());

        // Unknown emails are rejected without leaking anything else.
        $this->actingAs($project->owner)
            ->post(route('projects.team.invite', $project), ['email' => 'nobody@example.test'])
            ->assertStatus(422);

        // Maintainers cannot revoke either.
        $membership = ProjectMember::where('user_id', $member->id)->firstOrFail();
        $this->actingAs($member)
            ->patch(route('projects.team.members.revoke', [$project, $membership]))
            ->assertForbidden();

        $this->actingAs($project->owner)
            ->patch(route('projects.team.members.revoke', [$project, $membership]))
            ->assertRedirect();

        $this->assertSame('revoked', $membership->fresh()->status);
        $this->assertFalse($project->managedBy($member));

        // The owner always retains access: revoking everyone cannot strand the project.
        $this->assertTrue($project->managedBy($project->owner));
    }

    public function test_ordinary_offer_acceptance_grants_no_privileges(): void
    {
        $project = $this->publishedProject();
        $applicant = User::factory()->create();
        $offer = ContributionOffer::factory()->create([
            'project_id' => $project->id,
            'user_id' => $applicant->id,
            'type' => OfferType::OfferHelp,
            'status' => OfferStatus::Pending,
        ]);

        $this->actingAs($project->owner)
            ->post(route('projects.offers.accept', [$project, $offer]))
            ->assertRedirect();

        $this->assertFalse($project->managedBy($applicant));
        $this->assertSame(0, TeamInvitation::count());
        $this->assertSame(0, ProjectMember::count());
    }

    public function test_team_page_shows_roles_and_cta(): void
    {
        $project = $this->publishedProject(['activity' => ProjectActivity::SeekingMaintainer]);
        $member = User::factory()->create(['name' => 'Team Maintainer Member']);
        ProjectMember::factory()->create(['project_id' => $project->id, 'user_id' => $member->id]);

        $this->get(route('projects.team.index', $project))
            ->assertOk()
            ->assertSee($project->owner->name, false)
            ->assertSee('Team Maintainer Member', false)
            ->assertSee('Maintainer', false);

        $this->actingAs(User::factory()->create())
            ->get(route('projects.show', $project))
            ->assertSee('Apply to maintain', false);

        // Managers do not see the CTA on their own project.
        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertDontSee('Apply to maintain', false);
    }

    public function test_offers_index_separates_maintainer_applications(): void
    {
        $project = $this->publishedProject(['activity' => ProjectActivity::SeekingMaintainer]);
        ContributionOffer::factory()->create([
            'project_id' => $project->id,
            'type' => OfferType::OfferHelp,
            'message' => 'General help message here.',
        ]);
        ContributionOffer::factory()->create([
            'project_id' => $project->id,
            'type' => OfferType::MaintainProject,
            'message' => 'Maintain application message here.',
        ]);

        $response = $this->actingAs($project->owner)->get(route('projects.offers.index', $project));
        $response->assertOk()
            ->assertSee('Contribution offers (1)', false)
            ->assertSee('Maintainer applications (1)', false)
            ->assertSee('General help message here.', false)
            ->assertSee('Maintain application message here.', false);

        // Applications render after the offers section heading.
        $html = $response->getContent();
        $this->assertLessThan(
            strpos($html, 'Maintain application message here.'),
            strpos($html, 'Maintainer applications (1)')
        );
    }
}
