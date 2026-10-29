<?php

use App\Models\Project;
use App\Models\User;
use App\Services\Presence\MemberInfo;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('chat.lounge', fn (User $user): bool => ! $user->isSuspended());

Broadcast::channel('chat.project.{projectId}', function (User $user, int $projectId): bool {
    if ($user->isSuspended()) {
        return false;
    }
    $project = Project::find($projectId);

    return $project !== null && $user->can('view', $project);
});

// Presence is strictly opt-in: the auth callback denies members who have
// not enabled it, and member payloads carry identity only. Names below are
// the normalized forms: clients join presence-{name}, and the broadcaster
// strips the prefix before matching. Presence names stay distinct from the
// private chat.* message channels so message delivery (all signed-in users)
// and roster visibility (opted-in users only) enforce different rules.
Broadcast::channel('online', fn (User $user): array|false => $user->isSuspended() || ! $user->show_presence ? false : MemberInfo::for($user));

Broadcast::channel('roster.lounge', fn (User $user): array|false => $user->isSuspended() || ! $user->show_presence ? false : MemberInfo::for($user));

Broadcast::channel('roster.project.{projectId}', function (User $user, int $projectId): array|false {
    $project = Project::find($projectId);

    if ($project === null || $user->isSuspended() || ! $user->can('view', $project) || ! $user->show_presence) {
        return false;
    }

    return MemberInfo::for($user, $project);
});
