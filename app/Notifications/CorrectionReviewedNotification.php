<?php

namespace App\Notifications;

use App\Models\ObservationCorrection;

class CorrectionReviewedNotification extends DatabaseNotification
{
    public function __construct(
        private readonly ObservationCorrection $correction,
        private readonly string $decision,
    ) {}

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
