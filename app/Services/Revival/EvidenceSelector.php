<?php

namespace App\Services\Revival;

use App\Models\EvidenceSource;
use Illuminate\Support\Collection;

/**
 * Bounded, transparent evidence selection for one analysis run.
 * Whole files only; every exclusion is recorded with a reason.
 */
class EvidenceSelector
{
    /**
     * @param  Collection<int, EvidenceSource>  $sources
     * @return array{included: list<array{source_id: int, path: string, url: string, content: string}>, excluded: list<array{source_id: int, path: string, reason: string}>, approx_tokens: int}
     */
    public static function select(Collection $sources, int $tokenBudget): array
    {
        $ranked = $sources->sortBy(fn ($row) => [self::rank($row->path), $row->path])->values();

        $included = [];
        $excluded = [];
        $tokens = 0;

        foreach ($ranked as $row) {
            $cost = (int) ceil(strlen((string) $row->content) / 4);

            if ($tokens + $cost > $tokenBudget) {
                $excluded[] = [
                    'source_id' => $row->id,
                    'path' => $row->path,
                    'reason' => 'over analysis token budget',
                ];

                continue;
            }

            $tokens += $cost;
            $included[] = [
                'source_id' => $row->id,
                'path' => $row->path,
                'url' => $row->url,
                'content' => (string) $row->content,
            ];
        }

        return ['included' => $included, 'excluded' => $excluded, 'approx_tokens' => $tokens];
    }

    private static function rank(?string $path): int
    {
        $basename = strtolower(basename((string) $path));

        if (str_starts_with($basename, 'readme')) {
            return 0;
        }

        if (str_contains((string) $path, 'research/') && str_ends_with($basename, '.md')) {
            return 1;
        }

        if (str_ends_with($basename, '.md') || str_ends_with($basename, '.markdown')) {
            return 2;
        }

        return 3;
    }
}
