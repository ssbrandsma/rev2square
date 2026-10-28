<?php

namespace App\Enums;

enum ContentOrigin: string
{
    case AI = 'ai';
    case Human = 'human';

    public function label(): string
    {
        return match ($this) {
            self::AI => 'Drafted by AI',
            self::Human => 'Added by maintainer',
        };
    }
}
