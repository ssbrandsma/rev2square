<?php

namespace App\Notifications;

use App\Models\DiscussionPost;
use App\Services\Discussion\Markup;

class ProjectUpdateNotification extends DatabaseNotification
{
    public function __construct(
        private readonly DiscussionPost $update,
    ) {}

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
