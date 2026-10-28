<?php

namespace App\Services\Profile;

use App\Enums\ProjectRole;
use App\Enums\PublicationStatus;
use App\Enums\TaskStatus;
use App\Models\AuditEvent;
use App\Models\ContributorTask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Derives Started / Maintaining / Working on / Contributions from real
 * relationships. Public sections only ever see published projects, for
 * every viewer including the member themselves; drafts live in the
 * private workspace.
 */
class MemberProfile
{
    public const SECTION_LIMIT = 12;

    /**
     * @return array{started: array, maintaining: array, working_on: array, contributions: array}
     */
    public function publicSections(User $member): array
    {
        $published = fn ($query) => $query->where('publication_status', PublicationStatus::Published);

        $started = $member->createdProjects()
            ->tap($published)
            ->with('devices')
            ->orderByDesc('id')
            ->get();

        $maintaining = Project::query()
            ->tap($published)
            ->where(fn ($query) => $query
                ->where('owner_id', $member->id)
                ->orWhereHas('members', fn ($query) => $query
                    ->where('user_id', $member->id)
                    ->where('status', 'active')
                    ->whereIn('role', [ProjectRole::Owner, ProjectRole::Maintainer])))
            ->with('devices')
            ->orderByDesc('id')
            ->get();

        $maintainingIds = $maintaining->pluck('id')->all();

        $workingOn = Project::query()
            ->tap($published)
            ->whereNotIn('id', $maintainingIds)
            ->where(fn ($query) => $query
                ->whereHas('members', fn ($query) => $query
                    ->where('user_id', $member->id)
                    ->where('status', 'active'))
                ->orWhereHas('tasks', fn ($query) => $query
                    ->where('assigned_to_user_id', $member->id)
                    ->where('status', '!=', TaskStatus::Retired)))
            ->with('devices')
            ->orderByDesc('id')
            ->get();

        return [
            'started' => $this->projectCards($started, $member),
            'maintaining' => $this->projectCards($maintaining, $member),
            'working_on' => $this->projectCards($workingOn, $member),
            'contributions' => $this->contributions($member, true),
        ];
    }

    /**
     * @return array{started: array, working_on: array, assigned_tasks: Collection, contributions: array, hidden_saved: int}
     */
    public function workspaceSections(User $member): array
    {
        $started = $member->createdProjects()->with('devices')->orderByDesc('id')->get();

        $workingOn = Project::query()
            ->where(fn ($query) => $query
                ->where('owner_id', $member->id)
                ->orWhereHas('members', fn ($query) => $query
                    ->where('user_id', $member->id)
                    ->where('status', 'active'))
                ->orWhereHas('tasks', fn ($query) => $query
                    ->where('assigned_to_user_id', $member->id)
                    ->where('status', '!=', TaskStatus::Retired)))
            ->with('devices')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Project $project): bool => $member->can('view', $project));

        $assignedTasks = ContributorTask::query()
            ->where('assigned_to_user_id', $member->id)
            ->where('status', '!=', TaskStatus::Retired)
            ->with('project')
            ->orderBy('id')
            ->get()
            ->filter(fn (ContributorTask $task): bool => $member->can('view', $task->project));

        $contributions = $this->contributions($member, false);
        $visible = $contributions['all']
            ->filter(fn (array $item): bool => $member->can('view', $item['project']))
            ->values();
        $contributions['hidden'] = $contributions['total'] - $visible->count();
        $contributions['items'] = $visible->take(self::SECTION_LIMIT);
        $contributions['total'] = $visible->count();
        unset($contributions['all']);

        $hiddenSaved = $member->savedProjects()->with('project')->get()
            ->reject(fn ($save): bool => $save->project !== null && $member->can('view', $save->project))
            ->count();

        return [
            'started' => $this->projectCards($started, $member),
            'working_on' => $this->projectCards($workingOn, $member),
            'assigned_tasks' => $assignedTasks,
            'contributions' => $contributions,
            'hidden_saved' => $hiddenSaved,
        ];
    }

    /**
     * @return array{items: Collection, total: int}
     */
    private function projectCards(Collection $projects, User $member): array
    {
        $items = $projects->take(self::SECTION_LIMIT)->map(fn (Project $project): array => [
            'project' => $project,
            'role' => $this->roleLabel($project, $member),
            'is_creator' => (int) $project->created_by_user_id === $member->id,
            'assigned_task' => $project->tasks()
                ->where('assigned_to_user_id', $member->id)
                ->where('status', '!=', TaskStatus::Retired)
                ->orderBy('id')
                ->first(),
        ]);

        return ['items' => $items, 'total' => $projects->count()];
    }

    private function roleLabel(Project $project, User $member): string
    {
        if ((int) $project->owner_id === $member->id) {
            return 'Owner';
        }

        $membership = $project->members
            ?? $project->members()->where('user_id', $member->id)->get();

        $role = $membership->where('user_id', $member->id)->firstWhere('status', 'active')?->role;

        if ($role instanceof ProjectRole && $role !== ProjectRole::Follower) {
            return $role === ProjectRole::Contributor ? 'Contributor' : 'Maintainer';
        }

        return 'Task assignee';
    }

    /**
     * Attributable work, each logical record once: authored observations
     * and test reports plus assigned and completed tasks. Removal from a
     * team never deletes authorship; reopening a task updates its status
     * in place.
     *
     * @return array{items: Collection, total: int}
     */
    private function contributions(User $member, bool $publicOnly): array
    {
        $items = collect();

        $observations = $member->observations()->with('project')->orderByDesc('id')->get();
        $reports = $member->testReports()->with('project')->orderByDesc('id')->get();

        foreach ($observations as $observation) {
            if ($this->includeProject($observation->project, $publicOnly)) {
                $items->push([
                    'kind' => 'Observation',
                    'title' => $observation->title,
                    'status' => $observation->isVerified() ? 'Reviewed' : 'Pending review',
                    'url' => route('projects.show', $observation->project).'#observations',
                    'project' => $observation->project,
                    'at' => $observation->created_at,
                ]);
            }
        }

        foreach ($reports as $report) {
            if ($this->includeProject($report->project, $publicOnly)) {
                $items->push([
                    'kind' => 'Hardware test',
                    'title' => $report->title,
                    'status' => $report->outcome->label().($report->isConfirmed() ? ' · Confirmed' : ''),
                    'url' => route('projects.test-reports.show', [$report->project, $report]),
                    'project' => $report->project,
                    'at' => $report->created_at,
                ]);
            }
        }

        $tasks = ContributorTask::query()
            ->where('assigned_to_user_id', $member->id)
            ->where('status', '!=', TaskStatus::Retired)
            ->with('project')
            ->orderByDesc('id')
            ->get();

        // Completed-while-assigned credit survives later unassignment:
        // the task.assigned audit names the worker, the current status
        // tells the truth about the work.
        $creditedIds = AuditEvent::query()
            ->where('event', 'task.assigned')
            ->whereJsonContains('metadata->assignee_id', $member->id)
            ->pluck('subject_id')
            ->filter()
            ->unique()
            ->all();

        $credited = ContributorTask::query()
            ->whereIn('id', $creditedIds ?: [0])
            ->where('assigned_to_user_id', '!=', $member->id)
            ->whereIn('status', [TaskStatus::Completed, TaskStatus::VerifiedComplete])
            ->with('project')
            ->orderByDesc('id')
            ->get();

        foreach ($tasks as $task) {
            if (! $this->includeProject($task->project, $publicOnly)) {
                continue;
            }

            $items->push([
                'kind' => $task->status === TaskStatus::VerifiedComplete ? 'Task completed & reviewed' : 'Task',
                'title' => $task->title,
                'status' => $task->status->label(),
                'url' => route('projects.tasks.show', [$task->project, $task]),
                'project' => $task->project,
                'at' => $task->updated_at,
            ]);
        }

        // Earlier hands on finished work: credited, but never presented
        // as the member who carried it over the line.
        foreach ($credited as $task) {
            if (! $this->includeProject($task->project, $publicOnly)) {
                continue;
            }

            $items->push([
                'kind' => 'Earlier task work',
                'title' => $task->title,
                'status' => $task->status->label(),
                'url' => route('projects.tasks.show', [$task->project, $task]),
                'project' => $task->project,
                'at' => $task->updated_at,
            ]);
        }

        $items = $items->sortByDesc('at')->values();

        return ['items' => $items->take(self::SECTION_LIMIT), 'total' => $items->count(), 'all' => $items];
    }

    private function includeProject(?Project $project, bool $publicOnly): bool
    {
        if ($project === null) {
            return false;
        }

        return ! $publicOnly || $project->publication_status === PublicationStatus::Published;
    }
}
