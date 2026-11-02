<?php

namespace App\Services\Realtime;

use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Single source of truth for realtime channel names. Every name embeds
 * the epochs that authorize it, so a rotated channel is simply a name no
 * stale subscriber joined (P1-2).
 *
 * Canonical (unprefixed) names; the broadcaster adds private-/presence-.
 */
class ChannelNames
{
    public static function lounge(int $globalEpoch): string
    {
        return "chat.lounge.{$globalEpoch}";
    }

    public static function online(int $globalEpoch): string
    {
        return "online.{$globalEpoch}";
    }

    public static function loungeRoster(int $globalEpoch): string
    {
        return "roster.lounge.{$globalEpoch}";
    }

    public static function projectChat(Project $project, int $globalEpoch): string
    {
        return "chat.project.{$project->id}.{$project->channel_epoch}.{$globalEpoch}";
    }

    public static function projectRoster(Project $project, int $globalEpoch): string
    {
        return "roster.project.{$project->id}.{$project->channel_epoch}.{$globalEpoch}";
    }

    /** @param Collection<int, Project> $projects */
    public static function describeGlobalRotation(int $old, int $new, Collection $projects): array
    {
        $pairs = [
            ['old' => self::lounge($old), 'new' => self::lounge($new), 'presence' => false],
            ['old' => self::online($old), 'new' => self::online($new), 'presence' => true],
            ['old' => self::loungeRoster($old), 'new' => self::loungeRoster($new), 'presence' => true],
        ];

        foreach ($projects as $project) {
            $pairs[] = [
                'old' => "chat.project.{$project->id}.{$project->channel_epoch}.{$old}",
                'new' => "chat.project.{$project->id}.{$project->channel_epoch}.{$new}",
                'presence' => false,
            ];
            $pairs[] = [
                'old' => "roster.project.{$project->id}.{$project->channel_epoch}.{$old}",
                'new' => "roster.project.{$project->id}.{$project->channel_epoch}.{$new}",
                'presence' => true,
            ];
        }

        return $pairs;
    }
}
