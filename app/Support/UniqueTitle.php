<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Case-insensitive sibling-title uniqueness guard for project children
 * (tasks, milestones). Aborts with 422 when another row in scope
 * already carries the title.
 */
class UniqueTitle
{
    public static function abortIfDuplicate(Builder|Relation $siblings, string $title, ?Model $ignore, string $message): void
    {
        $duplicate = $siblings
            ->whereRaw('LOWER(title) = ?', [mb_strtolower(trim($title))])
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->exists();

        if ($duplicate) {
            abort(422, $message);
        }
    }
}
