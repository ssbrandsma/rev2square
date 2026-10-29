<?php

namespace Tests\Unit;

use App\Services\Agent\AgentTurnRunner;
use App\Services\Agent\ToolResult;
use App\Services\AI\AIProviderException;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockAIProvider;

class AgentTurnRunnerTest extends TestCase
{
    /** @var list<array{name: string, args: ?array}> */
    private array $executed = [];

    /** @var array<string, ToolResult> */
    private array $stubs = [];

    protected function tearDown(): void
    {
        MockAIProvider::fake([]);
        parent::tearDown();
    }

    private function runner(array $overrides = []): AgentTurnRunner
    {
        return new AgentTurnRunner(
            new MockAIProvider,
            function (string $name, ?array $args): ToolResult {
                $this->executed[] = ['name' => $name, 'args' => $args];

                return $this->stubs[$name] ?? ToolResult::ok(['echo' => $args]);
            },
            [['type' => 'function', 'function' => ['name' => 'search_evidence', 'description' => 's', 'parameters' => ['type' => 'object']]]],
            ['search_evidence'],
            $overrides['maxToolCalls'] ?? 6,
            $overrides['maxIdenticalCalls'] ?? 2,
            $overrides['turnSeconds'] ?? 60,
            $overrides['tokenBudget'] ?? 30000,
            $overrides['maxProviderCalls'] ?? 8,
        );
    }

    public function test_direct_answer_without_tools(): void
    {
        MockAIProvider::fake(['The entry point is main.py.']);

        $result = $this->runner()->run('system', 'Where is the entry point?', [], 'test-1');

        $this->assertSame('complete', $result->stoppedReason);
        $this->assertSame('The entry point is main.py.', $result->answer);
        $this->assertSame([], $result->toolTrace);
        $this->assertSame([], $this->executed);
    }

    public function test_tool_result_flows_back_with_protocol_history(): void
    {
        $this->stubs['search_evidence'] = ToolResult::ok(['results' => [['source_id' => 5]]], [5]);
        MockAIProvider::fake([
            ['__tool_calls' => [['id' => 'call-1', 'name' => 'search_evidence', 'arguments' => ['query' => 'entry']]], 'content' => ''],
            'Found it in [S5].',
        ]);

        $result = $this->runner()->run('system', 'Where?', [], 'test-2');

        $this->assertSame('complete', $result->stoppedReason);
        $this->assertCount(1, $result->toolTrace);
        $this->assertTrue($result->toolTrace[0]['ok']);
        $this->assertSame([['marker' => '[S5]', 'source_id' => 5, 'verified' => true]], $result->citations);
        $this->assertSame([], $result->unverifiedMarkers);

        $requests = MockAIProvider::$requests;
        $this->assertCount(2, $requests);
        $second = $requests[1]->messages;
        $this->assertSame('assistant', $second[2]['role']);
        $this->assertSame('call-1', $second[2]['tool_calls'][0]['id']);
        $this->assertSame('tool', $second[3]['role']);
        $this->assertSame('call-1', $second[3]['tool_call_id']);
    }

    public function test_unseen_citations_are_flagged(): void
    {
        MockAIProvider::fake(['See [S5] and also [S9].']);

        $result = $this->runner()->run('system', 'What?', [], 'test-3');

        $this->assertSame('complete', $result->stoppedReason);
        $this->assertSame(['[S5]', '[S9]'], $result->unverifiedMarkers);
    }

    public function test_unknown_tool_and_malformed_arguments_end_gracefully(): void
    {
        MockAIProvider::fake([
            ['__tool_calls' => [['id' => 'c1', 'name' => 'delete_everything', 'arguments' => []]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c2', 'name' => 'search_evidence', 'arguments' => '{broken']], 'content' => ''],
            'I could not use those tools, but here is what I know.',
        ]);

        $result = $this->runner()->run('system', 'What?', [], 'test-4');

        $this->assertSame('complete', $result->stoppedReason);
        $this->assertCount(2, $result->toolTrace);
        $this->assertFalse($result->toolTrace[0]['ok']);
        $this->assertFalse($result->toolTrace[1]['ok']);
        $this->assertSame([], $this->executed);
    }

    public function test_identical_calls_are_capped(): void
    {
        $same = ['query' => 'entry'];
        MockAIProvider::fake([
            ['__tool_calls' => [['id' => 'c1', 'name' => 'search_evidence', 'arguments' => $same]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c2', 'name' => 'search_evidence', 'arguments' => $same]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c3', 'name' => 'search_evidence', 'arguments' => $same]], 'content' => ''],
            'Done after the duplicate was rejected.',
        ]);

        $result = $this->runner()->run('system', 'What?', [], 'test-5');

        $this->assertSame('complete', $result->stoppedReason);
        $this->assertCount(3, $result->toolTrace);
        $this->assertTrue($result->toolTrace[0]['ok']);
        $this->assertTrue($result->toolTrace[1]['ok']);
        $this->assertFalse($result->toolTrace[2]['ok']);
        $this->assertCount(2, $this->executed);
    }

    public function test_tool_budget_triggers_final_text_attempt(): void
    {
        $script = [];
        for ($i = 1; $i <= 7; $i++) {
            $script[] = ['__tool_calls' => [['id' => "c{$i}", 'name' => 'search_evidence', 'arguments' => ['query' => "q{$i}"]]], 'content' => ''];
        }
        $script[] = 'Summary from gathered evidence.';
        MockAIProvider::fake($script);

        $result = $this->runner()->run('system', 'What?', [], 'test-6');

        $this->assertSame('tool_budget', $result->stoppedReason);
        $this->assertCount(6, $result->toolTrace);
        $this->assertStringContainsString('Summary from gathered evidence.', $result->answer);
        // The final attempt is text-only: no tools attached.
        $this->assertNull(end(MockAIProvider::$requests)->tools);
    }

    public function test_token_budget_stops_before_any_provider_call(): void
    {
        MockAIProvider::fake(['unused']);

        $result = $this->runner(['tokenBudget' => 5])->run('system', 'What?', [], 'test-7');

        $this->assertSame('token_budget', $result->stoppedReason);
        $this->assertSame([], MockAIProvider::$requests);
    }

    public function test_time_budget_stops_immediately(): void
    {
        MockAIProvider::fake(['unused']);

        $result = $this->runner(['turnSeconds' => 0])->run('system', 'What?', [], 'test-8');

        $this->assertSame('time_budget', $result->stoppedReason);
        $this->assertSame([], MockAIProvider::$requests);
    }

    public function test_provider_failure_returns_partial_result(): void
    {
        MockAIProvider::fake([new AIProviderException('boom')]);

        $result = $this->runner()->run('system', 'What?', [], 'test-9');

        $this->assertSame('provider_error', $result->stoppedReason);
        $this->assertStringContainsString('boom', $result->answer);
    }

    public function test_throwing_executor_is_contained(): void
    {
        $runner = new AgentTurnRunner(
            new MockAIProvider,
            function (): ToolResult {
                throw new \RuntimeException('executor blew up');
            },
            [],
            ['search_evidence'],
        );
        MockAIProvider::fake([
            ['__tool_calls' => [['id' => 'c1', 'name' => 'search_evidence', 'arguments' => ['query' => 'x']]], 'content' => ''],
            'Recovered.',
        ]);

        $result = $runner->run('system', 'What?', [], 'test-10');

        $this->assertSame('complete', $result->stoppedReason);
        $this->assertFalse($result->toolTrace[0]['ok']);
    }

    public function test_third_consecutive_search_is_blocked_until_a_read(): void
    {
        $executed = [];
        $runner = new AgentTurnRunner(
            new MockAIProvider,
            function (string $name, ?array $args) use (&$executed): ToolResult {
                $executed[] = $name;

                return match ($name) {
                    'search_evidence' => ToolResult::ok(['results' => [['source_id' => 5], ['source_id' => 9]]], [5, 9]),
                    'read_evidence' => ToolResult::ok(['source_id' => 5, 'content' => 'evidence'], [5]),
                    default => ToolResult::error('unknown_tool', 'nope'),
                };
            },
            [],
            ['search_evidence', 'read_evidence'],
        );
        MockAIProvider::fake([
            ['__tool_calls' => [['id' => 'c1', 'name' => 'search_evidence', 'arguments' => ['query' => 'one']]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c2', 'name' => 'search_evidence', 'arguments' => ['query' => 'two']]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c3', 'name' => 'search_evidence', 'arguments' => ['query' => 'three']]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c4', 'name' => 'read_evidence', 'arguments' => ['source_id' => 5]]], 'content' => ''],
            'Read [S5] and done.',
        ]);

        $result = $runner->run('system', 'What?', [], 'test-12');

        $this->assertSame('complete', $result->stoppedReason);
        // The blocked search still used budget but never dispatched.
        $this->assertSame(['search_evidence', 'search_evidence', 'read_evidence'], $executed);
        $this->assertCount(4, $result->toolTrace);
        $this->assertFalse($result->toolTrace[2]['ok']);

        $blocked = null;
        foreach (MockAIProvider::$requests as $request) {
            foreach ($request->messages as $message) {
                if (($message['role'] ?? null) === 'tool' && ($message['tool_call_id'] ?? null) === 'c3') {
                    $blocked = (string) $message['content'];
                }
            }
        }

        $this->assertNotNull($blocked);
        $this->assertStringContainsString('read_before_searching', $blocked);
        $this->assertStringContainsString('[S5]', $blocked);
        $this->assertStringContainsString('[S9]', $blocked);
    }

    public function test_empty_searches_do_not_trigger_the_read_block(): void
    {
        $executed = [];
        $runner = new AgentTurnRunner(
            new MockAIProvider,
            function (string $name) use (&$executed): ToolResult {
                $executed[] = $name;

                return ToolResult::ok(['results' => []]);
            },
            [],
            ['search_evidence'],
        );
        MockAIProvider::fake([
            ['__tool_calls' => [['id' => 'c1', 'name' => 'search_evidence', 'arguments' => ['query' => 'one']]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c2', 'name' => 'search_evidence', 'arguments' => ['query' => 'two']]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c3', 'name' => 'search_evidence', 'arguments' => ['query' => 'three']]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c4', 'name' => 'search_evidence', 'arguments' => ['query' => 'four']]], 'content' => ''],
            'Nothing matched anywhere.',
        ]);

        $result = $runner->run('system', 'What?', [], 'test-13');

        $this->assertSame('complete', $result->stoppedReason);
        $this->assertCount(4, $executed);
        $this->assertTrue($result->toolTrace[3]['ok']);
    }

    public function test_a_read_resets_the_search_streak(): void
    {
        $executed = [];
        $runner = new AgentTurnRunner(
            new MockAIProvider,
            function (string $name) use (&$executed): ToolResult {
                $executed[] = $name;

                return $name === 'search_evidence'
                    ? ToolResult::ok(['results' => [['source_id' => 5]]], [5])
                    : ToolResult::ok(['source_id' => 5, 'content' => 'evidence'], [5]);
            },
            [],
            ['search_evidence', 'read_evidence'],
        );
        MockAIProvider::fake([
            ['__tool_calls' => [['id' => 'c1', 'name' => 'search_evidence', 'arguments' => ['query' => 'one']]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c2', 'name' => 'search_evidence', 'arguments' => ['query' => 'two']]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c3', 'name' => 'read_evidence', 'arguments' => ['source_id' => 5]]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c4', 'name' => 'search_evidence', 'arguments' => ['query' => 'three']]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c5', 'name' => 'search_evidence', 'arguments' => ['query' => 'four']]], 'content' => ''],
            'Done.',
        ]);

        $result = $runner->run('system', 'What?', [], 'test-14');

        $this->assertSame('complete', $result->stoppedReason);
        $this->assertCount(5, $executed);
        foreach ($result->toolTrace as $step) {
            $this->assertTrue($step['ok']);
        }
    }

    public function test_missing_snapshot_counts_as_no_progress(): void
    {
        // s, s, arch(unavailable): the snapshot call yields nothing, so
        // the next search is the third non-progress call and is blocked
        // while unread sources remain.
        $executed = [];
        $runner = new AgentTurnRunner(
            new MockAIProvider,
            function (string $name) use (&$executed): ToolResult {
                $executed[] = $name;

                return match ($name) {
                    'search_evidence' => ToolResult::ok(['results' => [['source_id' => 5]]], [5]),
                    'get_architecture' => ToolResult::ok(['available' => false]),
                    'read_evidence' => ToolResult::ok(['source_id' => 5], [5]),
                    default => ToolResult::error('unknown_tool', 'nope'),
                };
            },
            [],
            ['search_evidence', 'read_evidence', 'get_architecture'],
        );
        MockAIProvider::fake([
            ['__tool_calls' => [['id' => 'c1', 'name' => 'search_evidence', 'arguments' => ['query' => 'one']]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c2', 'name' => 'search_evidence', 'arguments' => ['query' => 'two']]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c3', 'name' => 'get_architecture', 'arguments' => []]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c4', 'name' => 'search_evidence', 'arguments' => ['query' => 'three']]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c5', 'name' => 'read_evidence', 'arguments' => ['source_id' => 5]]], 'content' => ''],
            'Done.',
        ]);

        $result = $runner->run('system', 'What?', [], 'test-15');

        $this->assertSame('complete', $result->stoppedReason);
        $this->assertSame(['search_evidence', 'search_evidence', 'get_architecture', 'read_evidence'], $executed);
        $this->assertFalse($result->toolTrace[3]['ok']);
    }

    public function test_examined_snapshot_counts_as_progress(): void
    {
        $executed = [];
        $runner = new AgentTurnRunner(
            new MockAIProvider,
            function (string $name) use (&$executed): ToolResult {
                $executed[] = $name;

                return match ($name) {
                    'search_evidence' => ToolResult::ok(['results' => [['source_id' => 5]]], [5]),
                    'get_architecture' => ToolResult::ok(['available' => true]),
                    default => ToolResult::error('unknown_tool', 'nope'),
                };
            },
            [],
            ['search_evidence', 'get_architecture'],
        );
        MockAIProvider::fake([
            ['__tool_calls' => [['id' => 'c1', 'name' => 'search_evidence', 'arguments' => ['query' => 'one']]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c2', 'name' => 'search_evidence', 'arguments' => ['query' => 'two']]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c3', 'name' => 'get_architecture', 'arguments' => []]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c4', 'name' => 'search_evidence', 'arguments' => ['query' => 'three']]], 'content' => ''],
            'Done.',
        ]);

        $result = $runner->run('system', 'What?', [], 'test-16');

        $this->assertSame('complete', $result->stoppedReason);
        $this->assertCount(4, $executed);
        foreach ($result->toolTrace as $step) {
            $this->assertTrue($step['ok']);
        }
    }

    public function test_search_results_flag_sources_read_this_turn(): void
    {
        $runner = new AgentTurnRunner(
            new MockAIProvider,
            fn (string $name): ToolResult => match ($name) {
                'search_evidence' => ToolResult::ok(['results' => [['source_id' => 5], ['source_id' => 9]]], [5, 9]),
                'read_evidence' => ToolResult::ok(['source_id' => 5], [5]),
                default => ToolResult::error('unknown_tool', 'nope'),
            },
            [],
            ['search_evidence', 'read_evidence'],
        );
        MockAIProvider::fake([
            ['__tool_calls' => [['id' => 'c1', 'name' => 'search_evidence', 'arguments' => ['query' => 'one']]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c2', 'name' => 'read_evidence', 'arguments' => ['source_id' => 5]]], 'content' => ''],
            ['__tool_calls' => [['id' => 'c3', 'name' => 'search_evidence', 'arguments' => ['query' => 'two']]], 'content' => ''],
            'Done.',
        ]);

        $result = $runner->run('system', 'What?', [], 'test-17');

        $this->assertSame('complete', $result->stoppedReason);

        $second = null;
        foreach (MockAIProvider::$requests as $request) {
            foreach ($request->messages as $message) {
                if (($message['role'] ?? null) === 'tool' && ($message['tool_call_id'] ?? null) === 'c3') {
                    $second = json_decode((string) $message['content'], true);
                }
            }
        }

        $this->assertNotNull($second);
        $this->assertTrue($second['results'][0]['already_read']);
        $this->assertFalse($second['results'][1]['already_read']);
    }

    public function test_history_is_bounded(): void
    {
        $history = [];
        for ($i = 0; $i < 10; $i++) {
            $history[] = ['role' => $i % 2 === 0 ? 'user' : 'assistant', 'content' => "turn {$i}"];
        }
        MockAIProvider::fake(['ok']);

        $this->runner()->run('system', 'latest?', $history, 'test-11');

        // System + last 6 history turns + current question.
        $this->assertCount(8, MockAIProvider::$requests[0]->messages);
        $this->assertSame('turn 4', MockAIProvider::$requests[0]->messages[1]['content']);
    }
}
