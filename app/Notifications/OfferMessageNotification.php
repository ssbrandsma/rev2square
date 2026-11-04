<?php

namespace App\Notifications;

use App\Models\OfferMessage;

class OfferMessageNotification extends DatabaseNotification
{
    public function __construct(
        private readonly OfferMessage $message,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $offer = $this->message->offer;
        $project = $offer->project;

        return [
            'kind' => 'offer_message',
            'title' => "New message on your {$offer->type->label()} for {$project->title}",
            'body' => "{$this->message->author->name}: {$this->message->body}",
            'url' => route('projects.offers.show', [$project, $offer]),
            'project_id' => $project->id,
            'offer_id' => $offer->id,
        ];
    }
}
