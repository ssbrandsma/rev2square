<?php

namespace App\Http\Controllers;

use App\Enums\ContentOrigin;
use App\Enums\TaskStatus;
use App\Models\AuditEvent;
use App\Models\ContributorTask;
use App\Models\Project;
use App\Models\User;
use App\Services\Community\TaskWorkflow;
use App\Support\UniqueTitle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TaskController extends Controller
{
    public function show(Project $project, ContributorTask $task): View
    {
        Gate::authorize('view', $project);
        $this->assertBelongsToProject($project, $task);

        $task->load(['milestone', 'assignee']);

        $history = AuditEvent::where('subject_type', ContributorTask::class)
            ->where('subject_id', $task->id)
            ->with('user')
            ->orderByDesc('id')
            ->get();

        $transitions = auth()->check()
            ? TaskWorkflow::allowedTransitions($task, auth()->user())
            : [];

        $candidates = User::whereHas('offers', fn ($query) => $query->where('project_id', $project->id))->get();
        if ($task->assignee && ! $candidates->contains('id', $task->assignee->id)) {
            $candidates->push($task->assignee);
        }

        return view('projects.tasks.show', [
            'project' => $project,
            'task' => $task,
            'history' => $history,
            'assignees' => AuditEvent::assigneeMap($history),
            'transitions' => $transitions,
            'candidates' => $candidates,
            'canManage' => auth()->check() && $project->managedBy(auth()->user()),
            'discussions' => $task->discussionPosts()->with('author')->latest()->take(10)->get(),
        ]);
    }

    public function create(Project $project): View
    {
        Gate::authorize('update', $project);

        return view('projects.tasks.create', [
            'project' => $project,
            'milestones' => $project->milestones()->active()->orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $data = $this->validatedData($request, $project);

        $task = $project->tasks()->create([
            ...$data,
            'status' => TaskStatus::Open,
            'origin' => ContentOrigin::Human,
            'manually_edited' => true,
        ]);

        AuditEvent::record('task.created', $request->user(), $project, $task);

        return redirect()->route('projects.tasks.show', [$project, $task])
            ->with('status', 'Task created.');
    }

    public function edit(Project $project, ContributorTask $task): View
    {
        Gate::authorize('update', $project);
        $this->assertBelongsToProject($project, $task);

        return view('projects.tasks.edit', [
            'project' => $project,
            'task' => $task,
            'milestones' => $project->milestones()->active()->orderBy('sort_order')->get(),
        ]);
    }

    public function update(Request $request, Project $project, ContributorTask $task): RedirectResponse
    {
        Gate::authorize('update', $project);
        $this->assertBelongsToProject($project, $task);

        $task->update([
            ...$this->validatedData($request, $project, $task),
            'manually_edited' => true,
        ]);

        AuditEvent::record('task.updated', $request->user(), $project, $task);

        return redirect()->route('projects.tasks.show', [$project, $task])
            ->with('status', 'Task updated.');
    }

    public function transition(Request $request, Project $project, ContributorTask $task): RedirectResponse
    {
        Gate::authorize('view', $project);
        $this->assertBelongsToProject($project, $task);

        $data = $request->validate([
            'status' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $to = TaskStatus::tryFrom($data['status']);
        abort_unless($to !== null, 422, 'Unknown task status.');

        TaskWorkflow::transition($task, $to, $request->user(), $data['note'] ?? null);

        return redirect()->route('projects.tasks.show', [$project, $task])
            ->with('status', "Task moved to {$to->label()}.");
    }

    public function assign(Request $request, Project $project, ContributorTask $task): RedirectResponse
    {
        Gate::authorize('update', $project);
        $this->assertBelongsToProject($project, $task);

        $data = $request->validate([
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $assignee = $data['assignee_id'] !== null ? User::findOrFail($data['assignee_id']) : null;

        TaskWorkflow::assign($task, $assignee, $request->user());

        return redirect()->route('projects.tasks.show', [$project, $task])
            ->with('status', $assignee ? "Task assigned to {$assignee->name}." : 'Task unassigned.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request, Project $project, ?ContributorTask $ignore = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'definition_of_done' => ['nullable', 'string', 'max:10000'],
            'skills' => ['nullable', 'string', 'max:500'],
            'milestone_id' => ['nullable', 'integer', 'exists:milestones,id'],
            'requires_hardware' => ['nullable', 'boolean'],
        ]);

        if (($data['milestone_id'] ?? null) !== null) {
            abort_unless(
                $project->milestones()->whereKey($data['milestone_id'])->exists(),
                422,
                'Milestone does not belong to this project.'
            );
        }

        UniqueTitle::abortIfDuplicate($project->tasks()->active(), $data['title'], $ignore, 'A task with this title already exists on this project.');

        return [
            'title' => trim($data['title']),
            'description' => $data['description'] ?? null,
            'definition_of_done' => $data['definition_of_done'] ?? null,
            'skills' => collect(explode(',', (string) ($data['skills'] ?? '')))
                ->map(fn (string $skill): string => trim($skill))
                ->filter()
                ->values()
                ->all(),
            'milestone_id' => $data['milestone_id'] ?? null,
            'requires_hardware' => (bool) ($data['requires_hardware'] ?? false),
        ];
    }
}
