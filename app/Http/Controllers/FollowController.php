<?php

namespace App\Http\Controllers;

use App\Models\DiscussionPost;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FollowController extends Controller
{
    public function followProject(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);

        $project->follows()->firstOrCreate(['user_id' => $request->user()->id]);

        return back()->with('status', 'Following this project. You will hear about official updates and @-mentions.');
    }

    public function unfollowProject(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);

        $project->follows()->where('user_id', $request->user()->id)->delete();

        return back()->with('status', 'Unfollowed.');
    }

    public function followThread(Request $request, Project $project, DiscussionPost $post): RedirectResponse
    {
        Gate::authorize('view', $project);
        $this->assertBelongsToProject($project, $post);

        $post->thread()->follows()->firstOrCreate(['user_id' => $request->user()->id]);

        return back()->with('status', 'Following this discussion. You will hear about new replies.');
    }

    public function unfollowThread(Request $request, Project $project, DiscussionPost $post): RedirectResponse
    {
        Gate::authorize('view', $project);
        $this->assertBelongsToProject($project, $post);

        $post->thread()->follows()->where('user_id', $request->user()->id)->delete();

        return back()->with('status', 'Unfollowed.');
    }
}
