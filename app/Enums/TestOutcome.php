<?php

namespace App\Enums;

enum TestOutcome: string
{
    case Worked = 'worked';
    case Partially = 'partially';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Worked => 'Worked',
            self::Partially => 'Partially worked',
            self::Failed => 'Failed',
        };
    }
}
