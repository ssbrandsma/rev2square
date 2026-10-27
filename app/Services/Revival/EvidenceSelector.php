<?php

namespace App\Services\Revival;

use App\Models\EvidenceSource;
use Illuminate\Support\Collection;

/**
 * Bounded, transparent evidence selection for one analysis run.
 *
 * Rows without relevance scores (legacy imports) keep the original
 * README-first whole-file ordering. Scored rows use relevance order with
 * overview files first; partial excerpts carry their provenance so the
 * investigator never mistakes them for complete files.
 */
class EvidenceSelector
{
    /**
     * @param  Collection<int, EvidenceSource>  $sources
     * @return array{included: list<array{source_id: int, path: string, url: string, content: string, is_partial?: bool, line_ranges?: mixed, original_lines?: ?int, language?: ?string, relevance_score?: ?int}>, excluded: list<array{source_id: int, path: string, reason: string}>, approx_tokens: int}
     */
    public static function select(Collection $sources, int $tokenBudget): array
    {
        $scored = $sources->contains(fn ($row) => $row->relevance_score !== null);

        $ranked = $scored
            ? $sources->sortBy(fn ($row) => [
                self::isOverview($row->path) ? 0 : 1,
                -((int) $row->relevance_score),
                $row->path,
            ])->values()
            : $sources->sortBy(fn ($row) => [self::rank($row->path), $row->path])->values();

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
            $item = [
                'source_id' => $row->id,
                'path' => $row->path,
                'url' => $row->url,
                'content' => (string) $row->content,
            ];

            if ($scored) {
                $item['is_partial'] = (bool) $row->is_partial;
                $item['line_ranges'] = $row->line_ranges;
                $item['original_lines'] = $row->original_lines;
                $item['language'] = $row->language;
                $item['relevance_score'] = $row->relevance_score;
            }

            $included[] = $item;
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

    private static function isOverview(?string $path): bool
    {
        $basename = strtolower(basename((string) $path));

        if (str_starts_with($basename, 'readme') || str_starts_with($basename, 'architecture')) {
            return true;
        }

        return str_contains(strtolower((string) $path), 'research/')
            && (str_ends_with($basename, '.md') || str_ends_with($basename, '.markdown'));
    }
}
