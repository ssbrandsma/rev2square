<?php

namespace Tests\Feature;

use App\Enums\PublicationStatus;
use App\Models\DiscussionPost;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use App\Notifications\FollowedPostNotification;
use App\Notifications\ProjectUpdateNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FollowUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private function publishedProject(array $overrides = []): Project
    {
        return Project::factory()->create([
            'publication_status' => PublicationStatus::Published,
            ...$overrides,
        ]);
    }

    public function test_follow_and_unfollow_project_is_idempotent_and_view_gated(): void
    {
        $project = $this->publishedProject();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('projects.follow', $project))->assertRedirect();
        $this->actingAs($user)->post(route('projects.follow', $project))->assertRedirect();

        $this->assertSame(1, $project->follows()->count());

        $this->actingAs($user)->delete(route('projects.unfollow', $project))->assertRedirect();
        $this->assertSame(0, $project->follows()->count());

        $draft = Project::factory()->draft()->create();
        $this->actingAs($user)->post(route('projects.follow', $draft))->assertForbidden();
    }

    public function test_follow_thread_targets_the_thread(): void
    {
        $project = $this->publishedProject();
        $thread = DiscussionPost::factory()->for($project, 'project')->create();
        $reply = DiscussionPost::factory()->reply()->for($project, 'project')->create(['parent_id' => $thread->id]);
        $user = User::factory()->create();

        // Following from a reply follows the whole thread.
        $this->actingAs($user)
            ->post(route('projects.discussion.follow', [$project, $reply]))
            ->assertRedirect();

        $this->assertTrue($thread->follows()->where('user_id', $user->id)->exists());

        $this->actingAs($user)
            ->delete(route('projects.discussion.unfollow', [$project, $thread]))
            ->assertRedirect();

        $this->assertFalse($thread->follows()->where('user_id', $user->id)->exists());
    }

    public function test_updates_feed_lists_updates_and_notifies_followers(): void
    {
        Notification::fake();

        $project = $this->publishedProject();
        $follower = User::factory()->create();
        $project->follows()->create(['user_id' => $follower->id]);
        $plain = DiscussionPost::factory()->for($project, 'project')->create(['body' => 'Ordinary thread body.']);

        $this->actingAs($project->owner)
            ->post(route('projects.discussion.store', $project), [
                'type' => 'update',
                'title' => 'Milestone reached',
                'body' => 'The first prototype booted.',
            ])
            ->assertRedirect();

        // Ordinary visitors cannot publish updates.
        $this->actingAs(User::factory()->create())
            ->post(route('projects.discussion.store', $project), [
                'type' => 'update',
                'title' => 'Fake update',
                'body' => 'Should not land.',
            ])
            ->assertForbidden();

        $this->get(route('projects.updates.index', $project))
            ->assertOk()
            ->assertSee('Milestone reached', false)
            ->assertDontSee('Ordinary thread body.', false);

        $this->get(route('projects.discussion.index', $project))
            ->assertOk()
            ->assertSee('Ordinary thread body.', false)
            ->assertDontSee('Milestone reached', false);

        Notification::assertSentTo($follower, ProjectUpdateNotification::class);
        Notification::assertNothingSentTo($project->owner);
        $this->assertSame($plain->id, $plain->fresh()->id);
    }

    public function test_ordinary_posts_only_notify_opted_in_followers(): void
    {
        Notification::fake();

        $project = $this->publishedProject();
        $quiet = User::factory()->create();
        $loud = User::factory()->create(['notification_preferences' => ['followed_posts' => true]]);
        $project->follows()->create(['user_id' => $quiet->id]);
        $project->follows()->create(['user_id' => $loud->id]);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.discussion.store', $project), ['body' => 'A new community thread.'])
            ->assertRedirect();

        Notification::assertSentTo($loud, FollowedPostNotification::class);
        Notification::assertNothingSentTo($quiet);
    }

    public function test_muted_threads_stay_silent_for_replies(): void
    {
        Notification::fake();

        $project = $this->publishedProject();
        $author = User::factory()->create();
        $thread = DiscussionPost::factory()->for($project, 'project')->create(['user_id' => $author->id]);

        $this->actingAs($author)
            ->post(route('projects.discussion.mute', [$project, $thread]))
            ->assertRedirect();

        $this->actingAs(User::factory()->create())
            ->post(route('projects.discussion.replies.store', [$project, $thread]), ['body' => 'A reply you muted.'])
            ->assertRedirect();

        Notification::assertNothingSentTo($author);

        $this->actingAs($author)
            ->delete(route('projects.discussion.unmute', [$project, $thread]))
            ->assertRedirect();
    }

    public function test_maintainer_promotes_post_to_task_and_observation(): void
    {
        $project = $this->publishedProject();
        $milestone = Milestone::factory()->for($project, 'project')->create();
        $thread = DiscussionPost::factory()->for($project, 'project')->create(['body' => 'The volume knob crackles.']);

        $this->actingAs(User::factory()->create())
            ->get(route('projects.discussion.promote', [$project, $thread]))
            ->assertForbidden();

        $this->actingAs($project->owner)
            ->get(route('projects.discussion.promote', [$project, $thread]))
            ->assertOk();

        // A milestone from another project is rejected.
        $foreign = Milestone::factory()->create();
        $this->actingAs($project->owner)
            ->post(route('projects.discussion.promote.store', [$project, $thread]), [
                'target' => 'task',
                'title' => 'Fix knob',
                'body' => 'Clean the potentiometer.',
                'milestone_id' => $foreign->id,
            ])
            ->assertStatus(422);

        $this->actingAs($project->owner)
            ->post(route('projects.discussion.promote.store', [$project, $thread]), [
                'target' => 'task',
                'title' => 'Fix knob',
                'body' => 'Clean the potentiometer.',
                'milestone_id' => $milestone->id,
            ])
            ->assertRedirect();

        $task = $project->tasks()->firstOrFail();
        $this->assertSame('Fix knob', $task->title);
        $this->assertStringContainsString('Promoted from a discussion post', $task->description);
        $this->assertDatabaseHas('audit_events', ['event' => 'task.promoted']);

        $this->actingAs($project->owner)
            ->post(route('projects.discussion.promote.store', [$project, $thread]), [
                'target' => 'observation',
                'title' => 'Knob crackle',
                'body' => 'Audible at low volume.',
            ])
            ->assertRedirect();

        $observation = $project->observations()->firstOrFail();
        $this->assertFalse($observation->isVerified());
        $this->assertStringContainsString('Promoted from a discussion post', $observation->body);
        $this->assertDatabaseHas('audit_events', ['event' => 'observation.promoted']);

        // Removing the source post keeps the promoted snapshot intact.
        $thread->delete();
        $this->assertSame('Fix knob', $task->fresh()->title);
        $this->assertStringContainsString('Knob crackle', $observation->fresh()->title);
    }

    public function test_public_profile_shows_published_work_without_private_details(): void
    {
        $user = User::factory()->create(['email' => 'profile-private@example.test']);
        $published = $this->publishedProject(['owner_id' => $user->id]);
        $draft = Project::factory()->draft()->create(['owner_id' => $user->id, 'title' => 'Secret Draft Project']);

        $response = $this->get(route('users.show', $user))->assertOk();

        $response->assertSee($published->title, false);
        $response->assertDontSee('Secret Draft Project', false);
        $response->assertDontSee('profile-private@example.test', false);
        $this->assertSame($draft->id, $draft->fresh()->id);
    }

    public function test_export_includes_updates_and_discussion(): void
    {
        $project = $this->publishedProject();
        DiscussionPost::factory()->update()->for($project, 'project')->create(['title' => 'Exported update title']);
        DiscussionPost::factory()->for($project, 'project')->create(['body' => 'Exported discussion body.']);

        $json = $this->get(route('projects.export', [$project, 'json']))->assertOk()->json();

        $this->assertSame('Exported update title', $json['updates'][0]['title']);
        $this->assertSame('Exported discussion body.', $json['discussion'][0]['body']);

        $this->get(route('projects.export', [$project, 'md']))
            ->assertOk()
            ->assertSee('Exported update title', false)
            ->assertSee('Exported discussion body.', false);
    }

    public function test_inbox_hides_notifications_for_unpublished_projects(): void
    {
        $project = $this->publishedProject();
        $follower = User::factory()->create();
        $project->follows()->create(['user_id' => $follower->id]);

        $this->actingAs($project->owner)
            ->post(route('projects.discussion.store', $project), [
                'type' => 'update',
                'title' => 'Soon hidden update',
                'body' => 'Visible until unpublish.',
            ])
            ->assertRedirect();

        $this->actingAs($follower)->get(route('inbox.index'))->assertOk()->assertSee('Soon hidden update', false);

        $project->update(['publication_status' => PublicationStatus::Draft]);

        $this->actingAs($follower)->get(route('inbox.index'))->assertOk()->assertDontSee('Soon hidden update', false);
    }
}
