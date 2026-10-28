<?php

namespace Tests\Feature;

use App\Services\AI\AIProviderException;
use App\Services\AI\AIRequest;
use App\Services\AI\MetaAIProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Native tool-calling contract for the Meta Model API provider.
 *
 * Protocol shape per the Chat Completions tool-calling docs (tools /
 * tool_calls / tool messages). Covered by mocked HTTP only — NOT
 * verified against the live API in this delivery.
 */
class MetaAIToolCallingTest extends TestCase
{
    private function provider(): MetaAIProvider
    {
        return new MetaAIProvider(
            baseUrl: 'https://api.meta.ai/v1',
            apiKey: 'test-key-value',
            model: 'muse-spark-1.3',
            timeout: 30,
            maxCompletionTokens: 16000,
        );
    }

    /** @return list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}> */
    private function tools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_evidence',
                    'description' => 'Search evidence.',
                    'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
                ],
            ],
        ];
    }

    private function completion(array $message, string $finishReason = 'tool_calls'): array
    {
        return [
            'id' => 'chatcmpl-tools1',
            'model' => 'muse-spark-1.3',
            'choices' => [
                ['index' => 0, 'message' => $message, 'finish_reason' => $finishReason],
            ],
            'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 12, 'total_tokens' => 52],
        ];
    }

    public function test_tools_and_history_are_sent(): void
    {
        Http::fake(['*' => Http::response($this->completion(['role' => 'assistant', 'content' => 'hi']), 200)]);

        $messages = [
            ['role' => 'system', 'content' => 'sys'],
            ['role' => 'user', 'content' => 'q'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'call-1', 'type' => 'function', 'function' => ['name' => 'search_evidence', 'arguments' => '{"query":"x"}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'call-1', 'name' => 'search_evidence', 'content' => '{"results":[]}'],
        ];

        $this->provider()->complete(new AIRequest(system: '', user: 'q', messages: $messages, tools: $this->tools()));

        Http::assertSent(function (Request $request) {
            return $request['tools'][0]['function']['name'] === 'search_evidence'
                && $request['parallel_tool_calls'] === false
                && count($request['messages']) === 4
                && $request['messages'][3] === ['role' => 'tool', 'tool_call_id' => 'call-1', 'name' => 'search_evidence', 'content' => '{"results":[]}'];
        });
    }

    public function test_tool_calls_are_parsed_with_empty_content(): void
    {
        $message = [
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [
                ['id' => 'call-9', 'type' => 'function', 'function' => ['name' => 'read_evidence', 'arguments' => '{"source_id":7}']],
            ],
        ];
        Http::fake(['*' => Http::response($this->completion($message), 200)]);

        $response = $this->provider()->complete(new AIRequest(system: '', user: 'q', tools: $this->tools()));

        $this->assertSame('', $response->content);
        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('call-9', $response->toolCalls[0]->id);
        $this->assertSame('read_evidence', $response->toolCalls[0]->name);
        $this->assertSame(['source_id' => 7], $response->toolCalls[0]->arguments());
    }

    public function test_malformed_tool_calls_are_rejected(): void
    {
        Http::fake(['*' => Http::response($this->completion(['role' => 'assistant', 'tool_calls' => [['id' => '', 'function' => []]]]), 200)]);

        try {
            $this->provider()->complete(new AIRequest(system: '', user: 'q', tools: $this->tools()));
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $e) {
            $this->assertStringContainsString('tool_calls', $e->getMessage());
        }
    }

    public function test_tools_cannot_combine_with_json_schema(): void
    {
        $this->expectException(AIProviderException::class);

        $this->provider()->complete(new AIRequest(system: 's', user: 'u', jsonSchema: ['type' => 'object'], tools: $this->tools()));
    }

    public function test_plain_text_answers_still_parse(): void
    {
        Http::fake(['*' => Http::response($this->completion(['role' => 'assistant', 'content' => 'plain answer'], 'stop'), 200)]);

        $response = $this->provider()->complete(new AIRequest(system: '', user: 'q', tools: $this->tools()));

        $this->assertSame('plain answer', $response->content);
        $this->assertNull($response->toolCalls);
    }
}
