<?php

namespace App\Services\Realtime;

use App\Events\ChatChannelRotated;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Channel rotation: when access changes, the affected channels get new
 * names and a control event on each OLD name tells live clients to
 * rejoin. Rejoin re-runs channel auth, so clients that lost access are
 * stranded on a dead channel and receive nothing further. No client
 * cooperation is needed for the security property (P1-2).
 *
 * Bumps are locked reads, so two callers holding stale models still
 * advance distinct successive epochs. Bumps join the caller's ambient
 * transaction when there is one; announcements always run after commit.
 */
class ChannelRotation
{
    /**
     * Atomically advance one project's epoch. Returns the committed (or
     * ambient-transaction pending) transition; the caller announces it
     * after commit via announceProjectRotation().
     *
     * @return array{old: int, new: int}
     */
    public static function bumpProjectEpoch(Project $project): array
    {
        $bumped = DB::transaction(function () use ($project): array {
            $fresh = Project::whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            $old = (int) $fresh->channel_epoch;
            $fresh->forceFill(['channel_epoch' => $old + 1])->save();

            return ['old' => $old, 'new' => $old + 1];
        });

        // Sync the caller's (possibly stale) instance to the bump it caused.
        $project->forceFill(['channel_epoch' => $bumped['new']]);

        return $bumped;
    }

    public static function announceProjectRotation(int $projectId, int $old, int $new): void
    {
        $global = RealtimeEpoch::current();

        broadcast(new ChatChannelRotated("chat.project.{$projectId}.{$old}.{$global}", "chat.project.{$projectId}.{$new}.{$global}", false));
        broadcast(new ChatChannelRotated("roster.project.{$projectId}.{$old}.{$global}", "roster.project.{$projectId}.{$new}.{$global}", true));
    }

    /** @param Collection<int, Project> $projects */
    public static function announceGlobal(int $old, int $new, Collection $projects): void
    {
        foreach (ChannelNames::describeGlobalRotation($old, $new, $projects) as $pair) {
            broadcast(new ChatChannelRotated($pair['old'], $pair['new'], $pair['presence']));
        }
    }
}
