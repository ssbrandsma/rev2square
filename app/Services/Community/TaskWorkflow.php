<?php

namespace App\Services\Community;

use App\Enums\TaskStatus;
use App\Models\AuditEvent;
use App\Models\ContributorTask;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskStatusNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Enforced contributor-task state machine (A1/F02).
 *
 * Claim -> approve/assign -> start -> submit for verification -> verify
 * (or request changes). Every transition is permission-checked and audited.
 */
class TaskWorkflow
{
    /**
     * Allowed transitions: from => [to => ['by' => roles, 'note' => note required]].
     * Roles: 'maintainer' (project maintainer), 'assignee' (assigned user).
     *
     * @var array<string, array<string, array{by: list<string>, note: bool}>>
     */
    private const TRANSITIONS = [
        'OPEN' => [
            'CLAIMED' => ['by' => ['maintainer'], 'note' => false],
            'RETIRED' => ['by' => ['maintainer'], 'note' => false],
        ],
        'CLAIMED' => [
            'IN_PROGRESS' => ['by' => ['maintainer', 'assignee'], 'note' => false],
            'OPEN' => ['by' => ['maintainer'], 'note' => false],
            'RETIRED' => ['by' => ['maintainer'], 'note' => false],
        ],
        'IN_PROGRESS' => [
            'BLOCKED' => ['by' => ['maintainer', 'assignee'], 'note' => false],
            'READY_FOR_VERIFICATION' => ['by' => ['maintainer', 'assignee'], 'note' => false],
            'RETIRED' => ['by' => ['maintainer'], 'note' => false],
        ],
        'BLOCKED' => [
            'IN_PROGRESS' => ['by' => ['maintainer', 'assignee'], 'note' => false],
            'RETIRED' => ['by' => ['maintainer'], 'note' => false],
        ],
        'READY_FOR_VERIFICATION' => [
            'VERIFIED_COMPLETE' => ['by' => ['maintainer'], 'note' => false],
            'IN_PROGRESS' => ['by' => ['maintainer'], 'note' => true],
            'RETIRED' => ['by' => ['maintainer'], 'note' => false],
        ],
        'COMPLETED' => [
            'VERIFIED_COMPLETE' => ['by' => ['maintainer'], 'note' => false],
            'IN_PROGRESS' => ['by' => ['maintainer', 'assignee'], 'note' => false],
            'RETIRED' => ['by' => ['maintainer'], 'note' => false],
        ],
        'VERIFIED_COMPLETE' => [
            'IN_PROGRESS' => ['by' => ['maintainer'], 'note' => true],
            'RETIRED' => ['by' => ['maintainer'], 'note' => false],
        ],
        'RETIRED' => [
            'OPEN' => ['by' => ['maintainer'], 'note' => false],
        ],
    ];

    /**
     * @return array<string, array{by: list<string>, note: bool}>
     */
    public static function allowedTransitions(ContributorTask $task, User $actor): array
    {
        $rows = self::TRANSITIONS[$task->status->value] ?? [];
        $role = self::roleOf($task, $actor);

        return array_filter(
            $rows,
            fn (array $rule): bool => in_array($role, $rule['by'], true),
        );
    }

    public static function transition(ContributorTask $task, TaskStatus $to, User $actor, ?string $note = null): ContributorTask
    {
        $rule = (self::TRANSITIONS[$task->status->value] ?? [])[$to->value] ?? null;

        if ($rule === null) {
            throw ValidationException::withMessages([
                'status' => "Cannot move task from {$task->status->label()} to {$to->label()}.",
            ]);
        }

        if (! in_array(self::roleOf($task, $actor), $rule['by'], true)) {
            throw new AuthorizationException('You are not allowed to make this task update.');
        }

        if ($rule['note'] && trim((string) $note) === '') {
            throw ValidationException::withMessages([
                'note' => 'Add a note explaining why the task goes back to in progress.',
            ]);
        }

        if ($to === TaskStatus::Claimed && $task->assigned_to_user_id === null) {
            throw ValidationException::withMessages([
                'status' => 'Assign the task to someone before marking it claimed.',
            ]);
        }

        $from = $task->status;
        $task->status = $to;

        if ($to === TaskStatus::Open) {
            $task->assigned_to_user_id = null;
        }

        $task->save();

        $cleanNote = $note !== null && trim($note) !== '' ? trim($note) : null;

        AuditEvent::record('task.status', $actor, $task->project, $task, array_filter([
            'from' => $from->value,
            'to' => $to->value,
            'note' => $cleanNote,
        ]));

        $task->loadMissing(['assignee', 'project.owner']);

        // The assignee hears about maintainer-side moves; maintainers hear
        // when work is submitted for verification. Nobody is told twice.
        if ($task->assignee !== null && $task->assignee->id !== $actor->id) {
            $task->assignee->notify(new TaskStatusNotification($task, $from, $to, $cleanNote));
        }

        if ($to === TaskStatus::ReadyForVerification && ! $task->project->managedBy($actor)) {
            foreach ($task->project->managers()->where('id', '!=', $actor->id) as $manager) {
                $manager->notify(new TaskStatusNotification($task, $from, $to, $cleanNote));
            }
        }

        return $task;
    }

    public static function assign(ContributorTask $task, ?User $assignee, User $actor): ContributorTask
    {
        if (! $task->project->managedBy($actor)) {
            throw new AuthorizationException('Only maintainers can assign tasks.');
        }

        if (in_array($task->status, [TaskStatus::VerifiedComplete, TaskStatus::Retired], true)) {
            throw ValidationException::withMessages([
                'assignee' => "Cannot assign a {$task->status->label()} task.",
            ]);
        }

        $task->assigned_to_user_id = $assignee?->id;

        if ($assignee !== null && $task->status === TaskStatus::Open) {
            $task->status = TaskStatus::Claimed;
        } elseif ($assignee === null && $task->status === TaskStatus::Claimed) {
            $task->status = TaskStatus::Open;
        }

        $task->save();

        AuditEvent::record(
            $assignee !== null ? 'task.assigned' : 'task.unassigned',
            $actor,
            $task->project,
            $task,
            $assignee !== null ? ['assignee_id' => $assignee->id, 'assignee' => $assignee->name] : []
        );

        if ($assignee !== null && $assignee->id !== $actor->id) {
            $assignee->notify(new TaskAssignedNotification($task));
        }

        return $task;
    }

    private static function roleOf(ContributorTask $task, User $actor): string
    {
        if ($task->project->managedBy($actor)) {
            return 'maintainer';
        }

        if ($task->assigned_to_user_id !== null && $task->assigned_to_user_id === $actor->id) {
            return 'assignee';
        }

        return 'other';
    }
}
