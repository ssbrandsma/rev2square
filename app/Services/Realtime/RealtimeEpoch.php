<?php

namespace App\Services\Realtime;

use App\Models\AppSetting;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Global realtime epoch, versioning the lounge/online channels. Bumped
 * when site-wide access changes (currently: account suspension) so
 * sockets authorized earlier stop receiving new payloads (P1-2).
 *
 * Persisted in AppSetting (outside the admin allowlist, so the settings
 * UI never touches it). Memoized per request; rotate() resets the memo.
 */
class RealtimeEpoch
{
    public const SETTING_KEY = 'realtime_epoch';

    private static ?int $memo = null;

    public static function current(): int
    {
        if (self::$memo === null) {
            $row = AppSetting::firstOrCreate(
                ['key' => self::SETTING_KEY],
                ['value' => '0', 'is_secret' => false, 'updated_by' => null],
            );

            self::$memo = max(0, (int) $row->value);
        }

        return self::$memo;
    }

    public static function forgetMemo(): void
    {
        self::$memo = null;
    }

    /**
     * Bump the global epoch and tell every realtime channel to rejoin.
     * Project channels embed the global epoch too, so suspension also
     * strands project sockets — the fan-out covers them.
     *
     * @return array{old: int, new: int}
     */
    /**
     * Atomically advance the global epoch. Joins the caller's ambient
     * transaction when there is one; the caller announces the returned
     * transition after commit via announce().
     *
     * @return array{old: int, new: int}
     */
    public static function bump(): array
    {
        return DB::transaction(function (): array {
            $row = AppSetting::where('key', self::SETTING_KEY)->lockForUpdate()->first();

            $old = $row === null ? 0 : max(0, (int) $row->value);
            $new = $old + 1;

            AppSetting::updateOrCreate(
                ['key' => self::SETTING_KEY],
                ['value' => (string) $new, 'is_secret' => false],
            );

            self::$memo = $new;

            return ['old' => $old, 'new' => $new];
        });
    }

    /**
     * Announce a committed global transition on every old channel name.
     * Project rows are read fresh so the fan-out matches committed state.
     */
    public static function announce(int $old, int $new): void
    {
        $projects = Project::query()->select(['id', 'channel_epoch'])->get();
        ChannelRotation::announceGlobal($old, $new, $projects);
    }
}
