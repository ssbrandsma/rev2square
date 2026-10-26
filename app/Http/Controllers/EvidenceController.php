<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class EvidenceController extends Controller
{
    public function show(Project $project): View
    {
        Gate::authorize('update', $project);

        $repository = $project->repositories()->latest()->first();

        return view('projects.evidence', [
            'project' => $project,
            'repository' => $repository,
        ]);
    }
}
