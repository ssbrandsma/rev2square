<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class SavedProjectController extends Controller
{
    /**
     * Private bookmark, separate from following (notifications) and from
     * Working on (membership). Only projects the member can view.
     */
    public function store(Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);

        $project->saves()->firstOrCreate(['user_id' => request()->user()->id]);

        return back()->with('status', 'Saved to your workspace.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);

        $project->saves()->where('user_id', request()->user()->id)->delete();

        return back()->with('status', 'Removed from your saved projects.');
    }
}
