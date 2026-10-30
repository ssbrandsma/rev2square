<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ProjectOwnershipReceivedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Project $project,
        private readonly User $previousOwner,
        private readonly User $actor,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'project_ownership_received',
            'title' => "You now own “{$this->project->title}”",
            'body' => "Transferred from {$this->previousOwner->name} by {$this->actor->name}. "
                .'Review the team, invitations, and pending offers.',
            'url' => route('projects.show', $this->project),
            'project_id' => $this->project->id,
        ];
    }
}
