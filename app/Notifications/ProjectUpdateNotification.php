<?php

namespace App\Notifications;

use App\Models\DiscussionPost;
use App\Services\Discussion\Markup;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ProjectUpdateNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly DiscussionPost $update,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $project = $this->update->project;

        return [
            'kind' => 'project_update',
            'title' => "New update on {$project->title}: {$this->update->title}",
            'body' => Markup::excerpt($this->update->body),
            'url' => route('projects.updates.show', [$project, $this->update]),
            'project_id' => $project->id,
            'update_id' => $this->update->id,
        ];
    }
}
