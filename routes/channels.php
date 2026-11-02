<?php

use App\Models\Project;
use App\Models\User;
use App\Services\Presence\MemberInfo;
use App\Services\Realtime\RealtimeEpoch;
use Illuminate\Support\Facades\Broadcast;

// Every channel name embeds the epochs that authorize it. Stale epochs
// are rejected, so clients stranded by a rotation can never rejoin an
// old name, and rejoining the current name re-checks access (P1-2).
Broadcast::channel('chat.lounge.{globalEpoch}', function (User $user, int $globalEpoch): bool {
    return ! $user->isSuspended() && $globalEpoch === RealtimeEpoch::current();
});

Broadcast::channel('chat.project.{projectId}.{projectEpoch}.{globalEpoch}', function (
    User $user,
    int $projectId,
    int $projectEpoch,
    int $globalEpoch,
): bool {
    if ($user->isSuspended()) {
        return false;
    }
    $project = Project::find($projectId);

    return $project !== null
        && $project->channel_epoch === $projectEpoch
        && $globalEpoch === RealtimeEpoch::current()
        && $user->can('view', $project);
});

// Presence is strictly opt-in: the auth callback denies members who have
// not enabled it, and member payloads carry identity only. Names below are
// the normalized forms: clients join presence-{name}, and the broadcaster
// strips the prefix before matching. Presence names stay distinct from the
// private chat.* message channels so message delivery (all signed-in users)
// and roster visibility (opted-in users only) enforce different rules.
Broadcast::channel('online.{globalEpoch}', function (User $user, int $globalEpoch): array|false {
    if ($globalEpoch !== RealtimeEpoch::current()) {
        return false;
    }

    return $user->isSuspended() || ! $user->show_presence ? false : MemberInfo::for($user);
});

Broadcast::channel('roster.lounge.{globalEpoch}', function (User $user, int $globalEpoch): array|false {
    if ($globalEpoch !== RealtimeEpoch::current()) {
        return false;
    }

    return $user->isSuspended() || ! $user->show_presence ? false : MemberInfo::for($user);
});

Broadcast::channel('roster.project.{projectId}.{projectEpoch}.{globalEpoch}', function (
    User $user,
    int $projectId,
    int $projectEpoch,
    int $globalEpoch,
): array|false {
    $project = Project::find($projectId);

    if ($project === null
        || $project->channel_epoch !== $projectEpoch
        || $globalEpoch !== RealtimeEpoch::current()
        || $user->isSuspended()
        || ! $user->can('view', $project)
        || ! $user->show_presence) {
        return false;
    }

    return MemberInfo::for($user, $project);
});
