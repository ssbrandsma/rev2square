<?php

namespace Tests\Feature;

use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Models\AuditEvent;
use App\Models\ContributionOffer;
use App\Models\ContributorTask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContributionOfferTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $project = Project::factory()->create();

        $this->get(route('projects.offers.create', $project))->assertRedirect(route('login'));
        $this->post(route('projects.offers.store', $project), [])->assertRedirect(route('login'));
        $this->get(route('projects.offers.index', $project))->assertRedirect(route('login'));
    }

    public function test_contributor_can_submit_general_and_task_offers(): void
    {
        $project = Project::factory()->create();
        $task = ContributorTask::factory()->create(['project_id' => $project->id]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.offers.store', $project), [
                'type' => OfferType::ClaimTask->value,
                'contributor_task_id' => $task->id,
                'message' => 'I have the hardware and can run this test.',
            ])
            ->assertRedirect(route('projects.offers.show', [$project, ContributionOffer::firstOrFail()]));

        $offer = ContributionOffer::firstOrFail();
        $this->assertSame($project->id, $offer->project_id);
        $this->assertSame($task->id, $offer->contributor_task_id);
        $this->assertSame(OfferType::ClaimTask, $offer->type);
        $this->assertSame(OfferStatus::Pending, $offer->status);
        $this->assertDatabaseHas('audit_events', ['event' => 'offer.submitted', 'project_id' => $project->id]);
    }

    public function test_task_must_belong_to_project(): void
    {
        $project = Project::factory()->create();
        $otherTask = ContributorTask::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.offers.store', $project), [
                'type' => OfferType::OfferHelp->value,
                'contributor_task_id' => $otherTask->id,
                'message' => 'Trying to link elsewhere.',
            ])
            ->assertSessionHasErrors('contributor_task_id');

        $this->assertSame(0, ContributionOffer::count());
    }

    public function test_maintainer_can_review_offers(): void
    {
        $project = Project::factory()->create();
        $offer = ContributionOffer::factory()->create(['project_id' => $project->id]);

        $this->actingAs($project->owner)
            ->get(route('projects.offers.index', $project))
            ->assertOk()
            ->assertSee($offer->message, false);

        $this->actingAs($project->owner)
            ->post(route('projects.offers.accept', [$project, $offer]))
            ->assertRedirect();

        $this->assertSame(OfferStatus::Accepted, $offer->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['event' => 'offer.accepted']);

        // Decided offers cannot be re-decided.
        $this->actingAs($project->owner)
            ->post(route('projects.offers.decline', [$project, $offer]))
            ->assertSessionHasErrors('offer');
    }

    public function test_accepted_claim_names_the_assignee_in_history(): void
    {
        $project = Project::factory()->create();
        $task = ContributorTask::factory()->create(['project_id' => $project->id]);
        $contributor = User::factory()->create(['name' => 'QA Contributor']);
        $contributor->forceFill(['handle' => 'qa-contributor'])->save();
        $offer = ContributionOffer::factory()->create([
            'project_id' => $project->id,
            'user_id' => $contributor->id,
            'type' => OfferType::ClaimTask,
            'contributor_task_id' => $task->id,
        ]);

        $this->actingAs($project->owner)
            ->post(route('projects.offers.accept', [$project, $offer]))
            ->assertRedirect();

        $event = AuditEvent::where('event', 'task.assigned')->firstOrFail();
        $this->assertSame('QA Contributor', $event->metadata['assignee']);
        $this->assertSame($contributor->id, $event->metadata['assignee_id']);

        $this->actingAs($project->owner)->get(route('projects.tasks.show', [$project, $task]))
            ->assertOk()
            ->assertSee('assigned this task to', false)
            ->assertSee('QA Contributor')
            ->assertSee($contributor->profileUrl(), false)
            ->assertDontSee('>someone<', false);

        $this->actingAs($project->owner)->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('assigned a task to', false)
            ->assertSee('QA Contributor')
            ->assertDontSee('>someone<', false);
    }

    public function test_history_resolves_old_events_and_keeps_deleted_fallback(): void
    {
        $project = Project::factory()->create();
        $task = ContributorTask::factory()->create(['project_id' => $project->id]);
        $contributor = User::factory()->create(['name' => 'Legacy Worker']);

        // Old event shape: id but no recorded name (e.g. pre-fix rows).
        AuditEvent::record('task.assigned', $project->owner, $project, $task, [
            'assignee_id' => $contributor->id,
        ]);
        // Deleted user: neither name nor lookup resolves.
        AuditEvent::record('task.assigned', $project->owner, $project, $task, [
            'assignee_id' => 999999,
        ]);

        $this->actingAs($project->owner)->get(route('projects.tasks.show', [$project, $task]))
            ->assertOk()
            ->assertSee('Legacy Worker')
            ->assertSee('>someone<', false);
    }

    public function test_stranger_cannot_review_offers(): void
    {
        $project = Project::factory()->create();
        $offer = ContributionOffer::factory()->create(['project_id' => $project->id]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('projects.offers.index', $project))->assertForbidden();
        $this->actingAs($stranger)->post(route('projects.offers.accept', [$project, $offer]))->assertForbidden();
    }

    public function test_no_contact_information_is_exposed(): void
    {
        $project = Project::factory()->create();
        $contributor = User::factory()->create(['email' => 'contributor-private@example.test']);
        ContributionOffer::factory()->create(['project_id' => $project->id, 'user_id' => $contributor->id]);

        // Maintainer view shows the name but never the email address.
        $this->actingAs($project->owner)
            ->get(route('projects.offers.index', $project))
            ->assertOk()
            ->assertSee($contributor->name, false)
            ->assertDontSee('contributor-private@example.test', false);

        // Public pages never leak offer details.
        $this->get(route('projects.show', $project))
            ->assertOk()
            ->assertDontSee('contributor-private@example.test', false);
    }

    public function test_cross_project_offer_access_is_blocked(): void
    {
        $project = Project::factory()->create();
        $offer = ContributionOffer::factory()->create(['project_id' => $project->id]);
        $other = Project::factory()->create();

        $this->actingAs($other->owner)
            ->post(route('projects.offers.accept', [$other, $offer]))
            ->assertNotFound();

        $this->actingAs($project->owner)
            ->post(route('projects.offers.accept', [$other, $offer]))
            ->assertForbidden();
    }
}
