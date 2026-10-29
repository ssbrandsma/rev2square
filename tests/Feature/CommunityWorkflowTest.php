<?php

namespace Tests\Feature;

use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Enums\ProjectActivity;
use App\Enums\TaskStatus;
use App\Models\ContributionOffer;
use App\Models\ContributorTask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function openTask(Project $project): ContributorTask
    {
        return ContributorTask::factory()->create(['project_id' => $project->id, 'status' => TaskStatus::Open]);
    }

    public function test_claim_requires_a_task(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.offers.store', $project), [
                'type' => OfferType::ClaimTask->value,
                'message' => 'I want this one.',
            ])
            ->assertSessionHasErrors('contributor_task_id');

        $this->assertSame(0, ContributionOffer::count());
    }

    public function test_claim_rejects_unavailable_tasks(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create();
        $completed = ContributorTask::factory()->create(['project_id' => $project->id, 'status' => TaskStatus::Completed]);
        $claimed = ContributorTask::factory()->create([
            'project_id' => $project->id,
            'status' => TaskStatus::Claimed,
            'assigned_to_user_id' => User::factory()->create()->id,
        ]);

        foreach ([$completed, $claimed] as $task) {
            $this->actingAs($user)
                ->post(route('projects.offers.store', $project), [
                    'type' => OfferType::ClaimTask->value,
                    'contributor_task_id' => $task->id,
                    'message' => 'Mine please.',
                ])
                ->assertSessionHasErrors('contributor_task_id');
        }

        $this->assertSame(0, ContributionOffer::count());
    }

    public function test_pending_claim_does_not_assign(): void
    {
        $project = Project::factory()->create();
        $task = $this->openTask($project);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.offers.store', $project), [
                'type' => OfferType::ClaimTask->value,
                'contributor_task_id' => $task->id,
                'message' => 'I can do this.',
            ])
            ->assertRedirect(route('projects.offers.show', [$project, ContributionOffer::firstOrFail()]));

        $this->assertSame(OfferStatus::Pending, ContributionOffer::firstOrFail()->status);
        $this->assertSame(TaskStatus::Open, $task->fresh()->status);
        $this->assertNull($task->fresh()->assigned_to_user_id);
    }

    public function test_help_may_reference_a_task_without_claiming(): void
    {
        $project = Project::factory()->create();
        $task = $this->openTask($project);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.offers.store', $project), [
                'type' => OfferType::OfferHelp->value,
                'contributor_task_id' => $task->id,
                'message' => 'Happy to help around this.',
            ])
            ->assertRedirect(route('projects.offers.show', [$project, ContributionOffer::firstOrFail()]));

        $this->assertSame(TaskStatus::Open, $task->fresh()->status);
        $this->assertNull($task->fresh()->assigned_to_user_id);
    }

    public function test_duplicate_pending_request_is_rejected(): void
    {
        $project = Project::factory()->create();
        $task = $this->openTask($project);
        $user = User::factory()->create();
        $payload = [
            'type' => OfferType::ClaimTask->value,
            'contributor_task_id' => $task->id,
            'message' => 'First.',
        ];

        $this->actingAs($user)->post(route('projects.offers.store', $project), $payload)->assertRedirect();
        $this->actingAs($user)->post(route('projects.offers.store', $project), $payload + ['message' => 'Second.'])
            ->assertSessionHasErrors('type');

        $this->assertSame(1, ContributionOffer::count());
    }

    public function test_two_users_may_claim_the_same_task(): void
    {
        $project = Project::factory()->create();
        $task = $this->openTask($project);

        foreach ([User::factory()->create(), User::factory()->create()] as $user) {
            $this->actingAs($user)
                ->post(route('projects.offers.store', $project), [
                    'type' => OfferType::ClaimTask->value,
                    'contributor_task_id' => $task->id,
                    'message' => 'I can do this.',
                ])
                ->assertRedirect();
        }

        $this->assertSame(2, ContributionOffer::count());
    }

    public function test_accepting_a_claim_assigns_the_task(): void
    {
        $project = Project::factory()->create();
        $task = $this->openTask($project);
        $user = User::factory()->create();
        $offer = ContributionOffer::factory()->create([
            'project_id' => $project->id,
            'contributor_task_id' => $task->id,
            'user_id' => $user->id,
            'type' => OfferType::ClaimTask,
        ]);

        $this->actingAs($project->owner)
            ->post(route('projects.offers.accept', [$project, $offer]), ['decision_reason' => 'Great fit.'])
            ->assertRedirect();

        $this->assertSame(OfferStatus::Accepted, $offer->fresh()->status);
        $this->assertSame('Great fit.', $offer->fresh()->decision_reason);
        $this->assertSame(TaskStatus::Claimed, $task->fresh()->status);
        $this->assertSame($user->id, $task->fresh()->assigned_to_user_id);
    }

    public function test_second_accept_loses_the_assignment_race(): void
    {
        $project = Project::factory()->create();
        $task = $this->openTask($project);
        $first = ContributionOffer::factory()->create([
            'project_id' => $project->id, 'contributor_task_id' => $task->id, 'type' => OfferType::ClaimTask,
        ]);
        $second = ContributionOffer::factory()->create([
            'project_id' => $project->id, 'contributor_task_id' => $task->id, 'type' => OfferType::ClaimTask,
        ]);

        $this->actingAs($project->owner)->post(route('projects.offers.accept', [$project, $first]))->assertRedirect();
        $this->actingAs($project->owner)
            ->post(route('projects.offers.accept', [$project, $second]))
            ->assertSessionHasErrors('offer');

        $this->assertSame($first->user_id, $task->fresh()->assigned_to_user_id);
        $this->assertSame(OfferStatus::Pending, $second->fresh()->status);
    }

    public function test_message_is_preserved_on_validation_error(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.offers.store', $project), [
                'type' => OfferType::ClaimTask->value,
                'message' => 'Carefully written pitch.',
            ])
            ->assertSessionHasErrors('contributor_task_id');

        $this->actingAs($user)->get(route('projects.offers.create', $project))
            ->assertOk()
            ->assertSee('Carefully written pitch.', false);
    }

    public function test_archived_project_rejects_new_offers_but_keeps_review(): void
    {
        $project = Project::factory()->create(['activity' => ProjectActivity::Archived]);
        $user = User::factory()->create();
        $pending = ContributionOffer::factory()->create(['project_id' => $project->id]);

        $this->actingAs($user)->get(route('projects.offers.create', $project))
            ->assertOk()
            ->assertSee('archived', false)
            ->assertDontSee('Send offer', false);

        $this->actingAs($user)
            ->post(route('projects.offers.store', $project), [
                'type' => OfferType::OfferHelp->value,
                'message' => 'Too late.',
            ])
            ->assertSessionHasErrors('type');

        $this->assertSame(1, ContributionOffer::count());

        // Existing backlog stays reviewable.
        $this->actingAs($project->owner)
            ->post(route('projects.offers.accept', [$project, $pending]))
            ->assertRedirect();
        $this->assertSame(OfferStatus::Accepted, $pending->fresh()->status);
    }

    public function test_paused_project_rejects_new_offers_and_reactivation_reopens(): void
    {
        $project = Project::factory()->create(['activity' => ProjectActivity::Paused]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.offers.store', $project), [
                'type' => OfferType::OfferHelp->value,
                'message' => 'Hello?',
            ])
            ->assertSessionHasErrors('type');
        $this->assertSame(0, ContributionOffer::count());

        $project->forceFill(['activity' => ProjectActivity::Active])->save();

        $this->actingAs($user)
            ->post(route('projects.offers.store', $project), [
                'type' => OfferType::OfferHelp->value,
                'message' => 'Hello again.',
            ])
            ->assertRedirect();
        $this->assertSame(1, ContributionOffer::count());
    }

    public function test_draft_project_is_closed_to_outsiders(): void
    {
        $project = Project::factory()->draft()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('projects.offers.create', $project))->assertForbidden();
        $this->actingAs($user)->post(route('projects.offers.store', $project), [
            'type' => OfferType::OfferHelp->value,
            'message' => 'Nope.',
        ])->assertForbidden();
    }

    public function test_show_page_entry_points_follow_lifecycle(): void
    {
        $open = Project::factory()->create();

        $this->get(route('projects.show', $open))
            ->assertOk()
            ->assertSee('Offer help →', false);

        $archived = Project::factory()->create(['activity' => ProjectActivity::Archived]);

        $this->get(route('projects.show', $archived))
            ->assertOk()
            ->assertSee('archived', false)
            ->assertDontSee('Offer help →', false);
    }

    public function test_draft_hides_task_offer_links_but_keeps_details(): void
    {
        $project = Project::factory()->draft()->create();
        $task = ContributorTask::factory()->create(['project_id' => $project->id]);

        $this->actingAs($project->owner)->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('This project is not published.')
            ->assertSee('Details →', false)
            ->assertDontSee('Offer help →', false)
            ->assertDontSee(route('projects.offers.create', [$project, 'task' => $task->id]), false);
    }

    public function test_open_task_offers_help_on_published_projects(): void
    {
        $project = Project::factory()->create();
        $task = ContributorTask::factory()->create(['project_id' => $project->id]);

        $this->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee(route('projects.offers.create', [$project, 'task' => $task->id]), false);
    }

    public function test_empty_roadmap_names_both_creation_paths(): void
    {
        $project = Project::factory()->create();

        $this->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Maintainers can add them directly, or run an AI analysis and approve the roadmap to generate them.');
    }

    public function test_anonymous_contribute_returns_after_login(): void
    {
        $project = Project::factory()->create();

        $this->get(route('projects.offers.create', $project))
            ->assertRedirect(route('login'))
            ->assertSessionHas('url.intended', route('projects.offers.create', $project));
    }

    public function test_dashboard_lists_applications_with_next_steps(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create();
        ContributionOffer::factory()->create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => OfferStatus::Pending,
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('My applications', false)
            ->assertSee($project->title, false)
            ->assertSee('Waiting for maintainer review', false)
            ->assertSee('Withdraw', false);
    }

    public function test_contributor_can_withdraw_pending_offer(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create();
        $offer = ContributionOffer::factory()->create(['project_id' => $project->id, 'user_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('projects.offers.withdraw', [$project, $offer]))
            ->assertRedirect();
        $this->assertSame(OfferStatus::Withdrawn, $offer->fresh()->status);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.offers.withdraw', [$project, $offer]))
            ->assertForbidden();
    }

    public function test_offers_counter_names_pending_count(): void
    {
        $project = Project::factory()->create();
        ContributionOffer::factory()->count(2)->create(['project_id' => $project->id, 'status' => OfferStatus::Pending]);
        ContributionOffer::factory()->create(['project_id' => $project->id, 'status' => OfferStatus::Accepted]);

        $this->actingAs($project->owner)->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Offers — 2 pending', false);

        $empty = Project::factory()->create();

        $this->actingAs($empty->owner)->get(route('projects.show', $empty))
            ->assertOk()
            ->assertSee('>Offers<', false);
    }
}
