<?php

namespace App\Enums;

enum ReportStatus: string
{
    case Pending = 'pending';
    case Dismissed = 'dismissed';
    case Actioned = 'actioned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending review',
            self::Dismissed => 'Dismissed',
            self::Actioned => 'Actioned',
        };
    }
}
