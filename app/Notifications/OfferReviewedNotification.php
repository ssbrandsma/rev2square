<?php

namespace App\Notifications;

use App\Enums\OfferStatus;
use App\Models\ContributionOffer;

class OfferReviewedNotification extends DatabaseNotification
{
    public function __construct(
        private readonly ContributionOffer $offer,
        private readonly OfferStatus $outcome,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $project = $this->offer->project;
        $verb = $this->outcome === OfferStatus::Accepted ? 'accepted' : 'declined';

        return [
            'kind' => 'offer_reviewed',
            'title' => "Your offer on {$project->title} was {$verb}",
            'body' => $this->offer->decision_reason
                ? "Maintainer note: {$this->offer->decision_reason}"
                : ($this->outcome === OfferStatus::Accepted
                    ? 'The maintainers accepted your offer. See the next steps on your application.'
                    : 'The maintainers declined your offer. You can ask for reconsideration.'),
            'url' => route('projects.offers.show', [$project, $this->offer]),
            'project_id' => $project->id,
            'offer_id' => $this->offer->id,
        ];
    }
}
