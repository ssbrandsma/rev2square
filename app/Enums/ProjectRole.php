<?php

namespace App\Enums;

enum ProjectRole: string
{
    case Owner = 'OWNER';
    case Maintainer = 'MAINTAINER';
    case Contributor = 'CONTRIBUTOR';
    case Follower = 'FOLLOWER';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Maintainer => 'Maintainer',
            self::Contributor => 'Contributor',
            self::Follower => 'Follower',
        };
    }
}
