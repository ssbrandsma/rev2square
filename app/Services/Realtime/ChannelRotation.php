<?php

namespace App\Services\Realtime;

use App\Events\ChatChannelRotated;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Channel rotation: when access changes, the affected channels get new
 * names and a control event on each OLD name tells live clients to
 * rejoin. Rejoin re-runs channel auth, so clients that lost access are
 * stranded on a dead channel and receive nothing further. No client
 * cooperation is needed for the security property (P1-2).
 */
class ChannelRotation
{
    /**
     * Rotate one project's chat + roster channels after a project-level
     * access change (visibility, membership, ownership). Broadcasts
     * immediately after the bump commits; callers inside their own
     * transaction must call this after it commits.
     */
    public static function rotateProject(Project $project): void
    {
        $global = RealtimeEpoch::current();

        $oldChat = ChannelNames::projectChat($project, $global);
        $oldRoster = ChannelNames::projectRoster($project, $global);

        $project->forceFill(['channel_epoch' => $project->channel_epoch + 1])->save();
        $project->refresh();

        $newChat = ChannelNames::projectChat($project, $global);
        $newRoster = ChannelNames::projectRoster($project, $global);

        broadcast(new ChatChannelRotated($oldChat, $newChat, false));
        broadcast(new ChatChannelRotated($oldRoster, $newRoster, true));
    }

    /** @param Collection<int, Project> $projects */
    public static function announceGlobal(int $old, int $new, Collection $projects): void
    {
        foreach (ChannelNames::describeGlobalRotation($old, $new, $projects) as $pair) {
            broadcast(new ChatChannelRotated($pair['old'], $pair['new'], $pair['presence']));
        }
    }
}
