<?php

namespace App\Notifications;

use App\Models\ObservationCorrection;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CorrectionReviewedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly ObservationCorrection $correction,
        private readonly string $decision,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $observation = $this->correction->observation;
        $project = $observation->project;
        $verb = $this->decision === 'accept' ? 'accepted' : 'dismissed';

        return [
            'kind' => 'correction_reviewed',
            'title' => "Your correction on {$project->title} was {$verb}",
            'body' => "About “{$observation->title}”.",
            'url' => route('projects.show', $project).'#observations',
            'project_id' => $project->id,
            'observation_id' => $observation->id,
        ];
    }
}
