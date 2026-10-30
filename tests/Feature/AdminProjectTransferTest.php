<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\GlobalRole;
use App\Enums\ProjectActivity;
use App\Enums\ProjectRole;
use App\Models\AdminAuditEvent;
use App\Models\AgentConversation;
use App\Models\AiUsageEntry;
use App\Models\AuditEvent;
use App\Models\ContributorTask;
use App\Models\DiscussionPost;
use App\Models\Observation;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Repository;
use App\Models\User;
use App\Notifications\ProjectOwnershipReceivedNotification;
use App\Notifications\ProjectOwnershipTransferredNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminProjectTransferTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['global_role' => GlobalRole::Admin])->save();

        return $admin->fresh();
    }

    private function confirmedSession(): array
    {
        return ['auth.password_confirmed_at' => time()];
    }

    /** @return array{project: Project, recipient: User} */
    private function transferableProject(): array
    {
        $project = Project::factory()->create();
        $recipient = User::factory()->create();

        return ['project' => $project, 'recipient' => $recipient];
    }

    public function test_admin_projects_index_lists_search_filters_and_counts(): void
    {
        $admin = $this->makeAdmin();
        $suspended = User::factory()->create();
        $suspended->forceFill(['account_status' => AccountStatus::Suspended])->save();
        $owned = Project::factory()->create(['owner_id' => $suspended->id, 'title' => 'Suspended Owner Project']);
        Project::factory()->create(['title' => 'Active Owner Project', 'activity' => ProjectActivity::Paused]);

        $page = $this->actingAs($admin)->get(route('admin.projects.index'))->assertOk();
        $page->assertSee('2 projects found', false);
        $page->assertSee('Owner suspended', false);
        $page->assertSee(route('admin.projects.show', $owned), false);

        $this->actingAs($admin)->get(route('admin.projects.index', ['q' => 'Suspended Owner']))
            ->assertOk()->assertSee('1 project found', false)->assertDontSee('Active Owner Project', false);

        $this->actingAs($admin)->get(route('admin.projects.index', ['owner_status' => 'suspended']))
            ->assertOk()->assertSee('1 project found', false);

        $this->actingAs($admin)->get(route('admin.projects.index', ['activity' => ProjectActivity::Paused->value]))
            ->assertOk()->assertSee('1 project found', false)->assertSee('Active Owner Project', false);

        $this->actingAs(User::factory()->create())->get(route('admin.projects.index'))->assertForbidden();
    }

    public function test_admin_project_detail_shows_owner_team_and_transfer_link(): void
    {
        $admin = $this->makeAdmin();
        $project = Project::factory()->create();
        $maintainer = User::factory()->create();
        ProjectMember::factory()->create([
            'project_id' => $project->id, 'user_id' => $maintainer->id,
            'role' => ProjectRole::Maintainer, 'status' => 'active',
        ]);

        $this->actingAs($admin)->get(route('admin.projects.show', $project))
            ->assertOk()
            ->assertSee($project->owner->name, false)
            ->assertSee($maintainer->name, false)
            ->assertSee(route('admin.projects.transfer', $project), false)
            ->assertSee(route('projects.show', $project), false);

        $draft = Project::factory()->draft()->create();
        $this->actingAs($admin)->get(route('admin.projects.show', $draft))
            ->assertOk()->assertSee('Not viewable with your project access', false);
    }

    public function test_transfer_flow_end_to_end_keep_previous_owner(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();
        ['project' => $project, 'recipient' => $recipient] = $this->transferableProject();
        $oldOwner = $project->owner;

        $this->actingAs($admin)->get(route('admin.projects.transfer', $project))
            ->assertOk()->assertSee($oldOwner->email, false);

        $preview = $this->actingAs($admin)
            ->post(route('admin.projects.transfer.preview', $project), [
                'email' => $recipient->email,
                'keep_previous_owner' => 'keep',
                'reason' => 'Handing to an active maintainer.',
            ])->assertOk();
        $preview->assertSee($recipient->name, false)
            ->assertSee($recipient->email, false)
            ->assertSee('stays on as an active project maintainer', false)
            ->assertSee('is <strong>not</strong> promoted globally', false);

        $this->actingAs($admin)->withSession($this->confirmedSession())
            ->post(route('admin.projects.transfer.confirm', $project), [
                'recipient_id' => $recipient->id,
                'keep_previous_owner' => 'keep',
                'reason' => 'Handing to an active maintainer.',
                'expected_owner_id' => $oldOwner->id,
            ])->assertRedirect(route('admin.projects.show', $project));

        $project->refresh();
        $this->assertSame($recipient->id, $project->owner_id);
        $this->assertTrue($project->managedBy($recipient));
        $this->assertTrue($project->managedBy($oldOwner));
        $this->assertSame(GlobalRole::User, $recipient->fresh()->global_role);
        $this->assertSame('active', ProjectMember::where('project_id', $project->id)
            ->where('user_id', $oldOwner->id)->value('status'));

        $audit = AdminAuditEvent::where('action', 'project.transferred')->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame($project->id, $audit->target_id);
        $this->assertSame('Handing to an active maintainer.', $audit->reason);
        $this->assertSame($recipient->id, $audit->after['owner_id']);
        $this->assertSame($oldOwner->id, $audit->before['owner_id']);
        $this->assertSame('maintainer', $audit->after['previous_owner_result']);
        $this->assertNotEmpty($audit->correlation_id);

        $history = AuditEvent::where('project_id', $project->id)
            ->where('event', 'team.ownership_transferred')->sole();
        $this->assertSame($recipient->id, $history->metadata['to_user_id']);

        Notification::assertSentTo($oldOwner, ProjectOwnershipTransferredNotification::class);
        Notification::assertSentTo($recipient, ProjectOwnershipReceivedNotification::class);
    }

    public function test_transfer_removes_previous_owner_when_chosen(): void
    {
        $admin = $this->makeAdmin();
        ['project' => $project, 'recipient' => $recipient] = $this->transferableProject();
        $oldOwner = $project->owner;

        $this->actingAs($admin)->withSession($this->confirmedSession())
            ->post(route('admin.projects.transfer.confirm', $project), [
                'recipient_id' => $recipient->id,
                'keep_previous_owner' => 'remove',
                'reason' => 'Clean break.',
                'expected_owner_id' => $oldOwner->id,
            ])->assertRedirect(route('admin.projects.show', $project));

        $project->refresh();
        $this->assertSame($recipient->id, $project->owner_id);
        $this->assertFalse($project->managedBy($oldOwner));
        $this->assertFalse(ProjectMember::where('project_id', $project->id)
            ->where('user_id', $oldOwner->id)->where('status', 'active')->exists());
        $this->assertSame('removed', AdminAuditEvent::where('action', 'project.transferred')->sole()->after['previous_owner_result']);
    }

    public function test_transfer_to_already_maintainer_recipient_retires_member_row(): void
    {
        $admin = $this->makeAdmin();
        ['project' => $project, 'recipient' => $recipient] = $this->transferableProject();
        ProjectMember::factory()->create([
            'project_id' => $project->id, 'user_id' => $recipient->id,
            'role' => ProjectRole::Maintainer, 'status' => 'active',
        ]);

        $this->actingAs($admin)->withSession($this->confirmedSession())
            ->post(route('admin.projects.transfer.confirm', $project), [
                'recipient_id' => $recipient->id,
                'keep_previous_owner' => 'keep',
                'reason' => 'Promoting a maintainer.',
                'expected_owner_id' => $project->owner_id,
            ])->assertRedirect();

        $this->assertSame($recipient->id, $project->fresh()->owner_id);
        $this->assertSame('revoked', ProjectMember::where('project_id', $project->id)
            ->where('user_id', $recipient->id)->value('status'));
        $this->assertTrue(AdminAuditEvent::where('action', 'project.transferred')->sole()->after['recipient_membership_revoked']);
    }

    public function test_transfer_rejects_ineligible_recipients(): void
    {
        $admin = $this->makeAdmin();
        ['project' => $project] = $this->transferableProject();
        $suspended = User::factory()->create();
        $suspended->forceFill(['account_status' => AccountStatus::Suspended])->save();

        $base = ['keep_previous_owner' => 'keep', 'reason' => 'Trying.'];

        $this->actingAs($admin)->from(route('admin.projects.transfer', $project))
            ->post(route('admin.projects.transfer.preview', $project), $base + ['email' => 'nobody@example.com'])
            ->assertSessionHasErrors(['email' => 'No registered account uses that email address.']);

        $this->actingAs($admin)->from(route('admin.projects.transfer', $project))
            ->post(route('admin.projects.transfer.preview', $project), $base + ['email' => $suspended->email])
            ->assertSessionHasErrors(['email']);

        $this->actingAs($admin)->from(route('admin.projects.transfer', $project))
            ->post(route('admin.projects.transfer.preview', $project), $base + ['email' => $project->owner->email])
            ->assertSessionHasErrors(['email']);

        $this->actingAs($admin)
            ->post(route('admin.projects.transfer.preview', $project), ['email' => User::factory()->create()->email])
            ->assertSessionHasErrors(['keep_previous_owner', 'reason']);
    }

    public function test_transfer_requires_password_confirmation(): void
    {
        $admin = $this->makeAdmin();
        ['project' => $project, 'recipient' => $recipient] = $this->transferableProject();

        $this->actingAs($admin)
            ->post(route('admin.projects.transfer.confirm', $project), [
                'recipient_id' => $recipient->id,
                'keep_previous_owner' => 'keep',
                'reason' => 'No confirmation.',
                'expected_owner_id' => $project->owner_id,
            ])->assertRedirect(route('password.confirm'));

        $this->assertNotSame($recipient->id, $project->fresh()->owner_id);
    }

    public function test_transfer_rejects_stale_confirmation_and_duplicates(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();
        ['project' => $project, 'recipient' => $recipient] = $this->transferableProject();
        $oldOwner = $project->owner;

        $payload = [
            'recipient_id' => $recipient->id,
            'keep_previous_owner' => 'keep',
            'reason' => 'First write wins.',
            'expected_owner_id' => $oldOwner->id,
        ];

        // A concurrent transfer lands first.
        $project->forceFill(['owner_id' => User::factory()->create()->id])->save();

        $this->actingAs($admin)->withSession($this->confirmedSession())
            ->post(route('admin.projects.transfer.confirm', $project), $payload)
            ->assertRedirect(route('admin.projects.transfer', $project))
            ->assertSessionHasErrors(['transfer']);
        $this->assertSame(0, AdminAuditEvent::where('action', 'project.transferred')->count());

        // Retry against the current owner succeeds exactly once; a
        // duplicate submission with the same payload is then stale.
        $payload['expected_owner_id'] = $project->fresh()->owner_id;
        $this->actingAs($admin)->withSession($this->confirmedSession())
            ->post(route('admin.projects.transfer.confirm', $project), $payload)
            ->assertRedirect(route('admin.projects.show', $project));
        $this->actingAs($admin)->withSession($this->confirmedSession())
            ->post(route('admin.projects.transfer.confirm', $project), $payload)
            ->assertRedirect(route('admin.projects.transfer', $project))
            ->assertSessionHasErrors(['transfer']);

        $this->assertSame(1, AdminAuditEvent::where('action', 'project.transferred')->count());
        Notification::assertSentTimes(ProjectOwnershipTransferredNotification::class, 1);
        Notification::assertSentTimes(ProjectOwnershipReceivedNotification::class, 1);
    }

    public function test_transfer_rejected_for_non_admins(): void
    {
        $admin = $this->makeAdmin();
        $project = Project::factory()->create();
        $maintainer = User::factory()->create();
        ProjectMember::factory()->create([
            'project_id' => $project->id, 'user_id' => $maintainer->id,
            'role' => ProjectRole::Maintainer, 'status' => 'active',
        ]);
        $moderator = User::factory()->create();
        $moderator->forceFill(['global_role' => GlobalRole::Moderator])->save();

        foreach ([$maintainer, $moderator, User::factory()->create()] as $actor) {
            $this->actingAs($actor)->get(route('admin.projects.index'))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.projects.show', $project))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.projects.transfer', $project))->assertForbidden();
            $this->actingAs($actor)->post(route('admin.projects.transfer.preview', $project), [])->assertForbidden();
            $this->actingAs($actor)->withSession($this->confirmedSession())
                ->post(route('admin.projects.transfer.confirm', $project), [])->assertForbidden();
        }

        $this->assertSame($project->owner_id, $project->fresh()->owner_id);
        $this->assertSame(0, AdminAuditEvent::where('action', 'project.transferred')->count());
    }

    public function test_transfer_preserves_content_attribution_usage_and_consent_boundaries(): void
    {
        $admin = $this->makeAdmin();
        ['project' => $project, 'recipient' => $recipient] = $this->transferableProject();
        $oldOwner = $project->owner;
        $creator = User::factory()->create();
        $project->forceFill(['created_by_user_id' => $creator->id])->save();

        $task = ContributorTask::factory()->create(['project_id' => $project->id]);
        $observation = Observation::factory()->create(['project_id' => $project->id, 'user_id' => $oldOwner->id]);
        $post = DiscussionPost::factory()->create(['project_id' => $project->id, 'user_id' => $oldOwner->id, 'body' => 'Old owner words.']);
        $repository = Repository::factory()->create(['project_id' => $project->id]);
        $conversation = AgentConversation::create([
            'project_id' => $project->id, 'user_id' => $oldOwner->id, 'title' => 'Private thread',
        ]);
        AiUsageEntry::create([
            'idempotency_key' => 'transfer-usage-1', 'user_id' => $oldOwner->id,
            'feature' => 'repo_agent', 'operation' => 'analyze', 'attempt_seq' => 1,
            'provider' => 'mock', 'status' => 'succeeded',
        ]);

        $this->actingAs($admin)->withSession($this->confirmedSession())
            ->post(route('admin.projects.transfer.confirm', $project), [
                'recipient_id' => $recipient->id,
                'keep_previous_owner' => 'remove',
                'reason' => 'Preservation check.',
                'expected_owner_id' => $oldOwner->id,
            ])->assertRedirect();

        $project->refresh();
        $this->assertSame($recipient->id, $project->owner_id);
        $this->assertSame($creator->id, $project->created_by_user_id);
        $this->assertSame($project->id, $task->fresh()->project_id);
        $this->assertSame($oldOwner->id, $observation->fresh()->user_id);
        $this->assertSame($oldOwner->id, $post->fresh()->user_id);
        $this->assertSame($project->id, $repository->fresh()->project_id);
        $this->assertSame($oldOwner->id, $conversation->fresh()->user_id);
        $this->assertSame($oldOwner->id, AiUsageEntry::where('idempotency_key', 'transfer-usage-1')->sole()->user_id);
    }
}
