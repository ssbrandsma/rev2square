<?php

namespace App\Services\Presence;

use App\Models\Project;
use App\Models\User;

class MemberInfo
{
    /**
     * Identity-only presence payload. No email, no room detail, no history.
     *
     * @return array<string, mixed>
     */
    public static function for(User $user, ?Project $project = null): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'initials' => $user->initials(),
            'avatar_url' => $user->avatarUrl(),
            'profile_url' => $user->profileUrl(),
            'is_maintainer' => $project !== null && $project->managedBy($user),
        ];
    }
}
