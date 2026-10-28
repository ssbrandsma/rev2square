<?php

namespace App\Notifications;

use App\Enums\TaskStatus;
use App\Models\ContributorTask;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TaskStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly ContributorTask $task,
        private readonly TaskStatus $from,
        private readonly TaskStatus $to,
        private readonly ?string $note = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $project = $this->task->project;

        $body = "“{$this->task->title}” moved from {$this->from->label()} to {$this->to->label()} on {$project->title}.";
        if ($this->note !== null && trim($this->note) !== '') {
            $body .= " Note: {$this->note}";
        }

        return [
            'kind' => 'task_status',
            'title' => "Task update: {$this->task->title}",
            'body' => $body,
            'url' => route('projects.tasks.show', [$project, $this->task]),
            'project_id' => $project->id,
            'task_id' => $this->task->id,
        ];
    }
}
