<?php

namespace App\Services\Revival;

/**
 * Report schema v1.0 (PRD §7.4): the single source of truth for both the
 * model's constrained-decoding JSON Schema and the server-side validator.
 * Non-recursive by construction (Meta rejects recursive schemas with 400).
 */
class ReportSchema
{
    public const VERSION = '1.0';

    public const CLAIM_STATUSES = ['SUPPORTED', 'INFERRED', 'UNVERIFIED', 'CONTRADICTED', 'UNKNOWN'];

    public const VERDICTS = ['PROMISING', 'UNCERTAIN', 'HIGH_RISK', 'INSUFFICIENT_EVIDENCE'];

    public const CLASSIFICATIONS = ['SOURCE_CLAIM'];

    /** @return array<string, mixed> JSON Schema for response_format. */
    public static function jsonSchema(): array
    {
        $str = ['type' => 'string'];
        $strList = ['type' => 'array', 'items' => $str];

        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => [
                'schema_version', 'device', 'goal', 'summary', 'evidence', 'findings',
                'unknowns', 'revival_paths', 'experiments', 'milestones',
                'contributor_tasks', 'warnings', 'source_manifest_ids',
            ],
            'properties' => [
                'schema_version' => ['type' => 'string'],
                'device' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['manufacturer', 'model', 'variant'],
                    'properties' => [
                        'manufacturer' => ['type' => ['string', 'null']],
                        'model' => ['type' => 'string'],
                        'variant' => ['type' => ['string', 'null']],
                    ],
                ],
                'goal' => $str,
                'summary' => $str,
                'evidence' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object', 'additionalProperties' => false,
                        'required' => ['id', 'source_id', 'locator', 'claim', 'classification'],
                        'properties' => [
                            'id' => $str,
                            'source_id' => ['type' => 'integer'],
                            'locator' => $str,
                            'claim' => $str,
                            'classification' => ['type' => 'string', 'enum' => self::CLASSIFICATIONS],
                        ],
                    ],
                ],
                'findings' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object', 'additionalProperties' => false,
                        'required' => ['id', 'statement', 'status', 'evidence_ids', 'limitations'],
                        'properties' => [
                            'id' => $str,
                            'statement' => $str,
                            'status' => ['type' => 'string', 'enum' => self::CLAIM_STATUSES],
                            'evidence_ids' => $strList,
                            'limitations' => $str,
                        ],
                    ],
                ],
                'unknowns' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object', 'additionalProperties' => false,
                        'required' => ['id', 'question', 'why_it_matters'],
                        'properties' => ['id' => $str, 'question' => $str, 'why_it_matters' => $str],
                    ],
                ],
                'revival_paths' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object', 'additionalProperties' => false,
                        'required' => ['id', 'approach', 'feasibility', 'assumptions', 'supporting_evidence_ids', 'counter_evidence_ids', 'risks', 'validation_needed'],
                        'properties' => [
                            'id' => $str,
                            'approach' => $str,
                            'feasibility' => ['type' => 'string', 'enum' => self::VERDICTS],
                            'assumptions' => $strList,
                            'supporting_evidence_ids' => $strList,
                            'counter_evidence_ids' => $strList,
                            'risks' => $strList,
                            'validation_needed' => $strList,
                        ],
                    ],
                ],
                'experiments' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object', 'additionalProperties' => false,
                        'required' => ['id', 'question', 'hypothesis', 'prerequisites', 'procedure', 'expected_observations', 'pass_criteria', 'fail_criteria', 'safety_notes', 'source_ids'],
                        'properties' => [
                            'id' => $str,
                            'question' => $str,
                            'hypothesis' => $str,
                            'prerequisites' => $strList,
                            'procedure' => $strList,
                            'expected_observations' => $strList,
                            'pass_criteria' => $strList,
                            'fail_criteria' => $strList,
                            'safety_notes' => $strList,
                            'source_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        ],
                    ],
                ],
                'milestones' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object', 'additionalProperties' => false,
                        'required' => ['title', 'description', 'depends_on', 'task_ids'],
                        'properties' => [
                            'title' => $str, 'description' => $str,
                            'depends_on' => $strList, 'task_ids' => $strList,
                        ],
                    ],
                ],
                'contributor_tasks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object', 'additionalProperties' => false,
                        'required' => ['id', 'title', 'skill_tags', 'definition_of_done', 'requires_hardware'],
                        'properties' => [
                            'id' => $str,
                            'title' => $str,
                            'skill_tags' => $strList,
                            'definition_of_done' => $str,
                            'requires_hardware' => ['type' => 'boolean'],
                        ],
                    ],
                ],
                'warnings' => $strList,
                'source_manifest_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
        ];
    }

    /**
     * Validate a decoded payload independently of the model.
     *
     * @param  mixed  $payload  Decoded JSON.
     * @param  array<int, string>  $sourcePaths  Available evidence row id => path.
     * @return list<string> Error messages; empty means valid.
     */
    public static function validate(mixed $payload, array $sourcePaths): array
    {
        $errors = [];

        if (! is_array($payload)) {
            return ['Report payload must be a JSON object.'];
        }

        foreach (['schema_version', 'device', 'goal', 'summary', 'evidence', 'findings', 'unknowns', 'revival_paths', 'experiments', 'milestones', 'contributor_tasks', 'warnings', 'source_manifest_ids'] as $key) {
            if (! array_key_exists($key, $payload)) {
                $errors[] = "Missing required key: {$key}.";
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        if ($payload['schema_version'] !== self::VERSION) {
            $errors[] = 'Unsupported schema_version: '.json_encode($payload['schema_version']).'.';
        }

        if (! is_array($payload['device']) || ! isset($payload['device']['model']) || ! is_string($payload['device']['model'])) {
            $errors[] = 'device.model must be a string.';
        }

        if (! is_string($payload['summary']) || trim($payload['summary']) === '') {
            $errors[] = 'summary must be a non-empty string.';
        }

        $evidenceIds = [];
        foreach ($payload['evidence'] as $i => $item) {
            $at = "evidence[{$i}]";
            $id = $item['id'] ?? null;

            if (! is_string($id) || $id === '') {
                $errors[] = "{$at}.id must be a non-empty string.";

                continue;
            }

            if (isset($evidenceIds[$id])) {
                $errors[] = "Duplicate evidence id: {$id}.";
            }

            $evidenceIds[$id] = true;

            $sourceId = $item['source_id'] ?? null;

            if (! is_int($sourceId) || ! array_key_exists($sourceId, $sourcePaths)) {
                $errors[] = "{$at} cites unknown source_id: ".json_encode($sourceId).'.';

                continue;
            }

            $locator = $item['locator'] ?? null;

            if (! is_string($locator) || trim($locator) === '') {
                $errors[] = "{$at}.locator must be a non-empty string.";
            } elseif (str_contains($locator, ':') && ! str_starts_with($locator, $sourcePaths[$sourceId].':')) {
                $errors[] = "{$at}.locator path does not match the cited source path.";
            }

            if (! is_string($item['claim'] ?? null) || trim($item['claim']) === '') {
                $errors[] = "{$at}.claim must be a non-empty string.";
            }

            if (! in_array($item['classification'] ?? null, self::CLASSIFICATIONS, true)) {
                $errors[] = "{$at}.classification is invalid.";
            }
        }

        foreach ($payload['findings'] as $i => $finding) {
            $at = "findings[{$i}]";

            if (! is_string($finding['statement'] ?? null) || trim($finding['statement']) === '') {
                $errors[] = "{$at}.statement must be a non-empty string.";
            }

            $status = $finding['status'] ?? null;

            if (! in_array($status, self::CLAIM_STATUSES, true)) {
                $errors[] = "{$at}.status is invalid.";
            }

            $ids = $finding['evidence_ids'] ?? null;

            if (! is_array($ids)) {
                $errors[] = "{$at}.evidence_ids must be an array.";

                continue;
            }

            if ($ids === [] && $status !== 'UNVERIFIED') {
                $errors[] = "{$at} with status {$status} must cite at least one evidence id.";
            }

            foreach ($ids as $ref) {
                if (! isset($evidenceIds[$ref])) {
                    $errors[] = "{$at} cites unknown evidence id: ".json_encode($ref).'.';
                }
            }
        }

        foreach ($payload['unknowns'] as $i => $unknown) {
            if (! is_string($unknown['question'] ?? null) || trim($unknown['question']) === '') {
                $errors[] = "unknowns[{$i}].question must be a non-empty string.";
            }
        }

        foreach ($payload['revival_paths'] as $i => $path) {
            $at = "revival_paths[{$i}]";

            if (! is_string($path['approach'] ?? null) || trim($path['approach']) === '') {
                $errors[] = "{$at}.approach must be a non-empty string.";
            }

            if (! in_array($path['feasibility'] ?? null, self::VERDICTS, true)) {
                $errors[] = "{$at}.feasibility is invalid.";
            }

            foreach (['supporting_evidence_ids', 'counter_evidence_ids'] as $key) {
                foreach ((array) ($path[$key] ?? []) as $ref) {
                    if (! isset($evidenceIds[$ref])) {
                        $errors[] = "{$at}.{$key} cites unknown evidence id: ".json_encode($ref).'.';
                    }
                }
            }
        }

        $taskIds = [];
        foreach ($payload['contributor_tasks'] as $task) {
            if (is_string($task['id'] ?? null)) {
                $taskIds[$task['id']] = true;
            }
        }

        foreach ($payload['experiments'] as $i => $experiment) {
            $at = "experiments[{$i}]";

            if (($experiment['procedure'] ?? []) === []) {
                $errors[] = "{$at}.procedure must list at least one step.";
            }

            foreach ((array) ($experiment['source_ids'] ?? []) as $sourceId) {
                if (! array_key_exists($sourceId, $sourcePaths)) {
                    $errors[] = "{$at} cites unknown source_id: ".json_encode($sourceId).'.';
                }
            }
        }

        foreach ($payload['milestones'] as $i => $milestone) {
            if (! is_string($milestone['title'] ?? null) || trim($milestone['title']) === '') {
                $errors[] = "milestones[{$i}].title must be a non-empty string.";
            }

            foreach ((array) ($milestone['task_ids'] ?? []) as $ref) {
                if (! isset($taskIds[$ref])) {
                    $errors[] = "milestones[{$i}] cites unknown task id: ".json_encode($ref).'.';
                }
            }
        }

        foreach ($payload['source_manifest_ids'] as $sourceId) {
            if (! array_key_exists($sourceId, $sourcePaths)) {
                $errors[] = 'source_manifest_ids contains unknown source_id: '.json_encode($sourceId).'.';
            }
        }

        if ($payload['findings'] === [] && $payload['unknowns'] === []) {
            $errors[] = 'Report must contain at least one finding or one unknown.';
        }

        if ($payload['experiments'] === [] && $payload['unknowns'] === []) {
            $errors[] = 'Report without experiments must record unknowns.';
        }

        return $errors;
    }
}
