<?php

namespace App\Services\Discussion;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Matches @-mentions in discussion text against project participants.
 * Display names are matched case-insensitively as full name, first word,
 * or spaceless name; handles arrive with the profile prompt.
 */
class Mentions
{
    /**
     * @param  Collection<int, User>  $candidates
     * @return Collection<int, User>
     */
    public static function parse(string $body, Collection $candidates): Collection
    {
        preg_match_all('/@([\p{L}\p{N}_][\p{L}\p{N}_.-]*(?: [\p{L}\p{N}_][\p{L}\p{N}_.~-]*)*)/u', $body, $matches);

        $tokens = collect($matches[1] ?? [])
            ->map(fn (string $token): string => mb_strtolower(trim($token)))
            ->filter()
            ->unique();

        if ($tokens->isEmpty()) {
            return collect();
        }

        return $candidates
            ->filter(function (User $user) use ($tokens): bool {
                $name = mb_strtolower(trim($user->name));
                $first = mb_strtolower(strtok(trim($user->name), " \t") ?: '');
                $spaceless = str_replace([' ', "\t"], '', $name);

                return $tokens->contains(fn (string $token): bool => $token === $name
                    || $token === $first
                    || $token === $spaceless
                    || str_replace(['.', '_', '-'], '', $token) === str_replace(['.', '_', '-'], '', $spaceless));
            })
            ->unique('id')
            ->values();
    }
}
