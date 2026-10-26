<?php

namespace App\Enums;

enum ProjectActivity: string
{
    case Active = 'ACTIVE';
    case Paused = 'PAUSED';
    case SeekingMaintainer = 'SEEKING_MAINTAINER';
    case Archived = 'ARCHIVED';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::SeekingMaintainer => 'Seeking maintainer',
            self::Archived => 'Archived',
        };
    }
}
