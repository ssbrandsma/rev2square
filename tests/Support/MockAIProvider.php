<?php

namespace Tests\Support;

use App\Services\AI\AIProviderException;
use App\Services\AI\AIProviderInterface;
use App\Services\AI\AIRequest;
use App\Services\AI\AIResponse;
use App\Services\AI\AIToolCall;
use Throwable;

/**
 * Deterministic scripted provider for automated tests ONLY.
 * Never used for the judged demo or any user-facing analysis.
 */
class MockAIProvider implements AIProviderInterface
{
    /** @var list<array<string, mixed>|string|Throwable> */
    public static array $queue = [];

    /** @var list<AIRequest> */
    public static array $requests = [];

    /** @param  list<array<string, mixed>|string|Throwable>  $responses */
    public static function fake(array $responses = []): void
    {
        self::$queue = $responses;
        self::$requests = [];
    }

    public function name(): string
    {
        return 'mock';
    }

    public function complete(AIRequest $request): AIResponse
    {
        self::$requests[] = $request;

        if (self::$queue === []) {
            throw new AIProviderException('No mock AI response queued.');
        }

        $next = array_shift(self::$queue);

        if ($next instanceof Throwable) {
            throw $next;
        }

        $toolCalls = null;

        if (is_array($next) && array_key_exists('__tool_calls', $next)) {
            $toolCalls = array_map(
                fn ($call) => new AIToolCall(
                    $call['id'] ?? 'call-'.count(self::$requests),
                    $call['name'],
                    is_string($call['arguments'] ?? null) ? $call['arguments'] : json_encode($call['arguments'] ?? [])
                ),
                $next['__tool_calls']
            );
            $next = $next['content'] ?? '';
        }

        return new AIResponse(
            content: is_string($next) ? $next : json_encode($next),
            model: 'mock',
            requestId: 'mock-'.count(self::$requests),
            inputTokens: 100,
            outputTokens: 50,
            durationMs: 5,
            toolCalls: $toolCalls,
        );
    }

    /**
     * Build a complete valid v1.0 report payload citing the given sources.
     *
     * @param  list<array{source_id: int, path: string}>  $sources
     * @return array<string, mixed>
     */
    public static function reportPayload(array $sources, array $overrides = []): array
    {
        $first = $sources[0];

        $payload = [
            'schema_version' => '1.0',
            'device' => ['manufacturer' => null, 'model' => 'Test Device', 'variant' => null],
            'goal' => 'Restore basic functionality.',
            'summary' => 'Evidence supports a careful investigation path.',
            'evidence' => array_map(fn ($s, $i) => [
                'id' => 'E'.($i + 1),
                'source_id' => $s['source_id'],
                'locator' => $s['path'].':L1-L5',
                'claim' => 'Documented behavior excerpt.',
                'classification' => 'SOURCE_CLAIM',
            ], $sources, array_keys($sources)),
            'findings' => [
                [
                    'id' => 'F1',
                    'statement' => 'The device exposes a local API.',
                    'status' => 'SUPPORTED',
                    'evidence_ids' => ['E1'],
                    'limitations' => 'Only one source observed this.',
                ],
            ],
            'unknowns' => [
                ['id' => 'U1', 'question' => 'Which firmware revisions work?', 'why_it_matters' => 'Scope of support.'],
            ],
            'revival_paths' => [
                [
                    'id' => 'P1',
                    'approach' => 'Local service replacement.',
                    'feasibility' => 'UNCERTAIN',
                    'assumptions' => ['API stays stable.'],
                    'supporting_evidence_ids' => ['E1'],
                    'counter_evidence_ids' => [],
                    'risks' => ['Undocumented behavior.'],
                    'validation_needed' => ['Capture live traffic.'],
                ],
            ],
            'experiments' => [
                [
                    'id' => 'X1',
                    'question' => 'Does the local API respond?',
                    'hypothesis' => 'It responds on the documented port.',
                    'prerequisites' => ['Device powered on.'],
                    'procedure' => ['Send probe request.', 'Record response.'],
                    'expected_observations' => ['HTTP 200 with status payload.'],
                    'pass_criteria' => ['Response matches documented shape.'],
                    'fail_criteria' => ['Connection refused or timeout.'],
                    'safety_notes' => ['No destructive steps.'],
                    'source_ids' => [$first['source_id']],
                ],
            ],
            'milestones' => [
                ['title' => 'Confirm API behavior', 'description' => 'Run X1.', 'depends_on' => [], 'task_ids' => ['T1']],
            ],
            'contributor_tasks' => [
                [
                    'id' => 'T1',
                    'title' => 'Run the API probe',
                    'skill_tags' => ['testing'],
                    'definition_of_done' => 'Probe log attached.',
                    'requires_hardware' => true,
                ],
            ],
            'warnings' => [],
            'source_manifest_ids' => array_column($sources, 'source_id'),
        ];

        return array_replace_recursive($payload, $overrides);
    }

    /**
     * Build a complete valid architecture payload citing the given sources.
     *
     * @param  list<array{source_id: int, path: string, content_hash: string}>  $sources
     * @return array<string, mixed>
     */
    public static function architecturePayload(array $sources, array $overrides = []): array
    {
        $refs = array_map(fn ($s) => [
            'source_id' => $s['source_id'],
            'locator' => $s['path'],
            'content_hash' => $s['content_hash'],
        ], $sources);

        $payload = [
            'schema_version' => '1.0',
            'summary' => 'A small service with an entry point and a helper module.',
            'components' => [
                [
                    'id' => 'entry',
                    'type' => 'entry_point',
                    'label' => 'CLI entry point',
                    'explanation' => 'Parses arguments and starts the service.',
                    'certainty' => 'OBSERVED',
                    'evidence' => [$refs[0]],
                ],
                [
                    'id' => 'helper',
                    'type' => 'module',
                    'label' => 'Helper module',
                    'explanation' => 'Shared formatting helpers.',
                    'certainty' => 'INFERRED',
                    'evidence' => [$refs[count($refs) - 1]],
                ],
            ],
            'relationships' => [
                [
                    'id' => 'uses-helper',
                    'from' => 'entry',
                    'to' => 'helper',
                    'type' => 'imports',
                    'label' => 'Entry imports helper',
                    'explanation' => 'The entry point imports the helper module.',
                    'certainty' => 'OBSERVED',
                    'evidence' => [$refs[0]],
                ],
            ],
            'unknowns' => [
                ['id' => 'u1', 'question' => 'Where is state persisted?', 'why_it_matters' => 'Determines backup strategy.'],
            ],
            'coverage_note' => 'Inspected the provided evidence files only.',
        ];

        return array_replace_recursive($payload, $overrides);
    }
}
