<?php

namespace App\Enums;

enum MilestoneStatus: string
{
    case Planned = 'PLANNED';
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planned',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
        };
    }
}
