<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Enums\PublicationStatus;
use App\Enums\TaskStatus;
use App\Models\ChatMessage;
use App\Models\ContributorTask;
use App\Models\DiscussionPost;
use App\Models\Observation;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\TestReport;
use App\Models\User;
use App\Services\Community\TaskWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContributionsTest extends TestCase
{
    use RefreshDatabase;

    private function publishedProject(array $overrides = []): Project
    {
        return Project::factory()->create([
            'publication_status' => PublicationStatus::Published,
            ...$overrides,
        ]);
    }

    public function test_observations_and_reports_appear_with_review_status(): void
    {
        $project = $this->publishedProject();
        $author = User::factory()->create();

        $observation = Observation::factory()->for($project, 'project')->for($author, 'author')->create([
            'title' => 'Volume knob crackle observation',
        ]);
        TestReport::factory()->for($project, 'project')->for($author, 'author')->create([
            'title' => 'Cold boot test report',
        ]);

        $content = $this->get($author->profileUrl())->assertOk()->getContent();
        $this->assertStringContainsString('Volume knob crackle observation', $content);
        $this->assertStringContainsString('Cold boot test report', $content);
        $this->assertStringContainsString('Pending review', $content);

        $this->actingAs($project->owner)
            ->patch(route('projects.observations.verify', [$project, $observation]), ['verified' => true])
            ->assertRedirect();

        $content = $this->get($author->profileUrl())->assertOk()->getContent();
        $this->assertStringContainsString('Reviewed', $content);
    }

    public function test_task_assignment_completion_and_reopen_track_status(): void
    {
        $project = $this->publishedProject();
        $worker = User::factory()->create();
        $task = ContributorTask::factory()->for($project, 'project')->create([
            'title' => 'Recap the power supply',
            'status' => TaskStatus::Claimed,
            'assigned_to_user_id' => $worker->id,
        ]);

        $content = $this->get($worker->profileUrl())->assertOk()->getContent();
        $this->assertStringContainsString('Working on <span class="text-zinc-400">(1)</span>', $content);
        $this->assertStringContainsString('Recap the power supply', $content);

        TaskWorkflow::transition($task, TaskStatus::InProgress, $worker);
        TaskWorkflow::transition($task, TaskStatus::ReadyForVerification, $worker);
        TaskWorkflow::transition($task, TaskStatus::VerifiedComplete, $project->owner);

        $content = $this->get($worker->profileUrl())->assertOk()->getContent();
        $this->assertStringContainsString('Task completed &amp; reviewed', $content);

        // Reopening updates the status without erasing the record.
        TaskWorkflow::transition($task, TaskStatus::InProgress, $project->owner, 'Regression found.');

        $content = $this->get($worker->profileUrl())->assertOk()->getContent();
        $this->assertStringContainsString('Recap the power supply', $content);
        $this->assertStringContainsString('In progress', $content);
        $this->assertStringNotContainsString('Task completed &amp; reviewed', $content);
    }

    public function test_completed_credit_survives_reassignment_and_counts_once(): void
    {
        $project = $this->publishedProject();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $task = ContributorTask::factory()->for($project, 'project')->create([
            'title' => 'Document the pinout',
            'status' => TaskStatus::Open,
        ]);

        TaskWorkflow::assign($task, $first, $project->owner);
        TaskWorkflow::transition($task, TaskStatus::InProgress, $first);
        TaskWorkflow::assign($task, $second, $project->owner);
        TaskWorkflow::transition($task, TaskStatus::ReadyForVerification, $second);
        TaskWorkflow::transition($task, TaskStatus::VerifiedComplete, $project->owner);

        // The first worker keeps an honest earlier-work credit, counted
        // once; the finisher holds the completed credit.
        $content = $this->get($first->profileUrl())->assertOk()->getContent();
        $this->assertSame(1, substr_count($content, 'Document the pinout'));
        $this->assertStringContainsString('Earlier task work', $content);
        $this->assertStringContainsString('Contributions <span class="text-zinc-400">(1)</span>', $content);

        $finisher = $this->get($second->profileUrl())->assertOk()->getContent();
        $this->assertStringContainsString('Task completed &amp; reviewed', $finisher);
    }

    public function test_membership_removal_keeps_authorship_but_drops_working_on(): void
    {
        $project = $this->publishedProject();
        $member = User::factory()->create();
        ProjectMember::factory()->for($project, 'project')->for($member, 'user')->create([
            'role' => ProjectRole::Contributor, 'status' => 'active',
        ]);
        Observation::factory()->for($project, 'project')->for($member, 'author')->create([
            'title' => 'Authorship survives removal',
        ]);

        $content = $this->get($member->profileUrl())->assertOk()->getContent();
        $this->assertStringContainsString('Working on <span class="text-zinc-400">(1)</span>', $content);

        $this->actingAs($project->owner)
            ->patch(route('projects.team.members.revoke', [$project, ProjectMember::firstOrFail()]))
            ->assertRedirect();

        $content = $this->get($member->profileUrl())->assertOk()->getContent();
        $this->assertStringContainsString('Working on <span class="text-zinc-400">(0)</span>', $content);
        $this->assertStringContainsString('Authorship survives removal', $content);
    }

    public function test_workspace_hides_contributions_on_projects_member_cannot_view(): void
    {
        // Drafts are managers-only: a contributor's workspace hides the
        // work with an honest note instead of linking to 403 pages.
        $project = $this->publishedProject();
        $worker = User::factory()->create();
        Observation::factory()->for($project, 'project')->for($worker, 'author')->create([
            'title' => 'Hidden helper observation',
        ]);

        $this->actingAs($worker)->get(route('dashboard'))->assertOk()->assertSee('Hidden helper observation', false);

        $project->update(['publication_status' => PublicationStatus::Draft]);

        $content = $this->actingAs($worker)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Hidden helper observation', $content);
        $this->assertStringContainsString('no longer visible to you', $content);
    }

    public function test_comments_and_chat_never_count_as_contributions(): void
    {
        $project = $this->publishedProject();
        $chatter = User::factory()->create();

        DiscussionPost::factory()->for($project, 'project')->create(['user_id' => $chatter->id]);
        ChatMessage::factory()->projectRoom($project)->create(['user_id' => $chatter->id]);

        $content = $this->get($chatter->profileUrl())->assertOk()->getContent();
        $this->assertStringContainsString('Contributions <span class="text-zinc-400">(0)</span>', $content);
        $this->assertStringContainsString('Working on <span class="text-zinc-400">(0)</span>', $content);
    }
}
