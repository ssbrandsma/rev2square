<?php

namespace App\Enums;

/**
 * Application-wide role, orthogonal to per-project Owner/Maintainer/
 * Contributor membership. A project maintainer gains no global powers.
 */
enum GlobalRole: string
{
    case User = 'user';
    case Moderator = 'moderator';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::User => 'User',
            self::Moderator => 'Moderator',
            self::Admin => 'Admin',
        };
    }

    public function isPrivileged(): bool
    {
        return $this !== self::User;
    }
}
