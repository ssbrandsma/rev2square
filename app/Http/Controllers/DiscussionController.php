<?php

namespace App\Http\Controllers;

use App\Enums\DiscussionType;
use App\Models\AuditEvent;
use App\Models\DiscussionMute;
use App\Models\DiscussionPost;
use App\Models\Project;
use App\Models\User;
use App\Notifications\DiscussionReplyNotification;
use App\Notifications\FollowedPostNotification;
use App\Notifications\MentionNotification;
use App\Notifications\ProjectUpdateNotification;
use App\Services\Community\ProjectActionPolicy;
use App\Services\Discussion\Mentions;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DiscussionController extends Controller
{
    public function index(Project $project): View
    {
        Gate::authorize('view', $project);

        $threads = $project->discussionThreads()
            ->with(['author', 'task'])
            ->withCount('replies')
            ->whereIn('type', [DiscussionType::Post, DiscussionType::Announcement])
            ->orderByRaw('COALESCE(last_reply_at, created_at) DESC, id DESC')
            ->paginate(10);

        return view('projects.discussion.index', [
            'project' => $project,
            'threads' => $threads,
            'denial' => ProjectActionPolicy::discussionDenial($project, auth()->user()),
        ]);
    }

    public function updatesIndex(Project $project): View
    {
        Gate::authorize('view', $project);

        $updates = $project->discussionThreads()
            ->with(['author'])
            ->withCount('replies')
            ->where('type', DiscussionType::Update)
            ->orderByDesc('id')
            ->paginate(10);

        return view('projects.discussion.updates', [
            'project' => $project,
            'updates' => $updates,
            'denial' => ProjectActionPolicy::discussionDenial($project, auth()->user()),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);

        if ($denial = ProjectActionPolicy::discussionDenial($project, $request->user())) {
            return back()->withInput()->withErrors(['body' => $denial]);
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000', fn ($attr, $value, $fail): mixed => trim((string) $value) === '' ? $fail('Write something before posting.') : null],
            'type' => ['nullable', 'string'],
            'title' => ['nullable', 'string', 'max:255'],
            'contributor_task_id' => ['nullable', 'integer', 'exists:contributor_tasks,id'],
            'submission_key' => ['nullable', 'string', 'max:64'],
        ]);

        $type = DiscussionType::tryFrom($data['type'] ?? DiscussionType::Post->value) ?? DiscussionType::Post;

        if (in_array($type, [DiscussionType::Announcement, DiscussionType::Update], true)
            && ! $project->managedBy($request->user())) {
            abort(403, 'Only maintainers can publish announcements and updates.');
        }

        if ($type === DiscussionType::Update && trim((string) ($data['title'] ?? '')) === '') {
            return back()->withInput()->withErrors(['title' => 'Updates need a title.']);
        }

        if (($data['contributor_task_id'] ?? null) !== null) {
            abort_unless(
                $project->tasks()->whereKey($data['contributor_task_id'])->exists(),
                422,
                'Task does not belong to this project.'
            );
        }

        try {
            $post = $project->discussionPosts()->create([
                'user_id' => $request->user()->id,
                'parent_id' => null,
                'contributor_task_id' => $data['contributor_task_id'] ?? null,
                'type' => $type,
                'title' => $data['title'] ?? null,
                'body' => trim($data['body']),
                'submission_key' => $data['submission_key'] ?? Str::uuid()->toString(),
            ]);
        } catch (QueryException $e) {
            // Double submission with the same key lands on the stored post.
            if (($data['submission_key'] ?? null) && Str::contains($e->getMessage(), 'submission_key')) {
                $existing = DiscussionPost::where('submission_key', $data['submission_key'])->first();

                if ($existing !== null) {
                    return redirect()->route(
                        $existing->type === DiscussionType::Update ? 'projects.updates.show' : 'projects.discussion.show',
                        [$project, $existing]
                    );
                }
            }

            throw $e;
        }

        AuditEvent::record($type === DiscussionType::Update ? 'discussion.update_published' : 'discussion.posted', $request->user(), $project, $post);

        $this->notifyMentions($project, $post, $request->user());
        $this->notifyNewThread($project, $post, $request->user());

        return redirect()->route(
            $type === DiscussionType::Update ? 'projects.updates.show' : 'projects.discussion.show',
            [$project, $post]
        )->with('status', $type === DiscussionType::Update ? 'Update published.' : 'Posted to the discussion.');
    }

    public function show(Project $project, int $post): View|RedirectResponse
    {
        Gate::authorize('view', $project);

        // Tombstoned threads stay reachable so saved links keep working.
        $thread = DiscussionPost::withTrashed()
            ->where('project_id', $project->id)
            ->whereNull('parent_id')
            ->find($post);

        if ($thread === null) {
            // A reply id redirects to its position in the thread.
            $reply = DiscussionPost::where('project_id', $project->id)->findOrFail($post);
            abort_unless($reply->parent_id !== null, 404);

            return redirect(route('projects.discussion.show', [$project, $reply->parent_id]).'#reply-'.$reply->id);
        }

        $thread->load(['author', 'task']);

        $thread->load(['author', 'task']);

        $replies = $thread->replies()->with(['author', 'task'])->paginate(20);

        return view('projects.discussion.show', [
            'project' => $project,
            'thread' => $thread,
            'replies' => $replies,
            'denial' => ProjectActionPolicy::discussionDenial($project, auth()->user()),
            'muted' => auth()->check() && DiscussionMute::where([
                'user_id' => auth()->id(),
                'discussion_post_id' => $thread->id,
            ])->exists(),
            'following' => auth()->check() && $thread->follows()->where('user_id', auth()->id())->exists(),
        ]);
    }

    public function reply(Request $request, Project $project, DiscussionPost $post): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($post->project_id === $project->id, 404);
        abort_unless($post->parent_id === null, 422, 'Replies attach to the thread, not to another reply.');

        if ($denial = ProjectActionPolicy::discussionDenial($project, $request->user())) {
            return back()->withInput()->withErrors(['body' => $denial]);
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000', fn ($attr, $value, $fail): mixed => trim((string) $value) === '' ? $fail('Write something before replying.') : null],
            'submission_key' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $reply = $project->discussionPosts()->create([
                'user_id' => $request->user()->id,
                'parent_id' => $post->id,
                'type' => DiscussionType::Post,
                'body' => trim($data['body']),
                'submission_key' => $data['submission_key'] ?? Str::uuid()->toString(),
            ]);
        } catch (QueryException $e) {
            if (($data['submission_key'] ?? null) && Str::contains($e->getMessage(), 'submission_key')) {
                $existing = DiscussionPost::where('submission_key', $data['submission_key'])->first();

                if ($existing !== null) {
                    return redirect(route('projects.discussion.show', [$project, $post]).'#reply-'.$existing->id);
                }
            }

            throw $e;
        }

        $post->update(['last_reply_at' => now()]);

        AuditEvent::record('discussion.replied', $request->user(), $project, $reply);

        $this->notifyReply($project, $post, $reply, $request->user());
        $this->notifyMentions($project, $reply, $request->user());

        return redirect(route('projects.discussion.show', [$project, $post]).'#reply-'.$reply->id)
            ->with('status', 'Reply posted.');
    }

    public function edit(Project $project, DiscussionPost $post): View
    {
        Gate::authorize('view', $project);
        abort_unless($post->project_id === $project->id, 404);
        abort_unless($post->user_id === auth()->id(), 403, 'Only the author can edit this post.');

        return view('projects.discussion.edit', ['project' => $project, 'post' => $post]);
    }

    public function update(Request $request, Project $project, DiscussionPost $post): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($post->project_id === $project->id, 404);
        abort_unless($post->user_id === $request->user()->id, 403, 'Only the author can edit this post.');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000', fn ($attr, $value, $fail): mixed => trim((string) $value) === '' ? $fail('A post cannot be emptied; remove it instead.') : null],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $post->update([
            'body' => trim($data['body']),
            'title' => $post->parent_id === null ? ($data['title'] ?? $post->title) : null,
            'edited_at' => now(),
        ]);

        AuditEvent::record('discussion.edited', $request->user(), $project, $post);

        $thread = $post->thread();

        return redirect()->route(
            $thread->type === DiscussionType::Update ? 'projects.updates.show' : 'projects.discussion.show',
            [$project, $thread]
        )->with('status', 'Post updated.');
    }

    public function destroy(Project $project, DiscussionPost $post): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($post->project_id === $project->id, 404);
        abort_unless($post->user_id === request()->user()->id, 403, 'Only the author can remove this post.');

        $thread = $post->thread();
        $isThread = $post->is($thread);
        $indexRoute = $thread->type === DiscussionType::Update ? 'projects.updates.index' : 'projects.discussion.index';
        $showRoute = $thread->type === DiscussionType::Update ? 'projects.updates.show' : 'projects.discussion.show';

        // Documented removal policy: reply-less posts disappear; threads
        // with replies stay as tombstones so context survives.
        if ($isThread && $post->replies()->exists()) {
            $post->delete();
            AuditEvent::record('discussion.removed', request()->user(), $project, $post, ['tombstone' => true]);

            return redirect()->route($showRoute, [$project, $thread])
                ->with('status', 'Post removed. Replies stay readable under a placeholder.');
        }

        AuditEvent::record('discussion.removed', request()->user(), $project, $post);
        $post->forceDelete();

        if ($isThread) {
            return redirect()->route($indexRoute, $project)->with('status', 'Post removed.');
        }

        return redirect()->route($showRoute, [$project, $thread])->with('status', 'Reply removed.');
    }

    public function moderate(Request $request, Project $project, DiscussionPost $post): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($post->project_id === $project->id, 404);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $post->delete();

        AuditEvent::record('discussion.moderated', $request->user(), $project, $post, [
            'reason' => $data['reason'],
        ]);

        $thread = $post->thread();
        $showRoute = $thread->type === DiscussionType::Update ? 'projects.updates.show' : 'projects.discussion.show';

        return redirect()->route($showRoute, [$project, $thread])
            ->with('status', 'Post removed by a maintainer. The removal is logged.');
    }

    public function toggle(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validate(['open' => ['required', 'boolean']]);

        $project->update(['discussion_open' => $data['open']]);

        AuditEvent::record($data['open'] ? 'discussion.reopened' : 'discussion.closed', $request->user(), $project);

        return back()->with('status', $data['open']
            ? 'Discussion reopened. New posts, replies, and chat are allowed again.'
            : 'Discussion closed. Existing conversation stays readable.');
    }

    public function mute(Project $project, DiscussionPost $post): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($post->project_id === $project->id, 404);

        $thread = $post->thread();

        DiscussionMute::firstOrCreate([
            'user_id' => request()->user()->id,
            'discussion_post_id' => $thread->id,
        ]);

        return back()->with('status', 'Thread muted. You will not be notified about new replies.');
    }

    public function unmute(Project $project, DiscussionPost $post): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($post->project_id === $project->id, 404);

        DiscussionMute::where([
            'user_id' => request()->user()->id,
            'discussion_post_id' => $post->thread()->id,
        ])->delete();

        return back()->with('status', 'Thread unmuted.');
    }

    /**
     * Notify the thread author and followers about a reply. Muted threads,
     * self-actions, and disabled preferences stay silent; one recipient is
     * never notified twice for the same reply.
     */
    private function notifyReply(Project $project, DiscussionPost $thread, DiscussionPost $reply, User $actor): void
    {
        $recipients = collect([$thread->author])
            ->concat($thread->follows()->with('user')->get()->pluck('user'))
            ->filter()
            ->unique('id')
            ->reject(fn (User $user): bool => $user->id === $actor->id
                || ! $user->wantsNotification('discussion_replies')
                || DiscussionMute::where(['user_id' => $user->id, 'discussion_post_id' => $thread->id])->exists());

        foreach ($recipients as $recipient) {
            $recipient->notify(new DiscussionReplyNotification($reply));
        }
    }

    private function notifyNewThread(Project $project, DiscussionPost $thread, User $actor): void
    {
        if ($thread->type === DiscussionType::Update) {
            $followers = $project->follows()->with('user')->get()->pluck('user')->filter()
                ->reject(fn (User $user): bool => $user->id === $actor->id || ! $user->wantsNotification('project_updates'));

            foreach ($followers as $follower) {
                $follower->notify(new ProjectUpdateNotification($thread));
            }

            return;
        }

        $followers = $project->follows()->with('user')->get()->pluck('user')->filter()
            ->reject(fn (User $user): bool => $user->id === $actor->id || ! $user->wantsNotification('followed_posts'));

        foreach ($followers as $follower) {
            $follower->notify(new FollowedPostNotification($thread));
        }
    }

    private function notifyMentions(Project $project, DiscussionPost $post, User $actor): void
    {
        $candidates = $project->managers();

        $participants = $project->discussionPosts()
            ->with('author')
            ->latest()
            ->take(100)
            ->get()
            ->pluck('author')
            ->filter();

        $followers = $project->follows()->with('user')->get()->pluck('user')->filter();

        $mentioned = Mentions::parse($post->body, $candidates->concat($participants)->concat($followers)->unique('id'))
            ->reject(fn (User $user): bool => $user->id === $actor->id || ! $user->wantsNotification('mentions'));

        foreach ($mentioned as $user) {
            $user->notify(new MentionNotification($post));
        }
    }
}
