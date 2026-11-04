<?php

namespace App\Support;

/**
 * LIKE-pattern helpers. User input interpolated into LIKE must escape
 * the wildcard characters (and the escape itself), or a literal % or _
 * silently widens the match. MySQL/MariaDB treat backslash as the
 * default LIKE escape, so no explicit ESCAPE clause is needed.
 */
class Like
{
    /**
     * Escape a raw needle for use inside a LIKE pattern.
     */
    public static function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Escape a raw needle and wrap it in %...% for a contains search.
     */
    public static function contains(string $value): string
    {
        return '%'.self::escape($value).'%';
    }
}
