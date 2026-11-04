<?php

namespace App\Notifications;

use App\Models\ContributorTask;

class TaskAssignedNotification extends DatabaseNotification
{
    public function __construct(
        private readonly ContributorTask $task,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $project = $this->task->project;

        return [
            'kind' => 'task_assigned',
            'title' => "You are assigned to “{$this->task->title}”",
            'body' => "On {$project->title}. Open the task to start work or hand it back.",
            'url' => route('projects.tasks.show', [$project, $this->task]),
            'project_id' => $project->id,
            'task_id' => $this->task->id,
        ];
    }
}
