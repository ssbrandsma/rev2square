<?php

namespace App\Notifications;

use App\Models\TeamInvitation;

class TeamInvitationNotification extends DatabaseNotification
{
    public function __construct(
        private readonly TeamInvitation $invitation,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $project = $this->invitation->project;

        return [
            'kind' => 'team_invitation',
            'title' => "You are invited to maintain {$project->title}",
            'body' => "Role: {$this->invitation->role->label()}. The invitation expires {$this->invitation->expires_at->toDateString()}. Nothing changes until you accept.",
            'url' => route('projects.team.index', $project),
            'project_id' => $project->id,
            'invitation_id' => $this->invitation->id,
        ];
    }
}
