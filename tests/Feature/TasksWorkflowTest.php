<?php

namespace Tests\Feature;

use App\Enums\ContentOrigin;
use App\Enums\PublicationStatus;
use App\Enums\ReviewStatus;
use App\Enums\TaskStatus;
use App\Models\AnalysisReport;
use App\Models\AuditEvent;
use App\Models\ContributorTask;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use App\Services\Revival\ProjectPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TasksWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_maintainer_can_create_task_with_human_provenance(): void
    {
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->post(route('projects.tasks.store', $project), [
                'title' => 'Desolder the flash chip',
                'description' => 'Careful work.',
                'definition_of_done' => 'Chip removed intact.',
                'skills' => 'soldering, patience',
                'requires_hardware' => '1',
            ])
            ->assertRedirect();

        $task = ContributorTask::firstOrFail();
        $this->assertSame('Desolder the flash chip', $task->title);
        $this->assertSame(TaskStatus::Open, $task->status);
        $this->assertSame(ContentOrigin::Human, $task->origin);
        $this->assertSame(['soldering', 'patience'], $task->skills);
        $this->assertTrue($task->requires_hardware);
        $this->assertTrue(AuditEvent::where('event', 'task.created')->exists());
    }

    public function test_non_maintainer_cannot_create_tasks(): void
    {
        $project = Project::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('projects.tasks.store', $project), ['title' => 'Nope'])
            ->assertForbidden();

        $this->assertSame(0, ContributorTask::count());
    }

    public function test_duplicate_task_title_is_rejected(): void
    {
        $project = Project::factory()->create();
        ContributorTask::factory()->create(['project_id' => $project->id, 'title' => 'Flash the firmware']);

        $this->actingAs($project->owner)
            ->post(route('projects.tasks.store', $project), ['title' => 'flash the FIRMWARE'])
            ->assertStatus(422);

        $this->assertSame(1, ContributorTask::count());
    }

    public function test_task_detail_page_shows_workflow_and_history(): void
    {
        $project = Project::factory()->create(['publication_status' => PublicationStatus::Published]);
        $task = ContributorTask::factory()->create([
            'project_id' => $project->id,
            'assigned_to_user_id' => $project->owner_id,
            'status' => TaskStatus::InProgress,
        ]);

        $this->get(route('projects.tasks.show', [$project, $task]))
            ->assertOk()
            ->assertSee($task->title, false)
            ->assertSee('In progress')
            ->assertSee('History', false);
    }

    public function test_full_task_lifecycle_with_handoff(): void
    {
        $project = Project::factory()->create();
        $assignee = User::factory()->create();
        $task = ContributorTask::factory()->create(['project_id' => $project->id]);

        // Maintainer assigns -> Open becomes Claimed.
        $this->actingAs($project->owner)
            ->patch(route('projects.tasks.assign', [$project, $task]), ['assignee_id' => $assignee->id])
            ->assertRedirect();
        $this->assertSame(TaskStatus::Claimed, $task->fresh()->status);
        $this->assertSame($assignee->id, $task->fresh()->assigned_to_user_id);

        // Assignee starts work.
        $this->actingAs($assignee)
            ->patch(route('projects.tasks.transition', [$project, $task]), ['status' => TaskStatus::InProgress->value])
            ->assertRedirect();
        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);

        // Assignee submits for verification.
        $this->actingAs($assignee)
            ->patch(route('projects.tasks.transition', [$project, $task]), ['status' => TaskStatus::ReadyForVerification->value])
            ->assertRedirect();
        $this->assertSame(TaskStatus::ReadyForVerification, $task->fresh()->status);

        // Assignee cannot verify their own work.
        $this->actingAs($assignee)
            ->patch(route('projects.tasks.transition', [$project, $task]), ['status' => TaskStatus::VerifiedComplete->value])
            ->assertForbidden();

        // Maintainer requests changes without a note -> rejected.
        $this->actingAs($project->owner)
            ->patch(route('projects.tasks.transition', [$project, $task]), ['status' => TaskStatus::InProgress->value])
            ->assertSessionHasErrors('note');

        // Maintainer requests changes with a note.
        $this->actingAs($project->owner)
            ->patch(route('projects.tasks.transition', [$project, $task]), [
                'status' => TaskStatus::InProgress->value,
                'note' => 'Please add photos of the wiring.',
            ])
            ->assertRedirect();
        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);

        // Resubmit and verify.
        $this->actingAs($assignee)
            ->patch(route('projects.tasks.transition', [$project, $task]), ['status' => TaskStatus::ReadyForVerification->value])
            ->assertRedirect();
        $this->actingAs($project->owner)
            ->patch(route('projects.tasks.transition', [$project, $task]), ['status' => TaskStatus::VerifiedComplete->value])
            ->assertRedirect();
        $this->assertSame(TaskStatus::VerifiedComplete, $task->fresh()->status);

        $this->assertSame(5, AuditEvent::where('event', 'task.status')->count());
        $this->assertDatabaseHas('audit_events', [
            'event' => 'task.assigned',
            'subject_type' => ContributorTask::class,
            'subject_id' => $task->id,
        ]);
    }

    public function test_illegal_transition_is_rejected(): void
    {
        $project = Project::factory()->create();
        $task = ContributorTask::factory()->create(['project_id' => $project->id]);

        $this->actingAs($project->owner)
            ->patch(route('projects.tasks.transition', [$project, $task]), ['status' => TaskStatus::VerifiedComplete->value])
            ->assertSessionHasErrors('status');

        $this->assertSame(TaskStatus::Open, $task->fresh()->status);
    }

    public function test_claim_transition_requires_assignee(): void
    {
        $project = Project::factory()->create();
        $task = ContributorTask::factory()->create(['project_id' => $project->id]);

        $this->actingAs($project->owner)
            ->patch(route('projects.tasks.transition', [$project, $task]), ['status' => TaskStatus::Claimed->value])
            ->assertSessionHasErrors('status');
    }

    public function test_unrelated_user_cannot_transition_tasks(): void
    {
        $project = Project::factory()->create();
        $task = ContributorTask::factory()->create([
            'project_id' => $project->id,
            'status' => TaskStatus::InProgress,
            'assigned_to_user_id' => User::factory()->create()->id,
        ]);

        $this->actingAs(User::factory()->create())
            ->patch(route('projects.tasks.transition', [$project, $task]), ['status' => TaskStatus::Blocked->value])
            ->assertForbidden();
    }

    public function test_retired_tasks_are_hidden_but_restorable(): void
    {
        $project = Project::factory()->create(['publication_status' => PublicationStatus::Published]);
        $task = ContributorTask::factory()->create(['project_id' => $project->id, 'title' => 'Obsolete probe task']);

        $this->actingAs($project->owner)
            ->patch(route('projects.tasks.transition', [$project, $task]), ['status' => TaskStatus::Retired->value])
            ->assertRedirect();

        $this->get(route('projects.show', $project))
            ->assertOk()
            ->assertDontSee('Obsolete probe task', false)
            ->assertSee('1 retired task hidden', false);

        $this->get(route('projects.show', [$project, 'show_retired' => 1]))
            ->assertOk()
            ->assertSee('Obsolete probe task', false);

        // Restore.
        $this->actingAs($project->owner)
            ->patch(route('projects.tasks.transition', [$project, $task]), ['status' => TaskStatus::Open->value])
            ->assertRedirect();
        $this->assertSame(TaskStatus::Open, $task->fresh()->status);
    }

    public function test_milestone_crud_and_retire(): void
    {
        $project = Project::factory()->create(['publication_status' => PublicationStatus::Published]);

        $this->actingAs($project->owner)
            ->post(route('projects.milestones.store', $project), [
                'title' => 'First boot',
                'description' => 'Get the device to boot.',
                'target_date' => '2027-03-01',
            ])
            ->assertRedirect();

        $milestone = Milestone::firstOrFail();
        $this->assertSame(ContentOrigin::Human, $milestone->origin);

        $this->actingAs($project->owner)
            ->patch(route('projects.milestones.update', [$project, $milestone]), [
                'title' => 'First boot attempt',
                'description' => 'Updated.',
            ])
            ->assertRedirect();
        $this->assertSame('First boot attempt', $milestone->fresh()->title);

        $this->actingAs($project->owner)
            ->patch(route('projects.milestones.retire', [$project, $milestone]))
            ->assertRedirect();
        $this->assertNotNull($milestone->fresh()->retired_at);

        $this->get(route('projects.show', $project))->assertDontSee('First boot attempt', false);

        $this->actingAs($project->owner)
            ->patch(route('projects.milestones.restore', [$project, $milestone]))
            ->assertRedirect();
        $this->assertNull($milestone->fresh()->retired_at);
    }

    public function test_milestones_can_be_ordered_explicitly(): void
    {
        $project = Project::factory()->create(['publication_status' => PublicationStatus::Published]);
        $first = Milestone::factory()->create(['project_id' => $project->id, 'title' => 'First milestone', 'sort_order' => 10]);
        $second = Milestone::factory()->create(['project_id' => $project->id, 'title' => 'Second milestone', 'sort_order' => 20]);

        $this->actingAs($project->owner)
            ->patch(route('projects.milestones.update', [$project, $second]), [
                'title' => 'Second milestone',
                'sort_order' => 5,
            ])
            ->assertRedirect();

        $this->assertSame(5, $second->fresh()->sort_order);

        $response = $this->get(route('projects.show', $project));
        $response->assertOk();
        $this->assertLessThan(
            strpos($response->getContent(), 'First milestone'),
            strpos($response->getContent(), 'Second milestone')
        );
        $this->assertTrue(strpos($response->getContent(), 'Second milestone') !== false);
    }

    public function test_project_page_shows_provenance_and_manage_links(): void
    {
        $project = Project::factory()->create(['publication_status' => PublicationStatus::Published]);
        ContributorTask::factory()->create(['project_id' => $project->id, 'origin' => ContentOrigin::AI]);
        ContributorTask::factory()->create(['project_id' => $project->id, 'origin' => ContentOrigin::Human]);

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Drafted by AI', false)
            ->assertSee('Added by maintainer', false)
            ->assertSee('Add task', false)
            ->assertSee('Add milestone', false);

        $this->actingAs(User::factory()->create())
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertDontSee('Add task', false);
    }

    public function test_manual_entries_survive_ai_republish(): void
    {
        $project = Project::factory()->create();
        $manual = ContributorTask::factory()->create([
            'project_id' => $project->id,
            'title' => 'Manual check task',
            'origin' => ContentOrigin::Human,
            'manually_edited' => true,
            'import_key' => null,
        ]);

        $report = AnalysisReport::factory()->create([
            'project_id' => $project->id,
            'review_status' => ReviewStatus::Approved,
            'payload_json' => [
                'milestones' => [['id' => 'm1', 'title' => 'AI milestone', 'task_ids' => ['t1']]],
                'contributor_tasks' => [[
                    'id' => 't1',
                    'title' => 'AI drafted task',
                    'skill_tags' => ['research'],
                    'definition_of_done' => 'Done.',
                    'requires_hardware' => false,
                ]],
            ],
        ]);

        app(ProjectPublisher::class)->publish($report, $project->owner);

        $this->assertSame('Manual check task', $manual->fresh()->title);
        $this->assertSame(ContentOrigin::Human, $manual->fresh()->origin);
        $this->assertSame(2, $project->tasks()->count());
    }
}
