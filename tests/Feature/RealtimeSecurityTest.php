<?php

namespace Tests\Feature;

use App\Enums\GlobalRole;
use App\Enums\ProjectActivity;
use App\Enums\ProjectPhase;
use App\Enums\ProjectRole;
use App\Enums\PublicationStatus;
use App\Events\ChatChannelRotated;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Services\Admin\AdminUserService;
use App\Services\Realtime\RealtimeEpoch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Realtime subscription revocation (P1-2): channel names embed the epochs
 * that authorize them, and every access change rotates the affected
 * channels, stranding earlier subscribers on dead names.
 *
 * A live two-socket reproduction is out of reach here (no Reverb server
 * in the test env), so these tests pin the server-enforced property from
 * both sides: stale names are rejected at auth, and each access change
 * bumps the epoch and announces the rotation on the old names.
 */
class RealtimeSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same Reverb-driver channel-auth setup as PresenceAuthTest:
        // signing is local HMAC; no socket server is contacted.
        config()->set('broadcasting.connections.reverb.key', 'test-key');
        config()->set('broadcasting.connections.reverb.secret', 'test-secret');
        config()->set('broadcasting.connections.reverb.app_id', 'test-app');
        config()->set('broadcasting.default', 'reverb');

        require base_path('routes/channels.php');
    }

    private function authRequest(?User $user, string $channel): TestResponse
    {
        $request = $user !== null ? $this->actingAs($user) : $this;

        return $request->postJson('/broadcasting/auth', [
            'socket_id' => '1.1',
            'channel_name' => $channel,
        ]);
    }

    private function publishedProject(): Project
    {
        return Project::factory()->create(['publication_status' => PublicationStatus::Published]);
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['global_role' => GlobalRole::Admin])->save();

        return $admin->fresh();
    }

    /** @return array{chat: string, roster: string} */
    private function projectChannels(Project $project): array
    {
        // Freshly created models carry null until reloaded; the column
        // default is 0, matching what subscribers would be handed.
        $pe = (int) ($project->channel_epoch ?? 0);
        $ge = RealtimeEpoch::current();

        return [
            'chat' => "private-chat.project.{$project->id}.{$pe}.{$ge}",
            'roster' => "presence-roster.project.{$project->id}.{$pe}.{$ge}",
        ];
    }

    private function unpublish(Project $project): void
    {
        $this->actingAs($project->owner)
            ->put(route('projects.update', $project), [
                'title' => $project->title,
                'pitch' => $project->pitch,
                'body' => $project->body,
                'phase' => ProjectPhase::Development->value,
                'activity' => ProjectActivity::Active->value,
                'publication_status' => PublicationStatus::Draft->value,
            ])
            ->assertRedirect(route('projects.show', $project));
    }

    private function assertRotationAnnounced(string $old, string $new, bool $presence): void
    {
        Event::assertDispatched(ChatChannelRotated::class, function (ChatChannelRotated $event) use ($old, $new, $presence): bool {
            if ($event->oldChannel !== $old || $event->newChannel !== $new || $event->presence !== $presence) {
                return false;
            }

            $expected = ($presence ? 'presence-' : 'private-').$old;

            return $event->broadcastOn()->name === $expected
                && $event->broadcastAs() === 'channel.rotated'
                && $event->broadcastWith() === ['channel' => $new];
        });
    }

    public function test_unpublishing_rotates_project_channels(): void
    {
        $project = $this->publishedProject();
        $outsider = User::factory()->create();
        $owner = $project->owner;
        $owner->forceFill(['show_presence' => true])->save();

        $before = $this->projectChannels($project);
        $this->authRequest($outsider, $before['chat'])->assertOk();

        Event::fake([ChatChannelRotated::class]);

        $this->unpublish($project);

        $project->refresh();
        $this->assertSame(1, $project->channel_epoch);
        $after = $this->projectChannels($project);

        $this->assertRotationAnnounced(
            "chat.project.{$project->id}.0.0",
            "chat.project.{$project->id}.1.0",
            false,
        );
        $this->assertRotationAnnounced(
            "roster.project.{$project->id}.0.0",
            "roster.project.{$project->id}.1.0",
            true,
        );

        // Stale names are dead for everyone, including the manager.
        $this->authRequest($owner, $before['chat'])->assertForbidden();
        $this->authRequest($owner, $before['roster'])->assertForbidden();
        $this->authRequest($outsider, $before['chat'])->assertForbidden();

        // Current names re-check access: manager in, outsider out.
        $this->authRequest($owner, $after['chat'])->assertOk();
        $this->authRequest($owner, $after['roster'])->assertOk();
        $this->authRequest($outsider, $after['chat'])->assertForbidden();
        $this->authRequest($outsider, $after['roster'])->assertForbidden();

        // Rendered pages hand out the current names.
        $html = $this->actingAs($owner)->get(route('projects.chat.show', $project))->assertOk()->getContent();
        $this->assertStringContainsString("data-channel=\"chat.project.{$project->id}.1.0\"", $html);
        $this->assertStringContainsString("data-channel=\"presence-roster.project.{$project->id}.1.0\"", $html);
    }

    public function test_ordinary_edits_do_not_rotate_channels(): void
    {
        $project = $this->publishedProject();

        Event::fake([ChatChannelRotated::class]);

        $this->actingAs($project->owner)
            ->put(route('projects.update', $project), [
                'title' => 'Same visibility, new title',
                'pitch' => $project->pitch,
                'body' => $project->body,
                'phase' => ProjectPhase::Development->value,
                'activity' => ProjectActivity::Active->value,
                'publication_status' => PublicationStatus::Published->value,
            ])
            ->assertRedirect();

        $this->assertSame(0, $project->fresh()->channel_epoch);
        Event::assertNotDispatched(ChatChannelRotated::class);
    }

    public function test_revoking_a_member_rotates_project_channels(): void
    {
        $project = Project::factory()->draft()->create();
        $member = User::factory()->create();
        $membership = ProjectMember::factory()->create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Maintainer,
            'status' => 'active',
        ]);

        $before = $this->projectChannels($project);
        $this->authRequest($member, $before['chat'])->assertOk();

        Event::fake([ChatChannelRotated::class]);

        $this->actingAs($project->owner)
            ->patch(route('projects.team.members.revoke', [$project, $membership]))
            ->assertRedirect();

        $this->assertSame(1, $project->fresh()->channel_epoch);
        $after = $this->projectChannels($project->fresh());

        $this->assertRotationAnnounced(
            "chat.project.{$project->id}.0.0",
            "chat.project.{$project->id}.1.0",
            false,
        );

        $this->authRequest($member, $before['chat'])->assertForbidden();
        $this->authRequest($member, $after['chat'])->assertForbidden();
        $this->authRequest($project->owner, $after['chat'])->assertOk();
    }

    public function test_ownership_transfer_rotates_project_channels(): void
    {
        $admin = $this->makeAdmin();
        $project = Project::factory()->create();
        $recipient = User::factory()->create();
        $oldOwner = $project->owner;

        Event::fake([ChatChannelRotated::class]);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.projects.transfer.confirm', $project), [
                'recipient_id' => $recipient->id,
                'keep_previous_owner' => 'remove',
                'reason' => 'Clean break.',
                'expected_owner_id' => $oldOwner->id,
            ])
            ->assertRedirect(route('admin.projects.show', $project));

        $this->assertSame(1, $project->fresh()->channel_epoch);
        $this->assertRotationAnnounced(
            "chat.project.{$project->id}.0.0",
            "chat.project.{$project->id}.1.0",
            false,
        );
        $this->assertRotationAnnounced(
            "roster.project.{$project->id}.0.0",
            "roster.project.{$project->id}.1.0",
            true,
        );
    }

    public function test_suspension_rotates_every_channel(): void
    {
        $admin = $this->makeAdmin();
        $project = $this->publishedProject();
        $victim = User::factory()->create();
        $member = User::factory()->create();
        $member->forceFill(['show_presence' => true])->save();

        $this->authRequest($member, 'private-chat.lounge.0')->assertOk();
        $this->authRequest($member, 'presence-online.0')->assertOk();

        Event::fake([ChatChannelRotated::class]);

        AdminUserService::suspend($admin, $victim->id, 'Abuse.');
        $victim->refresh();

        $this->assertSame(1, RealtimeEpoch::current());

        $this->assertRotationAnnounced('chat.lounge.0', 'chat.lounge.1', false);
        $this->assertRotationAnnounced('online.0', 'online.1', true);
        $this->assertRotationAnnounced('roster.lounge.0', 'roster.lounge.1', true);
        $this->assertRotationAnnounced(
            "chat.project.{$project->id}.0.0",
            "chat.project.{$project->id}.0.1",
            false,
        );
        $this->assertRotationAnnounced(
            "roster.project.{$project->id}.0.0",
            "roster.project.{$project->id}.0.1",
            true,
        );

        // Stale global names are dead even for unaffected members…
        $this->authRequest($member, 'private-chat.lounge.0')->assertForbidden();
        $this->authRequest($member, 'presence-online.0')->assertForbidden();
        $this->authRequest($member, "private-chat.project.{$project->id}.0.0")->assertForbidden();

        // …current names work for members but never for the suspended user.
        $this->authRequest($member, 'private-chat.lounge.1')->assertOk();
        $this->authRequest($member, 'presence-online.1')->assertOk();
        $this->authRequest($member, "private-chat.project.{$project->id}.0.1")->assertOk();
        $this->authRequest($victim, 'private-chat.lounge.1')->assertForbidden();
        $this->authRequest($victim, "private-chat.project.{$project->id}.0.1")->assertForbidden();
    }

    public function test_opt_out_preferences_emit_cross_tab_signal(): void
    {
        $user = User::factory()->create(['show_presence' => true]);

        $this->actingAs($user)
            ->patch(route('profile.preferences.update'), ['notify_mentions' => '1'])
            ->assertRedirect(route('profile.edit'));

        $this->assertFalse($user->fresh()->show_presence);

        $html = $this->actingAs($user)->get(route('profile.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('rt:presence-optout', $html);

        $this->actingAs($user)
            ->patch(route('profile.preferences.update'), ['show_presence' => '1'])
            ->assertRedirect(route('profile.edit'));

        $html = $this->actingAs($user)->get(route('profile.edit'))->assertOk()->getContent();
        $this->assertStringNotContainsString('rt:presence-optout', $html);
    }

    public function test_client_wiring_for_rotation_drafts_and_render(): void
    {
        $room = (string) file_get_contents(resource_path('js/pages/chat-room.js'));
        $roster = (string) file_get_contents(resource_path('js/pages/presence-roster.js'));
        $composer = (string) file_get_contents(resource_path('js/pages/discussion-composer.js'));
        $echo = (string) file_get_contents(resource_path('js/echo.js'));

        // P1-2: both live subscriptions rejoin on rotation.
        $this->assertStringContainsString('.channel.rotated', $room);
        $this->assertStringContainsString('echo.leave(', $room);
        $this->assertStringContainsString('.channel.rotated', $roster);
        $this->assertStringContainsString('trackPresenceChannel', $roster);

        // P1-2: cross-tab logout and opt-out signals.
        $this->assertStringContainsString('rt:logout', $echo);
        $this->assertStringContainsString('rt:presence-optout', $echo);

        // P1-3: every composer goes through the per-account draft store.
        foreach ([$room, $composer] as $module) {
            $this->assertStringContainsString('loadDraft', $module);
            $this->assertStringContainsString('saveDraft', $module);
            $this->assertStringNotContainsString('composer:', $module);
        }
        $this->assertStringContainsString('clearAllDrafts', $echo);

        // P1-1: live messages render through the DOM builder, never
        // through author-name string interpolation.
        $this->assertStringContainsString('buildMessageNode', $room);
        $this->assertStringNotContainsString('aria-label="${', $room);
    }
}
