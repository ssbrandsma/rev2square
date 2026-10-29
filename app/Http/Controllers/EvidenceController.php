<?php

namespace App\Http\Controllers;

use App\Models\AgentConversation;
use App\Models\EvidenceSource;
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
            'observations' => $project->observations()->with(['author', 'variant.device', 'verifiedBy'])->latest()->get(),
            'testReports' => $project->testReports()->with(['author', 'variant.device', 'confirmedBy'])->latest()->get(),
        ]);
    }

    public function showSource(Project $project, EvidenceSource $source): View
    {
        Gate::authorize('update', $project);

        if ($source->project_id !== $project->id) {
            abort(404);
        }

        $current = $project->repositories()->latest()->first();
        $stale = $current !== null
            && is_string($current->last_commit_sha) && $current->last_commit_sha !== ''
            && $source->commit_sha !== $current->last_commit_sha;

        $backToConversation = null;
        $from = request()->query('from');

        if (is_string($from) && preg_match('/^agent:(\d+)$/', $from, $m)) {
            $conversation = AgentConversation::where('id', (int) $m[1])
                ->where('project_id', $project->id)
                ->first();

            if ($conversation !== null && $conversation->isAccessibleBy(auth()->user())) {
                $backToConversation = $conversation;
            }
        }

        return view('projects.evidence-show', [
            'project' => $project,
            'source' => $source,
            'repository' => $source->repository,
            'stale' => $stale,
            'backToConversation' => $backToConversation,
        ]);
    }
}
