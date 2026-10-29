<?php

namespace App\Services\Revival;

/**
 * Versioned schema for validated architecture snapshots.
 *
 * Every component and relationship carries a stable id, a certainty
 * classification, and evidence references that are checked server-side
 * against the exact sources the model received: unknown source ids,
 * path mismatches, hash mismatches, invalid line ranges, and edges
 * pointing at nonexistent components are all rejected.
 */
final class ArchitectureSchema
{
    public const VERSION = '1.0';

    public const MAX_COMPONENTS = 60;

    public const MAX_RELATIONSHIPS = 120;

    public const MAX_EVIDENCE_REFS = 8;

    public const MAX_UNKNOWNS = 30;

    public const MAX_LABEL = 200;

    public const MAX_EXPLANATION = 2000;

    public const MAX_SUMMARY = 4000;

    public const MAX_CITATION_SPAN = 400;

    public const MIN_HASH_PREFIX = 12;

    /** @var list<string> */
    public const COMPONENT_TYPES = [
        'entry_point',
        'service',
        'module',
        'datastore',
        'external_system',
        'config',
        'build',
    ];

    /** @var list<string> */
    public const RELATIONSHIP_TYPES = [
        'calls',
        'imports',
        'reads',
        'writes',
        'configures',
        'depends_on',
        'serves',
        'documents',
    ];

    /** @var list<string> */
    public const CERTAINTIES = ['OBSERVED', 'INFERRED', 'UNKNOWN'];

    public static function componentTypeLabel(string $type): string
    {
        return ucfirst(str_replace('_', ' ', $type));
    }

    public static function relationshipTypeLabel(string $type): string
    {
        return ucfirst(str_replace('_', ' ', $type));
    }

    public static function certaintyBadge(string $certainty): string
    {
        return match ($certainty) {
            'OBSERVED' => 'bg-emerald-100 text-emerald-900',
            'INFERRED' => 'bg-amber-100 text-amber-900',
            default => 'bg-zinc-200 text-zinc-700',
        };
    }

    /**
     * JSON schema handed to the provider for structured output.
     *
     * Strict-mode subset only: every object sets additionalProperties
     * false and requires all of its properties (the provider rejects
     * anything else with 400 before the model runs). Bounds such as
     * maxItems/minimum and free-text descriptions stay in the
     * server-side validator and the prompt instead — see validate()
     * and ArchitecturePrompt::system().
     *
     * content_hash stays a required string but may be empty: the model
     * is never shown hashes, so omitted values bind to the trusted
     * snapshot manifest while supplied values must match it.
     *
     * @return array<string, mixed>
     */
    public static function jsonSchema(): array
    {
        $evidenceRef = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['source_id', 'locator', 'content_hash'],
            'properties' => [
                'source_id' => ['type' => 'integer'],
                'locator' => ['type' => 'string'],
                'content_hash' => ['type' => 'string'],
            ],
        ];

        $certainty = ['type' => 'string', 'enum' => self::CERTAINTIES];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['schema_version', 'summary', 'components', 'relationships', 'unknowns', 'coverage_note'],
            'properties' => [
                'schema_version' => ['type' => 'string', 'enum' => [self::VERSION]],
                'summary' => ['type' => 'string'],
                'components' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['id', 'type', 'label', 'explanation', 'certainty', 'evidence'],
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'type' => ['type' => 'string', 'enum' => self::COMPONENT_TYPES],
                            'label' => ['type' => 'string'],
                            'explanation' => ['type' => 'string'],
                            'certainty' => $certainty,
                            'evidence' => ['type' => 'array', 'items' => $evidenceRef],
                        ],
                    ],
                ],
                'relationships' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['id', 'from', 'to', 'type', 'label', 'explanation', 'certainty', 'evidence'],
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'from' => ['type' => 'string'],
                            'to' => ['type' => 'string'],
                            'type' => ['type' => 'string', 'enum' => self::RELATIONSHIP_TYPES],
                            'label' => ['type' => 'string'],
                            'explanation' => ['type' => 'string'],
                            'certainty' => $certainty,
                            'evidence' => ['type' => 'array', 'items' => $evidenceRef],
                        ],
                    ],
                ],
                'unknowns' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['id', 'question', 'why_it_matters'],
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'question' => ['type' => 'string'],
                            'why_it_matters' => ['type' => 'string'],
                        ],
                    ],
                ],
                'coverage_note' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * Validate decoded model output against the evidence it was given.
     *
     * @param  mixed  $decoded  json_decode() output.
     * @param  array<int, array{path: string, content_hash: string, is_partial: bool, line_ranges: ?list<array{start: int, end: int}>, original_lines: ?int, line_count: int}>  $index
     * @return array{0: list<string>, 1: ?array<string, mixed>} errors + normalized payload.
     */
    public static function validate(mixed $decoded, array $index): array
    {
        $errors = [];

        if (! is_array($decoded)) {
            return [['Architecture output must be a JSON object.'], null];
        }

        if (($decoded['schema_version'] ?? null) !== self::VERSION) {
            $errors[] = 'schema_version must be "'.self::VERSION.'".';
        }

        $summary = $decoded['summary'] ?? null;
        if (! is_string($summary) || trim($summary) === '') {
            $errors[] = 'summary is required.';
        } elseif (strlen($summary) > self::MAX_SUMMARY) {
            $errors[] = 'summary exceeds '.self::MAX_SUMMARY.' characters.';
        }

        $coverage = $decoded['coverage_note'] ?? null;
        if (! is_string($coverage) || trim($coverage) === '') {
            $errors[] = 'coverage_note is required.';
        } elseif (strlen($coverage) > self::MAX_EXPLANATION) {
            $errors[] = 'coverage_note exceeds '.self::MAX_EXPLANATION.' characters.';
        }

        $components = $decoded['components'] ?? null;
        $relationships = $decoded['relationships'] ?? null;
        $unknowns = $decoded['unknowns'] ?? null;

        if (! is_array($components) || ! array_is_list($components)) {
            $errors[] = 'components must be a list.';
            $components = [];
        }
        if (! is_array($relationships) || ! array_is_list($relationships)) {
            $errors[] = 'relationships must be a list.';
            $relationships = [];
        }
        if (! is_array($unknowns) || ! array_is_list($unknowns)) {
            $errors[] = 'unknowns must be a list.';
            $unknowns = [];
        }

        if (count($components) > self::MAX_COMPONENTS) {
            $errors[] = 'Too many components (max '.self::MAX_COMPONENTS.').';
        }
        if (count($relationships) > self::MAX_RELATIONSHIPS) {
            $errors[] = 'Too many relationships (max '.self::MAX_RELATIONSHIPS.').';
        }
        if (count($unknowns) > self::MAX_UNKNOWNS) {
            $errors[] = 'Too many unknowns (max '.self::MAX_UNKNOWNS.').';
        }
        if ($components === [] && $unknowns === []) {
            $errors[] = 'At least one component or one unknown is required.';
        }

        $normalizedComponents = [];
        $componentIds = [];
        foreach ($components as $i => $component) {
            $where = "components[{$i}]";
            [$itemErrors, $normalized] = self::validateNode($component, $where, $index, self::COMPONENT_TYPES, 'component');
            $errors = array_merge($errors, $itemErrors);
            if ($normalized === null) {
                continue;
            }
            if (isset($componentIds[$normalized['id']])) {
                $errors[] = "{$where}.id duplicates component id \"{$normalized['id']}\".";

                continue;
            }
            $componentIds[$normalized['id']] = true;
            $normalizedComponents[] = $normalized;
        }

        $normalizedRelationships = [];
        $edgeIds = [];
        foreach ($relationships as $i => $edge) {
            $where = "relationships[{$i}]";
            [$itemErrors, $normalized] = self::validateNode($edge, $where, $index, self::RELATIONSHIP_TYPES, 'relationship');
            $errors = array_merge($errors, $itemErrors);
            if ($normalized === null) {
                continue;
            }
            if (isset($edgeIds[$normalized['id']])) {
                $errors[] = "{$where}.id duplicates relationship id \"{$normalized['id']}\".";

                continue;
            }
            $edgeIds[$normalized['id']] = true;

            $from = is_array($edge) ? ($edge['from'] ?? null) : null;
            $to = is_array($edge) ? ($edge['to'] ?? null) : null;
            if (! is_string($from) || $from === '' || ! isset($componentIds[$from])) {
                $errors[] = "{$where}.from must reference an existing component id.";
            }
            if (! is_string($to) || $to === '' || ! isset($componentIds[$to])) {
                $errors[] = "{$where}.to must reference an existing component id.";
            }
            if (is_string($from) && $from !== '' && $from === $to) {
                $errors[] = "{$where} must not link a component to itself.";
            }

            $normalized['from'] = is_string($from) ? $from : '';
            $normalized['to'] = is_string($to) ? $to : '';
            $normalizedRelationships[] = $normalized;
        }

        $normalizedUnknowns = [];
        $unknownIds = [];
        foreach ($unknowns as $i => $unknown) {
            $where = "unknowns[{$i}]";
            if (! is_array($unknown)) {
                $errors[] = "{$where} must be an object.";

                continue;
            }
            [$idErrors, $id] = self::validateId($unknown['id'] ?? null, $where.'.id');
            $errors = array_merge($errors, $idErrors);
            if ($id !== null && isset($unknownIds[$id])) {
                $errors[] = "{$where}.id duplicates unknown id \"{$id}\".";

                continue;
            }
            if ($id === null) {
                continue;
            }
            $unknownIds[$id] = true;

            $question = $unknown['question'] ?? null;
            $why = $unknown['why_it_matters'] ?? null;
            if (! is_string($question) || trim($question) === '') {
                $errors[] = "{$where}.question is required.";

                continue;
            }
            if (! is_string($why) || trim($why) === '') {
                $errors[] = "{$where}.why_it_matters is required.";

                continue;
            }
            if (strlen($question) > self::MAX_EXPLANATION) {
                $errors[] = "{$where}.question exceeds ".self::MAX_EXPLANATION.' characters.';

                continue;
            }
            if (strlen($why) > self::MAX_EXPLANATION) {
                $errors[] = "{$where}.why_it_matters exceeds ".self::MAX_EXPLANATION.' characters.';

                continue;
            }
            $normalizedUnknowns[] = ['id' => $id, 'question' => $question, 'why_it_matters' => $why];
        }

        if ($errors !== []) {
            return [$errors, null];
        }

        return [[], [
            'schema_version' => self::VERSION,
            'summary' => $summary,
            'components' => $normalizedComponents,
            'relationships' => $normalizedRelationships,
            'unknowns' => $normalizedUnknowns,
            'coverage_note' => $coverage,
        ]];
    }

    /**
     * @param  list<string>  $types
     * @return array{0: list<string>, 1: ?array<string, mixed>}
     */
    private static function validateNode(mixed $node, string $where, array $index, array $types, string $kind): array
    {
        $errors = [];

        if (! is_array($node)) {
            return [["{$where} must be an object."], null];
        }

        [$idErrors, $id] = self::validateId($node['id'] ?? null, $where.'.id');
        $errors = array_merge($errors, $idErrors);

        $type = $node['type'] ?? null;
        if (! is_string($type) || ! in_array($type, $types, true)) {
            $errors[] = "{$where}.type must be one of: ".implode(', ', $types).'.';
        }

        $label = $node['label'] ?? null;
        if (! is_string($label) || trim($label) === '') {
            $errors[] = "{$where}.label is required.";
        } elseif (strlen($label) > self::MAX_LABEL) {
            $errors[] = "{$where}.label exceeds ".self::MAX_LABEL.' characters.';
        }

        $explanation = $node['explanation'] ?? null;
        if (! is_string($explanation) || trim($explanation) === '') {
            $errors[] = "{$where}.explanation is required.";
        } elseif (strlen($explanation) > self::MAX_EXPLANATION) {
            $errors[] = "{$where}.explanation exceeds ".self::MAX_EXPLANATION.' characters.';
        }

        $certainty = $node['certainty'] ?? null;
        if (! is_string($certainty) || ! in_array($certainty, self::CERTAINTIES, true)) {
            $errors[] = "{$where}.certainty must be one of: ".implode(', ', self::CERTAINTIES).'.';
        }

        $evidence = $node['evidence'] ?? null;
        $normalizedEvidence = [];
        if (! is_array($evidence) || ! array_is_list($evidence)) {
            $errors[] = "{$where}.evidence must be a list.";
        } else {
            if (count($evidence) > self::MAX_EVIDENCE_REFS) {
                $errors[] = "{$where}.evidence exceeds ".self::MAX_EVIDENCE_REFS.' references.';
            }
            if ($evidence === []) {
                $errors[] = "{$where} needs at least one evidence reference.";
            }
            foreach ($evidence as $j => $ref) {
                [$refErrors, $normalizedRef] = self::validateEvidenceRef($ref, "{$where}.evidence[{$j}]", $index);
                $errors = array_merge($errors, $refErrors);
                if ($normalizedRef !== null) {
                    $normalizedEvidence[] = $normalizedRef;
                }
            }
        }

        if ($errors !== [] || $id === null) {
            return [$errors, null];
        }

        return [[], [
            'id' => $id,
            'type' => $type,
            'label' => $label,
            'explanation' => $explanation,
            'certainty' => $certainty,
            'evidence' => $normalizedEvidence,
        ]];
    }

    /** @return array{0: list<string>, 1: ?string} */
    private static function validateId(mixed $id, string $where): array
    {
        if (! is_string($id) || $id === '') {
            return [["{$where} is required."], null];
        }

        if (strlen($id) > 64 || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $id)) {
            return [["{$where} must be 1-64 chars: letters, digits, dot, underscore, hyphen."], null];
        }

        return [[], $id];
    }

    /**
     * @param  array<int, array{path: string, content_hash: string, is_partial: bool, line_ranges: ?list<array{start: int, end: int}>, original_lines: ?int, line_count: int}>  $index
     * @return array{0: list<string>, 1: ?array<string, mixed>}
     */
    private static function validateEvidenceRef(mixed $ref, string $where, array $index): array
    {
        if (! is_array($ref)) {
            return [["{$where} must be an object."], null];
        }

        $errors = [];
        $sourceId = $ref['source_id'] ?? null;

        if (! is_int($sourceId) || ! isset($index[$sourceId])) {
            return [["{$where}.source_id does not match any source in this snapshot."], null];
        }

        $entry = $index[$sourceId];

        $locator = $ref['locator'] ?? null;
        if (! is_string($locator) || trim($locator) === '') {
            $errors[] = "{$where}.locator is required.";
        }

        // The model is never shown content hashes, so an omitted hash is
        // honest: it binds to the trusted snapshot metadata below. Only a
        // supplied value is checked — malformed or mismatched hashes are
        // rejected as fabricated, never silently corrected.
        $hash = $ref['content_hash'] ?? null;
        if ($hash !== null && (! is_string($hash) || trim($hash) !== '')) {
            if (! is_string($hash) || ! preg_match('/^[0-9a-fA-F]{12,64}$/', $hash)) {
                $errors[] = "{$where}.content_hash must be at least 12 hex characters.";
            } elseif (! str_starts_with(strtolower($entry['content_hash']), strtolower($hash))) {
                $errors[] = "{$where}.content_hash does not match the stored source.";
            }
        }

        $from = null;
        $to = null;

        if (is_string($locator) && $locator !== '') {
            [$path, $from, $to, $locatorErrors] = self::parseLocator($locator, $where, $entry);
            $errors = array_merge($errors, $locatorErrors);
        } else {
            $path = '';
        }

        if ($errors !== []) {
            return [$errors, null];
        }

        return [[], [
            'source_id' => $sourceId,
            'path' => $path,
            'locator' => $locator,
            'content_hash' => strtolower($entry['content_hash']),
            'from_line' => $from,
            'to_line' => $to,
            'is_partial' => $entry['is_partial'],
        ]];
    }

    /**
     * @param  array{path: string, content_hash: string, is_partial: bool, line_ranges: ?list<array{start: int, end: int}>, original_lines: ?int, line_count: int}  $entry
     * @return array{0: string, 1: ?int, 2: ?int, 3: list<string>}
     */
    private static function parseLocator(string $locator, string $where, array $entry): array
    {
        $errors = [];
        $path = $locator;
        $from = null;
        $to = null;

        if (preg_match('/:L(\d+)(?:-L?(\d+))?$/', $locator, $m)) {
            $path = substr($locator, 0, -strlen($m[0]));
            $from = (int) $m[1];
            $to = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $from;
        }

        if ($path !== $entry['path']) {
            return [$path, null, null, ["{$where}.locator path does not match the stored path for this source — cite the exact stored path."]];
        }

        if ($from === null) {
            return [$path, null, null, []];
        }

        if ($from < 1 || $to < $from) {
            return [$path, null, null, ["{$where}.locator has an invalid line span."]];
        }

        if ($to - $from + 1 > self::MAX_CITATION_SPAN) {
            return [$path, null, null, ["{$where}.locator span exceeds ".self::MAX_CITATION_SPAN.' lines.']];
        }

        if (! $entry['is_partial']) {
            $max = max($entry['original_lines'] ?? 0, $entry['line_count']);
            if ($to > $max) {
                return [$path, null, null, ["{$where}.locator lines L{$from}-L{$to} exceed the {$max}-line source."]];
            }

            return [$path, $from, $to, []];
        }

        $ranges = $entry['line_ranges'];
        if (! is_array($ranges) || $ranges === []) {
            return [$path, null, null, ["{$where}.locator must not invent line numbers: original positions are unknown for this partial excerpt, cite the path only."]];
        }

        foreach ($ranges as $range) {
            if (! isset($range['start'], $range['end'])) {
                continue;
            }
            $start = (int) $range['start'];
            $end = (int) $range['end'];
            if ($from >= $start && $to <= $end) {
                return [$path, $from, $to, []];
            }
        }

        return [$path, null, null, ["{$where}.locator lines L{$from}-L{$to} fall outside the known excerpt ranges — cite only known original ranges."]];
    }
}
