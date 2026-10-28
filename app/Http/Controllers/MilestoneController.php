<?php

namespace App\Http\Controllers;

use App\Enums\ContentOrigin;
use App\Enums\MilestoneStatus;
use App\Models\AuditEvent;
use App\Models\Milestone;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MilestoneController extends Controller
{
    public function create(Project $project): View
    {
        Gate::authorize('update', $project);

        return view('projects.milestones.create', ['project' => $project]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $data = $this->validatedData($request, $project);

        $milestone = $project->milestones()->create([
            ...$data,
            'status' => MilestoneStatus::Planned,
            'sort_order' => $data['sort_order'] ?? (int) ($project->milestones()->max('sort_order') ?? 0) + 1,
            'origin' => ContentOrigin::Human,
            'manually_edited' => true,
        ]);

        AuditEvent::record('milestone.created', $request->user(), $project, $milestone);

        return redirect()->route('projects.show', $project)
            ->with('status', 'Milestone created.');
    }

    public function edit(Project $project, Milestone $milestone): View
    {
        Gate::authorize('update', $project);
        abort_unless($milestone->project_id === $project->id, 404);

        return view('projects.milestones.edit', [
            'project' => $project,
            'milestone' => $milestone,
        ]);
    }

    public function update(Request $request, Project $project, Milestone $milestone): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($milestone->project_id === $project->id, 404);

        $data = $this->validatedData($request, $project, $milestone);

        $milestone->update([
            ...$data,
            'sort_order' => $data['sort_order'] ?? $milestone->sort_order,
            'manually_edited' => true,
        ]);

        AuditEvent::record('milestone.updated', $request->user(), $project, $milestone);

        return redirect()->route('projects.show', $project)
            ->with('status', 'Milestone updated.');
    }

    public function retire(Request $request, Project $project, Milestone $milestone): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($milestone->project_id === $project->id, 404);

        $milestone->update(['retired_at' => now()]);

        AuditEvent::record('milestone.retired', $request->user(), $project, $milestone);

        return redirect()->route('projects.show', $project)
            ->with('status', 'Milestone retired. Its tasks stay visible under All tasks.');
    }

    public function restore(Request $request, Project $project, Milestone $milestone): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($milestone->project_id === $project->id, 404);

        $milestone->update(['retired_at' => null]);

        AuditEvent::record('milestone.restored', $request->user(), $project, $milestone);

        return redirect()->route('projects.show', $project)
            ->with('status', 'Milestone restored.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request, Project $project, ?Milestone $ignore = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['nullable', 'string'],
            'target_date' => ['nullable', 'date'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $status = MilestoneStatus::tryFrom($data['status'] ?? MilestoneStatus::Planned->value)
            ?? MilestoneStatus::Planned;

        $duplicate = $project->milestones()->active()
            ->whereRaw('LOWER(title) = ?', [mb_strtolower(trim($data['title']))])
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->exists();

        if ($duplicate) {
            abort(422, 'A milestone with this title already exists on this project.');
        }

        return [
            'title' => trim($data['title']),
            'description' => $data['description'] ?? null,
            'status' => $status,
            'target_date' => $data['target_date'] ?? null,
            'sort_order' => $data['sort_order'] ?? null,
        ];
    }
}
