<?php

namespace Tests\Feature;

use App\Enums\PublicationStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PresenceAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The null test driver never evaluates channel callbacks, so these
        // tests run channel auth through the real Reverb/Pusher signing
        // path with dummy credentials. Signing is local HMAC; no socket
        // server is contacted. The default manager instance is kept: it
        // already holds the registered channels.
        config()->set('broadcasting.connections.reverb.key', 'test-key');
        config()->set('broadcasting.connections.reverb.secret', 'test-secret');
        config()->set('broadcasting.connections.reverb.app_id', 'test-app');
        config()->set('broadcasting.default', 'reverb');

        // Channel registrations land on the default driver at boot time
        // (the null driver in tests), so re-register the real channel
        // file against the Reverb driver under test.
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

    public function test_guests_cannot_join_channels(): void
    {
        // Guarded channels deny guests with 403 (no session identity).
        $this->authRequest(null, 'private-chat.lounge')->assertForbidden();
        $this->authRequest(null, 'presence-online')->assertForbidden();
    }

    public function test_presence_requires_opt_in_and_shares_identity_only(): void
    {
        $user = User::factory()->create(['email' => 'presence-private@example.test']);

        $this->authRequest($user, 'presence-online')->assertForbidden();

        $user->forceFill(['show_presence' => true])->save();

        $response = $this->authRequest($user, 'presence-online')->assertOk();
        $auth = $response->json('channel_data');
        $data = json_decode($auth, true);

        $this->assertSame((string) $user->id, (string) $data['user_id']);
        $this->assertSame($user->name, $data['user_info']['name']);
        $this->assertArrayNotHasKey('email', $data['user_info']);
        $this->assertStringNotContainsString('presence-private@example.test', $response->getContent());
    }

    public function test_project_presence_enforces_visibility_and_opt_in(): void
    {
        $project = Project::factory()->create(['publication_status' => PublicationStatus::Published]);
        $draft = Project::factory()->draft()->create();

        $member = User::factory()->create();
        $member->forceFill(['show_presence' => true])->save();

        $this->authRequest($member, 'presence-roster.project.'.$project->id)->assertOk();
        $this->authRequest($member, 'presence-roster.project.'.$draft->id)->assertForbidden();

        $draft->owner->forceFill(['show_presence' => false])->save();
        $this->authRequest($draft->owner, 'presence-roster.project.'.$draft->id)->assertForbidden();

        $draft->owner->forceFill(['show_presence' => true])->save();
        $this->authRequest($draft->owner, 'presence-roster.project.'.$draft->id)->assertOk();
    }

    public function test_private_chat_channels_enforce_visibility(): void
    {
        $draft = Project::factory()->draft()->create();
        $member = User::factory()->create();

        $this->authRequest($member, 'private-chat.lounge')->assertOk();
        $this->authRequest($member, 'private-chat.project.'.$draft->id)->assertForbidden();
        $this->authRequest($draft->owner, 'private-chat.project.'.$draft->id)->assertOk();
    }

    public function test_presence_timing_configuration_bounds_stale_members(): void
    {
        // Reverb drops quiet sockets after activity_timeout and probes them
        // on ping_interval, so a silent member disappears within ~90s.
        $this->assertSame(60, (int) config('reverb.apps.apps.0.ping_interval'));
        $this->assertSame(30, (int) config('reverb.apps.apps.0.activity_timeout'));
    }

    public function test_preferences_update_controls_presence_and_notifications(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.preferences.update'), [
                'show_presence' => '1',
                'notify_mentions' => '1',
            ])
            ->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertTrue($user->show_presence);
        $this->assertTrue($user->wantsNotification('mentions'));
        $this->assertFalse($user->wantsNotification('discussion_replies'));
    }
}
