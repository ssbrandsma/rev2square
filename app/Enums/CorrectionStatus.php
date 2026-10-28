<?php

namespace App\Enums;

enum CorrectionStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending review',
            self::Accepted => 'Accepted',
            self::Dismissed => 'Dismissed',
        };
    }
}
