<?php

namespace App\Services\Revival;

use App\Models\Repository;

/**
 * Builds the repository-context block for the AI investigator: inventory
 * summary, subsystem coverage, partial-file indicators and the most
 * relevant files that were NOT received. Returns an empty string for
 * legacy imports without inventory data.
 */
class EvidenceContextBuilder
{
    /**
     * @param  list<array{path: string}>  $included
     * @param  list<array{path: string}>  $excluded
     */
    public static function forRepository(Repository $repository, array $included, array $excluded): string
    {
        $summary = $repository->last_import_summary ?? [];
        $inventory = $summary['inventory'] ?? null;

        if (! is_array($inventory) || ($inventory['blobs'] ?? 0) === 0) {
            return '';
        }

        $lines = [];
        $lines[] = 'Repository inventory at commit '.substr((string) $repository->last_commit_sha, 0, 12).':';
        $lines[] = sprintf(
            '- Discovered %d files (%d eligible for evidence); downloaded %d, stored %d%s.',
            $inventory['blobs'] ?? 0,
            $inventory['eligible'] ?? 0,
            $inventory['downloaded'] ?? 0,
            $inventory['stored'] ?? 0,
            ($inventory['partial'] ?? 0) > 0 ? ', including '.($inventory['partial']).' partial excerpts' : ''
        );

        if (! empty($inventory['capped'])) {
            $lines[] = '- WARNING: inventory was capped; '.($inventory['over_cap'] ?? 0).' further paths were not recorded.';
        }

        $coverage = $summary['coverage'] ?? [];

        if (is_array($coverage) && $coverage !== []) {
            $parts = [];

            foreach ($coverage as $subsystem => $count) {
                $parts[] = "{$subsystem} ({$count})";
            }

            $lines[] = '- Stored evidence covers these subsystems: '.implode(', ', $parts).'.';
        }

        $partials = array_values(array_filter(
            $included,
            fn ($item) => ($item['is_partial'] ?? false) === true
        ));

        if ($partials !== []) {
            $lines[] = '- PARTIAL excerpts (recorded line ranges only; do not present as whole files):';

            foreach ($partials as $item) {
                $ranges = self::formatRanges($item['line_ranges'] ?? null);
                $total = isset($item['original_lines']) ? " of {$item['original_lines']}" : '';
                $lines[] = "  - {$item['path']}: lines {$ranges}{$total}.";
            }
        }

        $missing = $summary['missing_relevant'] ?? [];

        if (is_array($missing) && $missing !== []) {
            $lines[] = '- Relevant files NOT received (do not imply you inspected them):';

            foreach (array_slice($missing, 0, 10) as $item) {
                $lines[] = "  - {$item['path']} ({$item['reason']}).";
            }
        }

        if ($excluded !== []) {
            $paths = array_slice(array_column($excluded, 'path'), 0, 10);
            $lines[] = '- Stored but excluded from this analysis by the token budget: '.implode(', ', $paths).'.';
        }

        $lines[] = 'Where evidence is partial or missing, state the uncertainty explicitly and name the additional files that would resolve it.';

        return implode("\n", $lines);
    }

    private static function formatRanges(mixed $ranges): string
    {
        if (! is_array($ranges) || $ranges === []) {
            return 'unknown ranges';
        }

        $parts = [];

        foreach ($ranges as $range) {
            if (isset($range['start'], $range['end'])) {
                $parts[] = $range['start'] === $range['end']
                    ? "L{$range['start']}"
                    : "L{$range['start']}-L{$range['end']}";
            }
        }

        return $parts === [] ? 'unknown ranges' : implode(', ', $parts);
    }
}
