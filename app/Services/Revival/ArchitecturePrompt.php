<?php

namespace App\Services\Revival;

/**
 * Prompt for the Architecture Investigator (prompt version 1.0).
 *
 * The evidence block reuses the revival analysis format so citations
 * stay comparable; the signals block adds deterministic measurements.
 */
final class ArchitecturePrompt
{
    public const VERSION = '1.0';

    public static function system(): string
    {
        return <<<'PROMPT'
You are the Rev2Square Architecture Investigator. Map the software architecture of an abandoned project from the repository evidence you receive.

Output exactly one JSON object matching the requested schema — no markdown, no commentary.

Rules:
- Components are code units you can name from the evidence: entry points, services, modules, datastores, external systems, configs, build scripts.
- Relationships link two component ids and describe how they interact (calls, imports, reads, writes, configures, depends_on, serves, documents).
- Certainty: OBSERVED for facts stated in the evidence, INFERRED for relationships you deduce from imports or configuration, UNKNOWN for guesses. Never present INFERRED or UNKNOWN findings as established facts.
- Every component and every relationship needs at least one evidence reference with the exact source_id, the exact stored path, and the content hash printed beside the source.
- Citations use original line numbers: "path/to/file.go" or "path/to/file.go:L10-L40". For PARTIAL excerpts, cite only the recorded line ranges; when ranges are unknown, cite the path without line numbers. Never invent line numbers.
- Deterministic signals (entry points, imports, configs) are measured facts — prefer them over guesses. Measured import edges that you cannot explain become INFERRED relationships or unknowns.
- Record open questions as unknowns instead of guessing: what is missing, and which additional files would resolve it.
- The coverage note states plainly what you inspected and what you did not (partial excerpts, excluded files, capped inventory).
PROMPT;
    }

    /**
     * @param  array{entry_points: list<array{path: string, reason: string}>, imports: array<string, list<string>>, configs: list<array{path: string, kind: string}>, external_notes: list<array{path: string, note: string}>, stats: array{files: int, bytes: int, languages: array<string, int>}}  $signals
     */
    public static function signalsBlock(array $signals): string
    {
        $lines = [];
        $lines[] = '## Deterministic architectural signals (computed locally, not model output)';
        $stats = $signals['stats'];
        $topLanguages = array_slice(array_keys($stats['languages']), 0, 5);
        $lines[] = sprintf(
            'Analyzed %d files (%d bytes)%s.',
            $stats['files'],
            $stats['bytes'],
            $topLanguages !== [] ? '; main languages: '.implode(', ', $topLanguages) : ''
        );

        $lines[] = 'Entry-point candidates:';
        if ($signals['entry_points'] === []) {
            $lines[] = '- none detected';
        }
        foreach ($signals['entry_points'] as $entry) {
            $lines[] = "- {$entry['path']} ({$entry['reason']})";
        }

        $lines[] = 'Configuration and manifest files:';
        if ($signals['configs'] === []) {
            $lines[] = '- none detected';
        }
        foreach ($signals['configs'] as $config) {
            $lines[] = "- {$config['path']} [{$config['kind']}]";
        }

        $lines[] = 'Measured import edges (importer -> dependency):';
        $shown = 0;
        foreach ($signals['imports'] as $path => $edges) {
            foreach ($edges as $edge) {
                $lines[] = "- {$path} -> {$edge}";
                if (++$shown >= 100) {
                    break 2;
                }
            }
        }
        if ($shown === 0) {
            $lines[] = '- none detected';
        }

        if ($signals['external_notes'] !== []) {
            $lines[] = 'External content markers (not imported):';
            foreach ($signals['external_notes'] as $note) {
                $lines[] = "- {$note['path']}: {$note['note']}";
            }
        }

        return implode("\n", $lines);
    }
}
