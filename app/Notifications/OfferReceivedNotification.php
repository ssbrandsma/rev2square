<?php

namespace App\Notifications;

use App\Models\ContributionOffer;

class OfferReceivedNotification extends DatabaseNotification
{
    public function __construct(
        private readonly ContributionOffer $offer,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $project = $this->offer->project;

        return [
            'kind' => 'offer_received',
            'title' => "New {$this->offer->type->label()} on {$project->title}",
            'body' => "{$this->offer->user->name} {$this->offer->type->label()} — {$this->offer->message}",
            'url' => route('projects.offers.show', [$project, $this->offer]),
            'project_id' => $project->id,
            'offer_id' => $this->offer->id,
        ];
    }
}
