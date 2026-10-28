<?php

namespace App\Http\Controllers;

use App\Enums\ContentOrigin;
use App\Enums\TaskStatus;
use App\Models\AuditEvent;
use App\Models\DiscussionPost;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PromotionController extends Controller
{
    public function create(Project $project, DiscussionPost $post): View
    {
        Gate::authorize('update', $project);
        abort_unless($post->project_id === $project->id, 404);

        $permalink = route('projects.discussion.show', [$project, $post->thread()]).($post->parent_id !== null ? '#reply-'.$post->id : '');

        return view('projects.discussion.promote', [
            'project' => $project,
            'post' => $post,
            'permalink' => $permalink,
            'milestones' => $project->milestones()->active()->orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request, Project $project, DiscussionPost $post): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($post->project_id === $project->id, 404);

        $data = $request->validate([
            'target' => ['required', 'in:observation,task'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'milestone_id' => ['nullable', 'integer', 'exists:milestones,id'],
        ]);

        if (($data['milestone_id'] ?? null) !== null) {
            abort_unless(
                $project->milestones()->whereKey($data['milestone_id'])->exists(),
                422,
                'Milestone does not belong to this project.'
            );
        }

        $permalink = route('projects.discussion.show', [$project, $post->thread()]).($post->parent_id !== null ? '#reply-'.$post->id : '');
        $attribution = "Promoted from a discussion post by {$post->author->name}: {$permalink}";

        if ($data['target'] === 'observation') {
            // A snapshot copy: later edits or removal of the source post
            // never rewrite the reviewed record.
            $observation = $project->observations()->create([
                'user_id' => $request->user()->id,
                'title' => trim($data['title']),
                'body' => trim($data['body'])."\n\n".$attribution,
            ]);

            AuditEvent::record('observation.promoted', $request->user(), $project, $observation, [
                'source_post_id' => $post->id,
                'source_permalink' => $permalink,
            ]);

            return redirect(route('projects.show', $project).'#observations')
                ->with('status', 'Discussion promoted to an observation. It starts unverified; verify it after review.');
        }

        $task = $project->tasks()->create([
            'milestone_id' => $data['milestone_id'] ?? null,
            'title' => trim($data['title']),
            'description' => trim($data['body'])."\n\n".$attribution,
            'status' => TaskStatus::Open,
            'origin' => ContentOrigin::Human,
            'manually_edited' => true,
        ]);

        AuditEvent::record('task.promoted', $request->user(), $project, $task, [
            'source_post_id' => $post->id,
            'source_permalink' => $permalink,
        ]);

        return redirect()->route('projects.tasks.show', [$project, $task])
            ->with('status', 'Discussion promoted to a task.');
    }
}
