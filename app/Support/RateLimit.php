<?php

namespace App\Support;

/**
 * Shared rate-limit retry delay. A known reset timestamp yields the
 * remaining wait plus a small buffer, clamped to a sane window; an
 * unknown reset falls back to five minutes.
 */
class RateLimit
{
    public static function delay(?int $resetsAt): int
    {
        if ($resetsAt === null) {
            return 300;
        }

        return min(max($resetsAt - time() + 5, 60), 3600);
    }
}
