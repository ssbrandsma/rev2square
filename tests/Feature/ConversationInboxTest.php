<?php

namespace Tests\Feature;

use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Enums\PublicationStatus;
use App\Enums\TaskStatus;
use App\Models\ContributionOffer;
use App\Models\ContributorTask;
use App\Models\OfferMessage;
use App\Models\Project;
use App\Models\User;
use App\Notifications\OfferReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConversationInboxTest extends TestCase
{
    use RefreshDatabase;

    private function publishedProject(): Project
    {
        return Project::factory()->create(['publication_status' => PublicationStatus::Published]);
    }

    private function offer(Project $project, User $applicant, array $overrides = []): ContributionOffer
    {
        return ContributionOffer::factory()->create([
            'project_id' => $project->id,
            'user_id' => $applicant->id,
            'type' => OfferType::OfferHelp,
            'status' => OfferStatus::Pending,
            ...$overrides,
        ]);
    }

    public function test_application_thread_is_private_to_applicant_and_maintainers(): void
    {
        $project = $this->publishedProject();
        $applicant = User::factory()->create();
        $offer = $this->offer($project, $applicant);

        $this->actingAs($applicant)
            ->get(route('projects.offers.show', [$project, $offer]))
            ->assertOk()
            ->assertSee('Private discussion', false)
            ->assertSee($offer->message, false);

        $this->actingAs($project->owner)
            ->get(route('projects.offers.show', [$project, $offer]))
            ->assertOk();

        $this->actingAs(User::factory()->create())
            ->get(route('projects.offers.show', [$project, $offer]))
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->post(route('projects.offers.message', [$project, $offer]), ['body' => 'Snooping.'])
            ->assertForbidden();

        $this->assertSame(0, OfferMessage::count());
    }

    public function test_maintainer_and_applicant_can_discuss_with_notifications(): void
    {
        $project = $this->publishedProject();
        $applicant = User::factory()->create();
        $offer = $this->offer($project, $applicant);

        // Maintainer asks for clarification.
        $this->actingAs($project->owner)
            ->post(route('projects.offers.message', [$project, $offer]), [
                'body' => 'Which revision of the board do you have?',
            ])
            ->assertRedirect();

        $this->assertSame(1, OfferMessage::count());
        $this->assertSame(1, $applicant->notifications()->count());
        $this->assertSame(
            route('projects.offers.show', [$project, $offer]),
            $applicant->notifications()->firstOrFail()->data['url']
        );

        // Applicant replies; the owner is notified, not the applicant.
        $this->actingAs($applicant)
            ->post(route('projects.offers.message', [$project, $offer]), ['body' => 'Revision C, firmware 2.1.'])
            ->assertRedirect();

        $this->assertSame(2, OfferMessage::count());
        $this->assertSame(1, $applicant->notifications()->count());
        $this->assertSame(1, $project->owner->notifications()->count());

        $this->actingAs($applicant)
            ->get(route('projects.offers.show', [$project, $offer]))
            ->assertSee('Which revision of the board do you have?', false)
            ->assertSee('Revision C, firmware 2.1.', false);
    }

    public function test_decision_with_reason_notifies_applicant(): void
    {
        $project = $this->publishedProject();
        $applicant = User::factory()->create();
        $task = ContributorTask::factory()->create(['project_id' => $project->id, 'status' => TaskStatus::Open]);
        $offer = $this->offer($project, $applicant, [
            'type' => OfferType::ClaimTask,
            'contributor_task_id' => $task->id,
        ]);

        $this->actingAs($project->owner)
            ->post(route('projects.offers.accept', [$project, $offer]), [
                'decision_reason' => 'Board experience confirmed. Welcome aboard!',
            ])
            ->assertRedirect();

        // Exactly two notifications: decision + task assignment. No duplicates.
        $this->assertSame(2, $applicant->notifications()->count());
        $this->assertSame(
            'Board experience confirmed. Welcome aboard!',
            $offer->fresh()->decision_reason
        );

        $this->actingAs($applicant)
            ->get(route('projects.offers.show', [$project, $offer]))
            ->assertSee('Welcome aboard!', false)
            ->assertSee('Accepted', false);
    }

    public function test_applicant_can_reopen_declined_request(): void
    {
        $project = $this->publishedProject();
        $applicant = User::factory()->create();
        $offer = $this->offer($project, $applicant);

        $this->actingAs($project->owner)
            ->post(route('projects.offers.decline', [$project, $offer]), ['decision_reason' => 'Not yet.'])
            ->assertRedirect();
        $this->assertSame(OfferStatus::Declined, $offer->fresh()->status);

        // Strangers cannot reopen.
        $this->actingAs(User::factory()->create())
            ->post(route('projects.offers.reopen', [$project, $offer]))
            ->assertForbidden();

        $this->actingAs($applicant)
            ->post(route('projects.offers.reopen', [$project, $offer]))
            ->assertRedirect();

        $this->assertSame(OfferStatus::Pending, $offer->fresh()->status);
        $this->assertNull($offer->fresh()->decision_reason);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'offer.reopened',
            'subject_type' => ContributionOffer::class,
            'subject_id' => $offer->id,
        ]);

        // Reopened requests return to the maintainer queue.
        $this->assertSame(1, $project->owner->notifications()->where('type', OfferReceivedNotification::class)->count());
    }

    public function test_maintainer_can_reconsider_withdrawn_request(): void
    {
        $project = $this->publishedProject();
        $applicant = User::factory()->create();
        $offer = $this->offer($project, $applicant, ['status' => OfferStatus::Withdrawn]);

        $this->actingAs($project->owner)
            ->post(route('projects.offers.reopen', [$project, $offer]))
            ->assertRedirect();

        $this->assertSame(OfferStatus::Pending, $offer->fresh()->status);
    }

    public function test_accepted_request_cannot_be_reopened(): void
    {
        $project = $this->publishedProject();
        $applicant = User::factory()->create();
        $offer = $this->offer($project, $applicant, ['status' => OfferStatus::Accepted]);

        $this->actingAs($applicant)
            ->post(route('projects.offers.reopen', [$project, $offer]))
            ->assertSessionHasErrors('offer');

        $this->assertSame(OfferStatus::Accepted, $offer->fresh()->status);
    }

    public function test_inbox_lists_marks_and_links_notifications(): void
    {
        $project = $this->publishedProject();
        $applicant = User::factory()->create();
        $task = ContributorTask::factory()->create([
            'project_id' => $project->id,
            'status' => TaskStatus::InProgress,
            'assigned_to_user_id' => $applicant->id,
        ]);

        // Maintainer verifies -> assignee notified.
        $this->actingAs($project->owner)
            ->patch(route('projects.tasks.transition', [$project, $task]), ['status' => TaskStatus::ReadyForVerification->value])
            ->assertRedirect();

        $this->actingAs($applicant)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertSee('Task update:', false);

        $notification = $applicant->notifications()->firstOrFail();
        $this->assertNull($notification->read_at);

        // Header shows the unread count with well-formed markup.
        $header = $this->actingAs($applicant)->get(route('projects.show', $project));
        $header->assertSee('Inbox, 1 unread', false);
        $this->assertStringContainsString(
            'aria-label="Inbox, 1 unread">Inbox <span',
            $header->getContent()
        );

        // Opening the notification marks it read and lands on the task.
        $this->actingAs($applicant)
            ->get(route('inbox.read', $notification->id))
            ->assertRedirect(route('projects.tasks.show', [$project, $task]));
        $this->assertNotNull($notification->fresh()->read_at);

        // Off-site targets never redirect away.
        $evil = $applicant->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'tampered',
            'data' => ['url' => 'https://evil.example.test/steal'],
        ]);
        $this->actingAs($applicant)
            ->get(route('inbox.read', $evil->id))
            ->assertRedirect(route('inbox.index'));

        $this->actingAs($applicant)
            ->patch(route('inbox.read-all'))
            ->assertRedirect();
        $this->assertSame(0, $applicant->unreadNotifications()->count());
    }

    public function test_verification_request_notifies_maintainers_not_actor(): void
    {
        $project = $this->publishedProject();
        $assignee = User::factory()->create();
        $task = ContributorTask::factory()->create([
            'project_id' => $project->id,
            'status' => TaskStatus::InProgress,
            'assigned_to_user_id' => $assignee->id,
        ]);

        $this->actingAs($assignee)
            ->patch(route('projects.tasks.transition', [$project, $task]), ['status' => TaskStatus::ReadyForVerification->value])
            ->assertRedirect();

        // Actor is not notified about their own move; the owner is.
        $this->assertSame(0, $assignee->notifications()->count());
        $this->assertSame(1, $project->owner->notifications()->count());
    }

    public function test_task_assignment_notifies_assignee_once(): void
    {
        $project = $this->publishedProject();
        $assignee = User::factory()->create();
        $task = ContributorTask::factory()->create(['project_id' => $project->id]);

        $this->actingAs($project->owner)
            ->patch(route('projects.tasks.assign', [$project, $task]), ['assignee_id' => $assignee->id])
            ->assertRedirect();

        $this->assertSame(1, $assignee->notifications()->count());
        $this->assertSame(
            route('projects.tasks.show', [$project, $task]),
            $assignee->notifications()->firstOrFail()->data['url']
        );
    }
}
