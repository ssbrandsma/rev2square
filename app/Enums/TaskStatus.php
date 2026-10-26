<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Open = 'OPEN';
    case Claimed = 'CLAIMED';
    case Completed = 'COMPLETED';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Claimed => 'Claimed',
            self::Completed => 'Completed',
        };
    }
}
