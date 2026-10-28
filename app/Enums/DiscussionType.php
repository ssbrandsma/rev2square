<?php

namespace App\Enums;

enum DiscussionType: string
{
    case Post = 'post';
    case Announcement = 'announcement';
    case Update = 'update';

    public function label(): string
    {
        return match ($this) {
            self::Post => 'Discussion',
            self::Announcement => 'Announcement',
            self::Update => 'Project update',
        };
    }
}
