<?php

namespace Tests\Feature;

use App\Enums\PublicationStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoungePresenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_lounge_has_single_heading_short_intro_and_title(): void
    {
        $html = $this->get(route('lounge'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('>Community</h1>', $html);
        $this->assertStringNotContainsString('Community lounge', $html);
        $this->assertStringContainsString('Say hello, ask around, find a project.', $html);
        $this->assertStringContainsString('<title>Community — Rev²Square</title>', $html);
    }

    public function test_presence_panel_lists_no_member_names_server_side(): void
    {
        $viewer = User::factory()->create();
        User::factory()->create(['name' => 'Opted In Friend', 'show_presence' => true]);
        User::factory()->create(['name' => 'Opted Out Stranger', 'show_presence' => false]);

        $html = $this->actingAs($viewer)->get(route('lounge'))->assertOk()->getContent();

        $this->assertStringContainsString('Here now', $html);
        $this->assertStringContainsString('data-presence-list', $html);
        $this->assertStringContainsString('data-presence-state', $html);
        $this->assertStringContainsString('<aside class="hidden', $html);
        $this->assertStringNotContainsString('Loading…', $html);
        $this->assertStringNotContainsString('Signing you in', $html);
        $this->assertStringNotContainsString('Opted In Friend', $html);
        $this->assertStringNotContainsString('Opted Out Stranger', $html);
        $this->assertStringNotContainsString('Only members who opted in to presence appear here.', $html);
        $this->assertStringNotContainsString('Presence settings', $html);

        $project = Project::factory()->create(['publication_status' => PublicationStatus::Published]);
        $room = $this->actingAs($viewer)->get(route('projects.chat.show', $project))->assertOk()->getContent();

        $this->assertStringContainsString('Here now', $room);
        $this->assertStringContainsString('<aside class="hidden', $room);
        $this->assertStringNotContainsString('Opted In Friend', $room);
        $this->assertStringNotContainsString('Opted Out Stranger', $room);
        $this->assertStringNotContainsString('Only members who opted in to presence appear here.', $room);
        $this->assertStringNotContainsString('Presence settings', $room);
    }

    public function test_chat_picker_labels_match_room_headings(): void
    {
        $project = Project::factory()->create([
            'publication_status' => PublicationStatus::Published,
            'title' => 'Signal Revival',
        ]);
        $user = User::factory()->create();
        $project->follows()->create(['user_id' => $user->id]);

        $rooms = $this->actingAs($user)->getJson(route('chat.unread'))->assertOk()->json('rooms');

        $this->assertSame('Community', collect($rooms)->firstWhere('room', 'lounge')['label']);
        $this->assertSame('Signal Revival chat', collect($rooms)->firstWhere('room', 'project.'.$project->id)['label']);

        $lounge = $this->actingAs($user)->get(route('lounge'))->assertOk()->getContent();
        $this->assertStringContainsString('>Community</h1>', $lounge);
        $this->assertStringNotContainsString('Community lounge', $lounge);
        $room = $this->actingAs($user)->get(route('projects.chat.show', $project))->assertOk()->getContent();
        $this->assertStringContainsString('>Signal Revival chat</h1>', $room);
    }

    public function test_chat_launcher_toggle_names_open_and_close_states(): void
    {
        $layout = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertStringContainsString('aria-label="Open chat rooms"', $layout);
        $this->assertStringContainsString('aria-expanded="false"', $layout);
        $this->assertStringContainsString('aria-controls="chat-rooms-panel"', $layout);

        $launcher = (string) file_get_contents(resource_path('js/pages/chat-launcher.js'));
        $this->assertStringContainsString('Close chat rooms', $launcher);
        $this->assertStringContainsString('aria-expanded', $launcher);
    }

    public function test_presence_roster_shows_usernames_and_collapses_when_empty(): void
    {
        $roster = (string) file_get_contents(resource_path('js/pages/presence-roster.js'));

        $this->assertStringNotContainsString('avatar_url', $roster);
        $this->assertStringNotContainsString('Away', $roster);
        $this->assertStringNotContainsString('Nobody else is here right now.', $roster);
        $this->assertStringNotContainsString('Signing you in', $roster);
        $this->assertStringNotContainsString('Loading presence', $roster);
        $this->assertStringNotContainsString('you opted out', $roster);
        $this->assertStringContainsString("closest('aside')", $roster);
        $this->assertStringContainsString('channel.error', $roster);
        $this->assertStringContainsString('no longer have access here', $roster);
    }

    public function test_opted_in_members_register_sitewide_presence(): void
    {
        $member = User::factory()->create(['show_presence' => true]);

        // A non-/online page still registers the tab globally.
        $html = $this->actingAs($member)->get(route('lounge'))->assertOk()->getContent();
        $this->assertStringContainsString('data-presence-register', $html);
        $this->assertStringContainsString('presence-online.0', $html);

        $optedOut = User::factory()->create(['show_presence' => false]);
        $html = $this->actingAs($optedOut)->get(route('lounge'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-presence-register', $html);

        $html = $this->get(route('lounge'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-presence-register', $html);
    }

    public function test_empty_chat_keeps_composer_and_help_guidance(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('lounge'))->assertOk()->getContent();

        $this->assertStringContainsString('No messages yet. Say hello.', $html);
        $this->assertStringContainsString('<label for="chat-body" class="sr-only">Message</label>', $html);
        $this->assertStringContainsString('Chat help', $html);
        $this->assertStringContainsString('No DMs here: harassment or secrets go to a maintainer, not the room.', $html);
        $this->assertStringContainsString('the code of conduct applies', $html);
    }
}
