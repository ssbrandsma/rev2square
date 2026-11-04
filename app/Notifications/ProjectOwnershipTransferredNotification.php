<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;

class ProjectOwnershipTransferredNotification extends DatabaseNotification
{
    public function __construct(
        private readonly Project $project,
        private readonly User $newOwner,
        private readonly string $previousOwnerResult,
        private readonly User $actor,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $stayed = $this->previousOwnerResult === 'maintainer';

        return [
            'kind' => 'project_ownership_transferred',
            'title' => "“{$this->project->title}” was transferred to {$this->newOwner->name}",
            'body' => "By {$this->actor->name}. "
                .($stayed
                    ? 'You remain a project maintainer.'
                    : 'Your project access was removed. Your past contributions stay attributed to you.'),
            'url' => route('projects.show', $this->project),
            'project_id' => $this->project->id,
        ];
    }
}
