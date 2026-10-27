<?php

namespace App\Services\Revival;

/**
 * Budget-aware, coverage-aware evidence planning over scored inventory.
 *
 * Pure: the same scored inventory and configuration always produce the same
 * fetch queue. Overview files (README / architecture) are ordered first so
 * repository understanding is never crowded out by implementation files,
 * and no single subsystem may dominate the selection while other
 * subsystems have uncovered eligible files.
 */
class EvidencePlanner
{
    /**
     * @param  list<array{path: string, size_bytes: int, score: int, subsystem: string, overview: bool}>  $scored
     * @param  array<string, mixed>  $config  revival.github budget keys.
     * @return array{queue: list<array{path: string, size_bytes: int, score: int, subsystem: string, overview: bool, want_bytes: int}>, skipped: list<array{path: string, reason: string}>}
     */
    public static function plan(array $scored, array $config): array
    {
        $maxFiles = max(1, (int) ($config['max_files'] ?? 50));
        $maxDownload = (int) ($config['max_download_bytes'] ?? 2000000);
        $maxEvidence = (int) ($config['max_evidence_bytes'] ?? $config['max_total_bytes'] ?? 500000);
        $maxExtraction = (int) ($config['max_extraction_bytes'] ?? $config['max_file_bytes'] ?? 100000);
        $tokenBudget = (int) ($config['token_budget'] ?? 100000);

        // Canonical order: overview first, then score, then path. Input order
        // (API traversal, prior sorts) never influences the outcome.
        usort($scored, fn ($a, $b) => [
            $a['overview'] ? 0 : 1, -$a['score'], $a['path'],
        ] <=> [
            $b['overview'] ? 0 : 1, -$b['score'], $b['path'],
        ]);

        $ordered = self::ensureCoverage($scored, $maxFiles);

        $queue = [];
        $skipped = [];
        $downloadBytes = 0;
        $evidenceBytes = 0;

        foreach ($ordered as $entry) {
            $wantBytes = min((int) $entry['size_bytes'], $maxExtraction);

            if (count($queue) >= $maxFiles) {
                $skipped[] = ['path' => $entry['path'], 'reason' => 'low relevance'];

                continue;
            }

            if ($downloadBytes + (int) $entry['size_bytes'] > $maxDownload
                || $evidenceBytes + $wantBytes > $maxEvidence
                || (int) ceil(($evidenceBytes + $wantBytes) / 4) > $tokenBudget) {
                $skipped[] = ['path' => $entry['path'], 'reason' => 'evidence budget exhausted'];

                continue;
            }

            $downloadBytes += (int) $entry['size_bytes'];
            $evidenceBytes += $wantBytes;
            $queue[] = $entry + ['want_bytes' => $wantBytes];
        }

        return ['queue' => $queue, 'skipped' => $skipped];
    }

    /**
     * Reorder so every subsystem with eligible files is represented before
     * any subsystem takes a dominant share. Deferred entries keep their
     * relative order and are appended after the coverage pass.
     *
     * @param  list<array{path: string, subsystem: string}>  $ordered
     * @return list<array{path: string, subsystem: string}>
     */
    private static function ensureCoverage(array $ordered, int $maxFiles): array
    {
        $remaining = [];

        foreach ($ordered as $entry) {
            $remaining[$entry['subsystem']] = ($remaining[$entry['subsystem']] ?? 0) + 1;
        }

        $dominanceCap = max(1, intdiv($maxFiles, 2));
        $accepted = [];
        $deferred = [];
        $picks = [];

        foreach ($ordered as $entry) {
            $subsystem = $entry['subsystem'];
            $remaining[$subsystem]--;

            $dominant = ($picks[$subsystem] ?? 0) >= $dominanceCap;
            $uncoveredExists = false;

            if ($dominant) {
                foreach ($remaining as $other => $left) {
                    if ($other !== $subsystem && $left > 0 && ! isset($picks[$other])) {
                        $uncoveredExists = true;

                        break;
                    }
                }
            }

            if ($dominant && $uncoveredExists) {
                $deferred[] = $entry;

                continue;
            }

            $picks[$subsystem] = ($picks[$subsystem] ?? 0) + 1;
            $accepted[] = $entry;
        }

        return array_merge($accepted, $deferred);
    }
}
