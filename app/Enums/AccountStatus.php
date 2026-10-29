<?php

namespace App\Enums;

/**
 * Account access state. Suspension preserves all rows (attribution,
 * history, audit) and only revokes access; there is no deletion path.
 */
enum AccountStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
        };
    }
}
