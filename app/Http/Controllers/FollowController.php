<?php

namespace App\Http\Controllers;

use App\Models\DiscussionPost;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class FollowController extends Controller
{
    public function followProject(Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);

        $project->follows()->firstOrCreate(['user_id' => request()->user()->id]);

        return back()->with('status', 'Following this project. You will hear about official updates and @-mentions.');
    }

    public function unfollowProject(Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);

        $project->follows()->where('user_id', request()->user()->id)->delete();

        return back()->with('status', 'Unfollowed.');
    }

    public function followThread(Project $project, DiscussionPost $post): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($post->project_id === $project->id, 404);

        $post->thread()->follows()->firstOrCreate(['user_id' => request()->user()->id]);

        return back()->with('status', 'Following this discussion. You will hear about new replies.');
    }

    public function unfollowThread(Project $project, DiscussionPost $post): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($post->project_id === $project->id, 404);

        $post->thread()->follows()->where('user_id', request()->user()->id)->delete();

        return back()->with('status', 'Unfollowed.');
    }
}
