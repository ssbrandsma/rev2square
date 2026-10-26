<?php

namespace App\Enums;

enum PublicationStatus: string
{
    case Draft = 'DRAFT';
    case Published = 'PUBLISHED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
        };
    }
}
