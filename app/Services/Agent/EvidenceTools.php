<?php

namespace App\Services\Agent;

use App\Enums\AnalysisStatus;
use App\Models\ArchitectureSnapshot;

/**
 * The Revival Agent's read-only tools: search_evidence, read_evidence,
 * get_architecture.
 *
 * Every invocation re-checks the project policy and scopes all reads to
 * the context repository's current evidence. Model-supplied ids and
 * paths select rows; they never widen access.
 */
final class EvidenceTools
{
    public const SEARCH = 'search_evidence';

    public const READ = 'read_evidence';

    public const ARCHITECTURE = 'get_architecture';

    public const MAX_QUERY = 200;

    public const MAX_SEARCH_LIMIT = 8;

    public const MAX_READ_LINES = 400;

    public const MAX_READ_BYTES = 32768;

    public const MAX_TOOL_BYTES = 24576;

    /** @return list<string> */
    public static function names(): array
    {
        return [self::SEARCH, self::READ, self::ARCHITECTURE];
    }

    /** @return list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}> */
    public static function definitions(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => self::SEARCH,
                    'description' => 'Search the project evidence files by keywords. Returns matching sources with ids, paths, and snippets. Search before reading.',
                    'parameters' => [
                        'type' => 'object',
                        'required' => ['query'],
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Keywords, e.g. "bluetooth pairing service". Max 200 chars.'],
                            'limit' => ['type' => 'integer', 'description' => 'Max results, 1-8. Default 5.'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => self::READ,
                    'description' => 'Read one evidence source by its source_id. Use from_line/to_line for windows of whole files. Partial excerpts return as stored with their known ranges.',
                    'parameters' => [
                        'type' => 'object',
                        'required' => ['source_id'],
                        'properties' => [
                            'source_id' => ['type' => 'integer', 'description' => 'Evidence id from search results.'],
                            'from_line' => ['type' => 'integer', 'description' => 'First line (whole files only).'],
                            'to_line' => ['type' => 'integer', 'description' => 'Last line, max 400 lines per read.'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => self::ARCHITECTURE,
                    'description' => 'Summarize the validated architecture snapshot: components, relationships, and open questions with their certainty.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $args
     */
    public static function execute(string $name, ?array $args, ToolContext $context): ToolResult
    {
        if (! in_array($name, self::names(), true)) {
            return ToolResult::error('unknown_tool', "Unknown tool '{$name}'. Available tools: ".implode(', ', self::names()).'.');
        }

        if (! $context->user->can('view', $context->project)) {
            return ToolResult::error('forbidden', 'You no longer have access to this project.');
        }

        if ($context->repository->project_id !== $context->project->id) {
            return ToolResult::error('forbidden', 'Repository does not belong to this project.');
        }

        if (! $context->repository->isReady()) {
            return ToolResult::error('not_ready', 'Evidence is not ready for this repository.');
        }

        if ($context->snapshot !== null
            && ($context->snapshot->project_id !== $context->project->id
                || $context->snapshot->repository_id !== $context->repository->id)) {
            return ToolResult::error('forbidden', 'Architecture snapshot does not belong to this repository.');
        }

        return match ($name) {
            self::SEARCH => self::search($args ?? [], $context),
            self::READ => self::read($args ?? [], $context),
            self::ARCHITECTURE => self::architecture($context),
        };
    }

    /** @param  array<string, mixed>  $args */
    private static function search(array $args, ToolContext $context): ToolResult
    {
        $query = $args['query'] ?? null;
        if (! is_string($query) || trim($query) === '' || strlen($query) > self::MAX_QUERY) {
            return ToolResult::error('invalid_arguments', 'query is required (1-'.self::MAX_QUERY.' chars).');
        }

        $limit = $args['limit'] ?? 5;
        if (is_numeric($limit)) {
            $limit = (int) $limit;
        }
        if (! is_int($limit) || $limit < 1 || $limit > self::MAX_SEARCH_LIMIT) {
            return ToolResult::error('invalid_arguments', 'limit must be 1-'.self::MAX_SEARCH_LIMIT.'.');
        }

        preg_match_all('/[a-z0-9]{2,}/i', strtolower($query), $m);
        $terms = array_values(array_unique($m[0]));

        if ($terms === []) {
            return ToolResult::error('invalid_arguments', 'query needs at least one word of 2+ letters or digits.');
        }
        $terms = array_slice($terms, 0, 8);

        $candidates = $context->repository->currentEvidence()
            ->where(function ($q) use ($terms) {
                foreach ($terms as $term) {
                    $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                    $q->orWhere('path', 'like', $like)->orWhere('content', 'like', $like);
                }
            })
            ->orderBy('path')
            ->limit(60)
            ->get(['id', 'path', 'content', 'is_partial']);

        $scored = [];
        foreach ($candidates as $row) {
            $score = 0;
            $lowerPath = strtolower($row->path);
            $lowerContent = strtolower((string) $row->content);
            foreach ($terms as $term) {
                if (str_contains($lowerPath, $term)) {
                    $score += 3;
                }
                $score += min(5, substr_count($lowerContent, $term));
            }
            if ($score > 0) {
                $scored[] = ['row' => $row, 'score' => $score];
            }
        }

        usort($scored, fn ($a, $b) => [$b['score'], $a['row']->path] <=> [$a['score'], $b['row']->path]);
        $top = array_slice($scored, 0, $limit);

        $results = [];
        $seen = [];
        foreach ($top as $entry) {
            $row = $entry['row'];
            $seen[] = $row->id;
            $results[] = [
                'source_id' => $row->id,
                'path' => $row->path,
                'score' => $entry['score'],
                'is_partial' => (bool) $row->is_partial,
                'snippet' => self::snippet((string) $row->content, $terms),
            ];
        }

        return ToolResult::ok([
            'query' => $query,
            'results' => $results,
            'candidates_capped' => count($candidates) >= 60,
        ], $seen);
    }

    /** @param  list<string>  $terms */
    private static function snippet(string $content, array $terms): string
    {
        $lower = strtolower($content);
        $pos = null;
        foreach ($terms as $term) {
            $found = strpos($lower, $term);
            if ($found !== false && ($pos === null || $found < $pos)) {
                $pos = $found;
            }
        }

        if ($pos === null) {
            return self::cleanText(substr($content, 0, 240));
        }

        $start = max(0, $pos - 120);
        $text = substr($content, $start, 300);

        return self::cleanText(($start > 0 ? '…' : '').$text.(strlen($content) > $start + 300 ? '…' : ''));
    }

    /** @param  array<string, mixed>  $args */
    private static function read(array $args, ToolContext $context): ToolResult
    {
        $sourceId = $args['source_id'] ?? null;
        if (is_numeric($sourceId)) {
            $sourceId = (int) $sourceId;
        }
        if (! is_int($sourceId) || $sourceId < 1) {
            return ToolResult::error('invalid_arguments', 'source_id is required.');
        }

        $row = $context->repository->currentEvidence()->where('id', $sourceId)->first();

        if ($row === null) {
            return ToolResult::error('unknown_source', "No evidence source #{$sourceId} in this repository snapshot.");
        }

        $lines = explode("\n", (string) $row->content);
        $total = count($lines);

        if ($row->is_partial) {
            // Never invent line positions for partial excerpts: return the
            // stored text as-is and report the known original ranges.
            $content = (string) $row->content;
            $truncated = strlen($content) > self::MAX_READ_BYTES;
            if ($truncated) {
                $content = substr($content, 0, self::MAX_READ_BYTES);
            }

            return ToolResult::ok([
                'source_id' => $row->id,
                'path' => $row->path,
                'commit_sha' => $row->commit_sha,
                'content_hash_prefix' => substr((string) $row->content_hash, 0, 12),
                'is_partial' => true,
                'excerpt_ranges' => $row->line_ranges ?? 'unknown',
                'original_lines' => $row->original_lines,
                'stored_lines' => $total,
                'from_line' => null,
                'to_line' => null,
                'truncated' => $truncated,
                'content' => self::cleanText($content),
            ], [$row->id]);
        }

        $from = $args['from_line'] ?? 1;
        $to = $args['to_line'] ?? $total;
        if (is_numeric($from)) {
            $from = (int) $from;
        }
        if (is_numeric($to)) {
            $to = (int) $to;
        }
        if (! is_int($from) || ! is_int($to) || $from < 1 || $to < $from) {
            return ToolResult::error('invalid_arguments', 'from_line/to_line must be line numbers with to_line >= from_line.');
        }

        $from = min($from, $total);
        $to = min($to, $total, $from + self::MAX_READ_LINES - 1);
        $slice = array_slice($lines, $from - 1, $to - $from + 1);
        $content = implode("\n", $slice);
        $truncated = strlen($content) > self::MAX_READ_BYTES;
        if ($truncated) {
            $content = substr($content, 0, self::MAX_READ_BYTES);
        }

        return ToolResult::ok([
            'source_id' => $row->id,
            'path' => $row->path,
            'commit_sha' => $row->commit_sha,
            'content_hash_prefix' => substr((string) $row->content_hash, 0, 12),
            'is_partial' => false,
            'from_line' => $from,
            'to_line' => $to,
            'total_lines' => $total,
            'truncated' => $truncated,
            'content' => self::cleanText($content),
        ], [$row->id]);
    }

    private static function architecture(ToolContext $context): ToolResult
    {
        $snapshot = ArchitectureSnapshot::where('project_id', $context->project->id)
            ->where('repository_id', $context->repository->id)
            ->where('status', AnalysisStatus::Ready)
            ->latest('id')
            ->first();

        if ($snapshot === null || ! $snapshot->isReady()) {
            return ToolResult::ok([
                'available' => false,
                'reason' => 'No architecture snapshot yet. Ask a maintainer to run the architecture analysis first.',
            ]);
        }

        $stale = $snapshot->staleness($context->repository);
        $seen = [];

        $components = array_map(function ($c) use (&$seen) {
            foreach ($c['evidence'] ?? [] as $ref) {
                if (isset($ref['source_id'])) {
                    $seen[] = (int) $ref['source_id'];
                }
            }

            return [
                'id' => $c['id'],
                'type' => $c['type'],
                'label' => $c['label'],
                'certainty' => $c['certainty'],
                'explanation' => substr((string) ($c['explanation'] ?? ''), 0, 300),
                'evidence' => array_map(fn ($ref) => $ref['locator'] ?? '', $c['evidence'] ?? []),
            ];
        }, $snapshot->components());

        $edges = array_map(fn ($e) => [
            'id' => $e['id'],
            'from' => $e['from'],
            'to' => $e['to'],
            'type' => $e['type'],
            'label' => $e['label'],
            'certainty' => $e['certainty'],
        ], $snapshot->relationships());

        $data = [
            'available' => true,
            'snapshot_id' => $snapshot->id,
            'commit_sha' => $snapshot->manifest_commit_sha,
            'stale' => $stale !== null,
            'stale_reason' => $stale,
            'schema_version' => $snapshot->schema_version,
            'summary' => substr($snapshot->summary(), 0, 1500),
            'components' => $components,
            'relationships' => $edges,
            'unknowns' => array_map(fn ($u) => [
                'question' => substr((string) ($u['question'] ?? ''), 0, 300),
                'why_it_matters' => substr((string) ($u['why_it_matters'] ?? ''), 0, 300),
            ], $snapshot->unknowns()),
            'coverage_note' => substr((string) ($snapshot->payload['coverage_note'] ?? ''), 0, 800),
            'trimmed' => false,
        ];

        if (strlen((string) json_encode($data)) > self::MAX_TOOL_BYTES) {
            // Trim explanations first; structure and citations survive.
            foreach ($data['components'] as &$component) {
                $component['explanation'] = '';
            }
            unset($component);
            $data['unknowns'] = array_map(fn ($u) => ['question' => $u['question'], 'why_it_matters' => ''], $data['unknowns']);
            $data['coverage_note'] = '';
            $data['trimmed'] = true;
        }

        if (strlen((string) json_encode($data)) > self::MAX_TOOL_BYTES) {
            $data['components'] = array_slice($data['components'], 0, 25);
            $data['relationships'] = array_slice($data['relationships'], 0, 40);
            $data['unknowns'] = array_slice($data['unknowns'], 0, 10);
            $data['trimmed'] = true;
        }

        return ToolResult::ok($data, array_values(array_unique($seen)));
    }

    private static function cleanText(string $text): string
    {
        $clean = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        return str_replace("\0", '', $clean);
    }
}
