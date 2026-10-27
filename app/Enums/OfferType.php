<?php

namespace App\Enums;

enum OfferType: string
{
    case OfferHelp = 'offer_help';
    case ClaimTask = 'claim_task';

    public function label(): string
    {
        return match ($this) {
            self::OfferHelp => 'Offer help',
            self::ClaimTask => 'Claim task',
        };
    }
}
