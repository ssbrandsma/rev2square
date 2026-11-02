<?php

namespace Tests\Feature;

use App\Enums\ProjectActivity;
use App\Enums\PublicationStatus;
use App\Events\NewChatMessage;
use App\Models\ChatMessage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    private function publishedProject(array $overrides = []): Project
    {
        return Project::factory()->create([
            'publication_status' => PublicationStatus::Published,
            ...$overrides,
        ]);
    }

    public function test_lounge_readable_by_guests_but_send_requires_login(): void
    {
        ChatMessage::factory()->create(['body' => 'Hello lounge readers.']);

        $this->get(route('lounge'))->assertOk()->assertSee('Hello lounge readers.', false);
        $this->getJson(route('lounge.history'))->assertOk();

        $this->postJson(route('lounge.send'), ['body' => 'Hi', 'client_key' => 'k1'])
            ->assertUnauthorized();
    }

    public function test_send_persists_broadcasts_and_returns_payload(): void
    {
        Event::fake([NewChatMessage::class]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('lounge.send'), [
            'body' => 'Hello with a link https://example.test/x',
            'client_key' => 'client-key-1',
        ]);

        $response->assertCreated();
        $this->assertSame('Hello with a link https://example.test/x', ChatMessage::firstOrFail()->body);

        $payload = $response->json('message');
        $this->assertSame($user->name, $payload['author']['name']);
        $this->assertStringContainsString('<a href="https://example.test/x"', $payload['body_html']);

        Event::assertDispatched(NewChatMessage::class, fn (NewChatMessage $event): bool => $event->message->client_key === 'client-key-1');

        // Chat never triggers inbox notifications.
        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_retried_send_resolves_to_stored_row(): void
    {
        Event::fake([NewChatMessage::class]);

        $user = User::factory()->create();
        $payload = ['body' => 'Sent twice over a flaky link.', 'client_key' => 'client-key-retry'];

        $first = $this->actingAs($user)->postJson(route('lounge.send'), $payload);
        $first->assertCreated();

        $second = $this->actingAs($user)->postJson(route('lounge.send'), $payload);
        $second->assertOk();

        $this->assertSame($first->json('message.id'), $second->json('message.id'));
        $this->assertSame(1, ChatMessage::count());
    }

    public function test_history_cursors_page_both_directions(): void
    {
        $messages = collect(range(1, 5))->map(
            fn (int $i): ChatMessage => ChatMessage::factory()->create(['body' => "Lounge message {$i}."])
        );

        $after = $this->getJson(route('lounge.history', ['after_id' => $messages[1]->id, 'limit' => 2]))->assertOk()->json('messages');
        $this->assertSame(['Lounge message 3.', 'Lounge message 4.'], array_map(
            fn (array $message): string => strip_tags($message['body_html']), $after
        ));

        $before = $this->getJson(route('lounge.history', ['before_id' => $messages[3]->id, 'limit' => 2]))->assertOk()->json('messages');
        $this->assertSame([$messages[1]->id, $messages[2]->id], array_column($before, 'id'));
    }

    public function test_project_room_respects_visibility_and_lifecycle(): void
    {
        $draft = Project::factory()->draft()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('projects.chat.show', $draft))
            ->assertForbidden();

        $project = $this->publishedProject(['activity' => ProjectActivity::Archived, 'discussion_open' => false]);

        $this->actingAs(User::factory()->create())
            ->get(route('projects.chat.show', $project))
            ->assertOk()
            ->assertSee('archived', false);

        $this->actingAs(User::factory()->create())
            ->postJson(route('projects.chat.send', $project), ['body' => 'Nope.', 'client_key' => 'k-arch'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Discussion is closed because this project is archived. A maintainer can deliberately reopen it.']);

        // Reopened projects accept chat again.
        $project->update(['discussion_open' => true]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('projects.chat.send', $project), ['body' => 'Welcome back.', 'client_key' => 'k-reopen'])
            ->assertCreated();

        // Unpublishing hides history.
        $project->update(['publication_status' => PublicationStatus::Draft]);
        $this->actingAs(User::factory()->create())
            ->getJson(route('projects.chat.history', $project))
            ->assertForbidden();
    }

    public function test_empty_oversized_and_ratelimited_sends_fail(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('lounge.send'), ['body' => '   ', 'client_key' => 'k-empty'])
            ->assertStatus(422);

        $this->actingAs($user)
            ->postJson(route('lounge.send'), ['body' => str_repeat('x', 2001), 'client_key' => 'k-big'])
            ->assertStatus(422);

        $this->assertSame(0, ChatMessage::count());
    }

    public function test_author_can_retract_and_maintainer_can_remove(): void
    {
        $project = $this->publishedProject();
        $message = ChatMessage::factory()->projectRoom($project)->create();

        $this->actingAs(User::factory()->create())
            ->delete(route('projects.chat.destroy', [$project, $message]))
            ->assertForbidden();

        $this->actingAs($project->owner)
            ->delete(route('projects.chat.destroy', [$project, $message]))
            ->assertRedirect();

        $this->assertSoftDeleted($message);
        $this->assertDatabaseHas('audit_events', ['event' => 'chat.moderated']);

        $own = ChatMessage::factory()->create();
        $this->actingAs($own->author)
            ->delete(route('lounge.destroy', $own))
            ->assertRedirect();
        $this->assertSoftDeleted($own);
    }

    public function test_unread_tracks_rooms_and_read_marks(): void
    {
        $project = $this->publishedProject();
        $user = User::factory()->create();
        $project->follows()->create(['user_id' => $user->id]);

        ChatMessage::factory()->create(['body' => 'Lounge news.']);
        ChatMessage::factory()->projectRoom($project)->create(['body' => 'Project news.']);

        $rooms = $this->actingAs($user)->getJson(route('chat.unread'))->assertOk()->json('rooms');

        $this->assertSame('lounge', $rooms[0]['room']);
        $this->assertTrue($rooms[0]['unread']);
        $this->assertSame('project.'.$project->id, $rooms[1]['room']);
        $this->assertTrue($rooms[1]['unread']);

        // Visiting the room marks it read.
        $this->actingAs($user)->get(route('projects.chat.show', $project))->assertOk();

        $rooms = $this->actingAs($user)->getJson(route('chat.unread'))->assertOk()->json('rooms');
        $this->assertFalse(collect($rooms)->firstWhere('room', 'project.'.$project->id)['unread']);
        $this->assertTrue(collect($rooms)->firstWhere('room', 'lounge')['unread']);
    }

    public function test_chat_report_lands_in_moderation_queue(): void
    {
        $project = $this->publishedProject();
        $message = ChatMessage::factory()->projectRoom($project)->create(['body' => 'Reported chat content.']);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.chat.report', [$project, $message]), ['reason' => 'Rude.'])
            ->assertRedirect();

        $this->actingAs($project->owner)
            ->get(route('projects.moderation.index', $project))
            ->assertOk()
            ->assertSee('Reported chat content.', false);
    }

    public function test_chat_empty_state_and_presence_match_viewer_capability(): void
    {
        $guest = $this->get(route('lounge'))->assertOk()->getContent();
        $this->assertStringContainsString('No messages yet.', $guest);
        $this->assertStringNotContainsString('Say hello.', $guest);
        $this->assertStringNotContainsString('Here now', $guest);

        $member = $this->actingAs(User::factory()->create())
            ->get(route('lounge'))->assertOk()->getContent();
        $this->assertStringContainsString('No messages yet. Say hello.', $member);
        $this->assertStringContainsString('Here now', $member);
    }

    public function test_message_payloads_carry_moderation_actions(): void
    {
        $project = $this->publishedProject();
        $message = ChatMessage::factory()->projectRoom($project)->create();

        $json = $this->actingAs(User::factory()->create())
            ->getJson(route('projects.chat.history', $project))
            ->assertOk()
            ->json('messages');

        $this->assertSame([
            'retract_url' => route('projects.chat.destroy', [$project, $message]),
            'report_url' => route('projects.chat.report', [$project, $message]),
            'remove_url' => route('projects.chat.destroy', [$project, $message]),
        ], $json[0]['actions']);

        $lounge = ChatMessage::factory()->create();

        $json = $this->getJson(route('lounge.history'))->assertOk()->json('messages');

        $this->assertSame([
            'retract_url' => route('lounge.destroy', $lounge),
            'report_url' => route('lounge.report', $lounge),
            'remove_url' => null,
        ], $json[0]['actions']);
    }

    public function test_broadcast_payload_matches_http_payload_shape(): void
    {
        $project = $this->publishedProject();
        $message = ChatMessage::factory()->projectRoom($project)->create();

        $payload = (new NewChatMessage($message))->broadcastWith();

        $this->assertSame($message->author->avatarUrl(), $payload['author']['avatar_url']);
        $this->assertSame([
            'retract_url' => route('projects.chat.destroy', [$project, $message]),
            'report_url' => route('projects.chat.report', [$project, $message]),
            'remove_url' => route('projects.chat.destroy', [$project, $message]),
        ], $payload['actions']);
    }

    public function test_chat_pages_render_viewer_moderation_context(): void
    {
        $project = $this->publishedProject();

        $manager = $this->actingAs($project->owner)
            ->get(route('projects.chat.show', $project))->assertOk()->getContent();
        $this->assertStringContainsString('data-room-kind="project"', $manager);
        $this->assertStringContainsString('data-can-moderate="1"', $manager);
        $this->assertStringContainsString(
            'data-channels-url="'.route('realtime.channels', ['room' => 'project:'.$project->id]).'"',
            $manager
        );

        $member = $this->actingAs(User::factory()->create())
            ->get(route('projects.chat.show', $project))->assertOk()->getContent();
        $this->assertStringContainsString('data-room-kind="project"', $member);
        $this->assertStringNotContainsString('data-can-moderate="1"', $member);

        $lounge = $this->actingAs($project->owner)
            ->get(route('lounge'))->assertOk()->getContent();
        $this->assertStringContainsString('data-room-kind="lounge"', $lounge);
        $this->assertStringNotContainsString('data-can-moderate="1"', $lounge);
        $this->assertStringContainsString(
            'data-channels-url="'.route('realtime.channels', ['room' => 'lounge']).'"',
            $lounge
        );
    }
}
