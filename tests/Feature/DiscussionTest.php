<?php

namespace Tests\Feature;

use App\Enums\DiscussionType;
use App\Enums\ProjectActivity;
use App\Enums\PublicationStatus;
use App\Enums\ReportStatus;
use App\Models\ContributorTask;
use App\Models\DiscussionMute;
use App\Models\DiscussionPost;
use App\Models\Observation;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use App\Notifications\MentionNotification;
use App\Notifications\ProjectUpdateNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscussionTest extends TestCase
{
    use RefreshDatabase;

    private function publishedProject(array $overrides = []): Project
    {
        return Project::factory()->create([
            'publication_status' => PublicationStatus::Published,
            ...$overrides,
        ]);
    }

    public function test_guest_is_redirected_to_login_when_posting(): void
    {
        $project = $this->publishedProject();

        $this->get(route('projects.discussion.index', $project))->assertOk();
        $this->post(route('projects.discussion.store', $project), ['body' => 'Hi'])
            ->assertRedirect(route('login'));
    }

    public function test_member_can_post_thread_and_reply(): void
    {
        $project = $this->publishedProject();
        $task = ContributorTask::factory()->create(['project_id' => $project->id]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.discussion.store', $project), [
                'body' => 'Has anyone measured the standby current?',
                'contributor_task_id' => $task->id,
                'submission_key' => 'key-thread-1',
            ])
            ->assertRedirect();

        $thread = DiscussionPost::firstOrFail();
        $this->assertSame($task->id, $thread->contributor_task_id);
        $this->assertSame(DiscussionType::Post, $thread->type);

        $this->get(route('projects.discussion.index', $project))
            ->assertOk()
            ->assertSee('Has anyone measured the standby current?', false)
            ->assertSee('0 replies', false);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.discussion.replies.store', [$project, $thread]), [
                'body' => 'Yes — 40mA on my unit.',
                'submission_key' => 'key-reply-1',
            ])
            ->assertRedirect(route('projects.discussion.show', [$project, $thread]).'#reply-'.DiscussionPost::latest('id')->firstOrFail()->id);

        $this->get(route('projects.discussion.show', [$project, $thread]))
            ->assertOk()
            ->assertSee('40mA on my unit', false);
    }

    public function test_empty_and_oversized_posts_are_rejected(): void
    {
        $project = $this->publishedProject();

        $this->actingAs(User::factory()->create())
            ->post(route('projects.discussion.store', $project), ['body' => '   '])
            ->assertSessionHasErrors('body');

        $this->actingAs(User::factory()->create())
            ->post(route('projects.discussion.store', $project), ['body' => str_repeat('x', 10001)])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, DiscussionPost::count());
    }

    public function test_threads_sort_by_activity_and_replies_read_oldest_first(): void
    {
        $project = $this->publishedProject();
        $old = DiscussionPost::factory()->create(['project_id' => $project->id, 'body' => 'Old thread starter text.', 'created_at' => now()->subDays(3)]);
        $new = DiscussionPost::factory()->create(['project_id' => $project->id, 'body' => 'New thread starter text.', 'created_at' => now()->subDay()]);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.discussion.replies.store', [$project, $old]), ['body' => 'Fresh reply.']);

        $html = $this->get(route('projects.discussion.index', $project))->getContent();
        $this->assertLessThan(strpos($html, 'New thread starter text.'), strpos($html, 'Old thread starter text.'));

        $first = DiscussionPost::factory()->create(['project_id' => $project->id, 'parent_id' => $new->id, 'body' => 'Reply alpha ordered.', 'created_at' => now()->subHour()]);
        $second = DiscussionPost::factory()->create(['project_id' => $project->id, 'parent_id' => $new->id, 'body' => 'Reply beta ordered.']);

        $html = $this->get(route('projects.discussion.show', [$project, $new]))->getContent();
        $this->assertLessThan(strpos($html, 'Reply beta ordered.'), strpos($html, 'Reply alpha ordered.'));
    }

    public function test_reply_permalink_redirects_to_anchor(): void
    {
        $project = $this->publishedProject();
        $thread = DiscussionPost::factory()->create(['project_id' => $project->id]);
        $reply = DiscussionPost::factory()->create(['project_id' => $project->id, 'parent_id' => $thread->id]);

        $this->get(route('projects.discussion.show', [$project, $reply]))
            ->assertRedirect(route('projects.discussion.show', [$project, $thread]).'#reply-'.$reply->id);
    }

    public function test_author_can_edit_own_post_only(): void
    {
        $project = $this->publishedProject();
        $post = DiscussionPost::factory()->create(['project_id' => $project->id]);

        $this->actingAs(User::factory()->create())
            ->patch(route('projects.discussion.update', [$project, $post]), ['body' => 'Hijacked.'])
            ->assertForbidden();

        $this->actingAs($post->author)
            ->patch(route('projects.discussion.update', [$project, $post]), ['body' => 'Corrected wording here.'])
            ->assertRedirect();

        $this->assertSame('Corrected wording here.', $post->fresh()->body);
        $this->assertNotNull($post->fresh()->edited_at);

        $this->get(route('projects.discussion.show', [$project, $post->thread()]))
            ->assertSee('Edited', false);
    }

    public function test_author_removal_tombstones_threads_with_replies(): void
    {
        $project = $this->publishedProject();
        $thread = DiscussionPost::factory()->create(['project_id' => $project->id, 'body' => 'Thread starter text.']);
        $reply = DiscussionPost::factory()->create(['project_id' => $project->id, 'parent_id' => $thread->id, 'body' => 'Surviving reply text.']);

        $this->actingAs($thread->author)
            ->delete(route('projects.discussion.destroy', [$project, $thread]))
            ->assertRedirect();

        $this->assertSoftDeleted($thread);

        // Tombstoned thread keeps a stable link with readable replies.
        $this->get(route('projects.discussion.show', [$project, $thread]))
            ->assertOk()
            ->assertDontSee('Thread starter text.', false)
            ->assertSee('Surviving reply text.', false)
            ->assertSee('was removed', false);

        // Reply-less posts disappear entirely.
        $lonely = DiscussionPost::factory()->create(['project_id' => $project->id]);
        $this->actingAs($lonely->author)
            ->delete(route('projects.discussion.destroy', [$project, $lonely]))
            ->assertRedirect(route('projects.discussion.index', $project));
        $this->assertModelMissing($lonely);
    }

    public function test_maintainer_moderation_is_logged_with_reason(): void
    {
        $project = $this->publishedProject();
        $post = DiscussionPost::factory()->create(['project_id' => $project->id]);

        $this->actingAs(User::factory()->create())
            ->patch(route('projects.discussion.moderate', [$project, $post]), ['reason' => 'Nope.'])
            ->assertForbidden();

        $this->actingAs($project->owner)
            ->patch(route('projects.discussion.moderate', [$project, $post]), ['reason' => 'Spam link.'])
            ->assertRedirect();

        $this->assertSoftDeleted($post);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'discussion.moderated',
            'subject_type' => DiscussionPost::class,
            'subject_id' => $post->id,
        ]);
    }

    public function test_announcements_are_maintainer_only(): void
    {
        $project = $this->publishedProject();

        $this->actingAs(User::factory()->create())
            ->post(route('projects.discussion.store', $project), [
                'body' => 'Fake announcement.',
                'type' => DiscussionType::Announcement->value,
            ])
            ->assertForbidden();

        $this->actingAs($project->owner)
            ->post(route('projects.discussion.store', $project), [
                'body' => 'Real announcement.',
                'type' => DiscussionType::Announcement->value,
            ])
            ->assertRedirect();

        $this->get(route('projects.discussion.index', $project))->assertSee('Announcement', false);
    }

    public function test_archived_projects_close_and_reopen_deliberately(): void
    {
        $project = $this->publishedProject(['activity' => ProjectActivity::Active]);

        $this->actingAs($project->owner)
            ->put(route('projects.update', $project), [
                'title' => $project->title,
                'pitch' => $project->pitch,
                'body' => $project->body,
                'phase' => $project->phase->value,
                'activity' => ProjectActivity::Archived->value,
                'publication_status' => PublicationStatus::Published->value,
            ])
            ->assertRedirect();

        $this->assertFalse($project->fresh()->discussion_open);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.discussion.store', $project), ['body' => 'Too late.'])
            ->assertSessionHasErrors('body');

        $this->get(route('projects.discussion.index', $project))
            ->assertSee('archived', false);

        // Deliberate reopen restores posting.
        $this->actingAs($project->owner)
            ->patch(route('projects.discussion.toggle', $project), ['open' => '1'])
            ->assertRedirect();

        $this->actingAs(User::factory()->create())
            ->post(route('projects.discussion.store', $project), ['body' => 'Welcome back.'])
            ->assertRedirect();

        $this->assertSame(1, DiscussionPost::count());

        // Ordinary edits do not flip the deliberate reopen back.
        $this->actingAs($project->owner)
            ->put(route('projects.update', $project), [
                'title' => $project->title.' (2nd ed)',
                'pitch' => $project->pitch,
                'body' => $project->body,
                'phase' => $project->phase->value,
                'activity' => ProjectActivity::Archived->value,
                'publication_status' => PublicationStatus::Published->value,
            ])
            ->assertRedirect();

        $this->assertTrue($project->fresh()->discussion_open);
    }

    public function test_draft_discussions_are_restricted_and_unpublish_hides_everything(): void
    {
        $draft = Project::factory()->draft()->create();
        $thread = DiscussionPost::factory()->create(['project_id' => $draft->id]);

        $this->actingAs(User::factory()->create())
            ->get(route('projects.discussion.index', $draft))
            ->assertForbidden();

        $this->actingAs($draft->owner)
            ->get(route('projects.discussion.index', $draft))
            ->assertOk();

        // Publish, notify, then unpublish: excerpts disappear with access.
        $draft->update(['publication_status' => PublicationStatus::Published]);
        $member = User::factory()->create();

        $this->actingAs($member)
            ->post(route('projects.discussion.replies.store', [$draft, $thread]), ['body' => 'Hello here.'])
            ->assertRedirect();

        $this->assertSame(1, $thread->author->notifications()->count());

        $draft->update(['publication_status' => PublicationStatus::Draft]);

        $this->actingAs($thread->author)
            ->get(route('projects.discussion.index', $draft))
            ->assertForbidden();

        $inbox = $this->actingAs($thread->author)->get(route('inbox.index'));
        $inbox->assertOk()->assertDontSee('Hello here.', false);
    }

    public function test_double_submit_creates_one_post(): void
    {
        $project = $this->publishedProject();
        $user = User::factory()->create();

        $payload = ['body' => 'Posted once, submitted twice.', 'submission_key' => 'double-key-1'];

        $this->actingAs($user)->post(route('projects.discussion.store', $project), $payload)->assertRedirect();
        $this->actingAs($user)->post(route('projects.discussion.store', $project), $payload)->assertRedirect();

        $this->assertSame(1, DiscussionPost::count());
    }

    public function test_cross_project_references_are_rejected(): void
    {
        $project = $this->publishedProject();
        $other = $this->publishedProject();
        $foreignTask = ContributorTask::factory()->create(['project_id' => $other->id]);
        $foreignThread = DiscussionPost::factory()->create(['project_id' => $other->id]);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.discussion.store', $project), [
                'body' => 'Sneaky.',
                'contributor_task_id' => $foreignTask->id,
            ])
            ->assertStatus(422);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.discussion.replies.store', [$project, $foreignThread]), ['body' => 'Sneaky.'])
            ->assertNotFound();
    }

    public function test_muted_threads_stay_silent(): void
    {
        $project = $this->publishedProject();
        $thread = DiscussionPost::factory()->create(['project_id' => $project->id]);

        DiscussionMute::create(['user_id' => $thread->author->id, 'discussion_post_id' => $thread->id]);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.discussion.replies.store', [$project, $thread]), ['body' => 'Muted reply.'])
            ->assertRedirect();

        $this->assertSame(0, $thread->author->notifications()->count());
    }

    public function test_mentions_notify_and_preferences_silence_them(): void
    {
        $project = $this->publishedProject();
        $mentioned = User::factory()->create(['name' => 'Quite Unique Name']);
        $project->follows()->create(['user_id' => $mentioned->id]);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.discussion.store', $project), [
                'body' => 'Hey @Quite Unique Name, what do you think?',
            ])
            ->assertRedirect();

        $this->assertSame(1, $mentioned->notifications()->where('type', MentionNotification::class)->count());

        $mentioned->forceFill(['notification_preferences' => ['mentions' => false]])->save();

        $this->actingAs(User::factory()->create())
            ->post(route('projects.discussion.store', $project), [
                'body' => 'Again @Quite Unique Name, still thinking?',
            ])
            ->assertRedirect();

        $this->assertSame(1, $mentioned->notifications()->where('type', MentionNotification::class)->count());
    }

    public function test_updates_notify_followers_but_ordinary_threads_do_not(): void
    {
        $project = $this->publishedProject();
        $follower = User::factory()->create();
        $project->follows()->create(['user_id' => $follower->id]);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.discussion.store', $project), ['body' => 'Ordinary chatter.'])
            ->assertRedirect();

        $this->assertSame(0, $follower->notifications()->count());

        $this->actingAs($project->owner)
            ->post(route('projects.discussion.store', $project), [
                'body' => 'Milestone reached.',
                'type' => DiscussionType::Update->value,
                'title' => 'First boot achieved',
            ])
            ->assertRedirect();

        $this->assertSame(1, $follower->notifications()->where('type', ProjectUpdateNotification::class)->count());

        $this->get(route('projects.updates.index', $project))
            ->assertOk()
            ->assertSee('First boot achieved', false);

        $this->get(route('projects.discussion.index', $project))
            ->assertDontSee('First boot achieved', false);
    }

    public function test_reporting_and_moderation_queue(): void
    {
        $project = $this->publishedProject();
        $post = DiscussionPost::factory()->create(['project_id' => $project->id, 'body' => 'Reported post body here.']);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.discussion.report', [$project, $post]), ['reason' => 'Looks like spam.'])
            ->assertRedirect();

        // Duplicate pending reports are rejected.
        $this->actingAs($post->author)->post(route('projects.discussion.report', [$project, $post]), ['reason' => 'x'])
            ->assertRedirect();

        $this->actingAs(User::factory()->create())
            ->get(route('projects.moderation.index', $project))
            ->assertForbidden();

        $this->actingAs($project->owner)
            ->get(route('projects.moderation.index', $project))
            ->assertOk()
            ->assertSee('Reported post body here.', false);

        $report = Report::firstOrFail();

        $this->actingAs($project->owner)
            ->patch(route('projects.moderation.review', [$project, $report]), [
                'decision' => 'remove',
                'reason' => 'Confirmed spam.',
            ])
            ->assertRedirect();

        $this->assertSame(ReportStatus::Actioned, $report->fresh()->status);
        $this->assertSoftDeleted($post);
    }

    public function test_promotion_creates_snapshot_records_with_attribution(): void
    {
        $project = $this->publishedProject();
        $post = DiscussionPost::factory()->create(['project_id' => $project->id, 'body' => 'The 3V3 rail measures 3.31V.']);

        $this->actingAs(User::factory()->create())
            ->get(route('projects.discussion.promote', [$project, $post]))
            ->assertForbidden();

        $this->actingAs($project->owner)
            ->get(route('projects.discussion.promote', [$project, $post]))
            ->assertOk()
            ->assertSee('The 3V3 rail measures 3.31V.', false);

        $this->actingAs($project->owner)
            ->post(route('projects.discussion.promote.store', [$project, $post]), [
                'target' => 'observation',
                'title' => 'Verified rail voltage',
                'body' => 'Rail confirmed at 3.31V on the bench.',
            ])
            ->assertRedirect();

        $observation = Observation::firstOrFail();
        $this->assertStringContainsString('Rail confirmed at 3.31V', $observation->body);
        $this->assertStringContainsString('discussion post by', $observation->body);
        $this->assertFalse($observation->isVerified());

        // Removing the source never rewrites the reviewed record.
        $post->forceDelete();
        $this->assertSame($observation->body, $observation->fresh()->body);
    }

    public function test_task_detail_shows_linked_discussions(): void
    {
        $project = $this->publishedProject();
        $task = ContributorTask::factory()->create(['project_id' => $project->id]);
        DiscussionPost::factory()->create([
            'project_id' => $project->id,
            'contributor_task_id' => $task->id,
            'body' => 'Question about this exact task here.',
        ]);

        $this->get(route('projects.tasks.show', [$project, $task]))
            ->assertOk()
            ->assertSee('Linked discussions', false)
            ->assertSee('Question about this exact task here.', false);
    }

    public function test_follow_and_unfollow_project(): void
    {
        $project = $this->publishedProject();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.follow', $project))
            ->assertRedirect();

        $this->assertTrue($project->follows()->where('user_id', $user->id)->exists());

        $this->actingAs($user)
            ->delete(route('projects.unfollow', $project))
            ->assertRedirect();

        $this->assertFalse($project->follows()->where('user_id', $user->id)->exists());
    }
}
