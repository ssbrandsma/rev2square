<?php

namespace App\Enums;

enum DocFindingStatus: string
{
    case Observed = 'observed';
    case Hypothesis = 'hypothesis';
    case Verified = 'verified';

    public function label(): string
    {
        return match ($this) {
            self::Observed => 'Observed',
            self::Hypothesis => 'Hypothesis',
            self::Verified => 'Verified',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
