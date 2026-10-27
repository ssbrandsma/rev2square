<?php

namespace Tests\Feature;

use App\Services\AI\AIProviderException;
use App\Services\AI\AIRateLimitedException;
use App\Services\AI\AIRequest;
use App\Services\AI\AITimeoutException;
use App\Services\AI\MetaAIProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Contract tests for the Meta Model API provider.
 *
 * Endpoint, auth, model and payload facts verified against
 * https://dev.meta.ai/docs (base URL https://api.meta.ai/v1,
 * POST /v1/chat/completions, Bearer MODEL_API_KEY, muse-spark-1.3,
 * response_format json_schema, choices[0].message.content).
 */
class MetaAIProviderTest extends TestCase
{
    private function provider(array $overrides = []): MetaAIProvider
    {
        return new MetaAIProvider(
            baseUrl: $overrides['base_url'] ?? 'https://api.meta.ai/v1',
            apiKey: array_key_exists('api_key', $overrides) ? $overrides['api_key'] : 'test-key-value',
            model: $overrides['model'] ?? 'muse-spark-1.3',
            timeout: 30,
            maxCompletionTokens: 16000,
        );
    }

    private function request(): AIRequest
    {
        return new AIRequest(system: 'system-prompt-marker', user: 'user-prompt-marker');
    }

    private function completion(array $overrides = []): array
    {
        return array_merge([
            'id' => 'chatcmpl-abc123',
            'object' => 'chat.completion',
            'created' => 1714502400,
            'model' => 'muse-spark-1.3',
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => '{"ok":true}'],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => ['prompt_tokens' => 25, 'completion_tokens' => 19, 'total_tokens' => 44],
        ], $overrides);
    }

    public function test_posts_to_verified_chat_completions_endpoint(): void
    {
        Http::fake(['*' => Http::response($this->completion(), 200)]);

        $this->provider()->complete($this->request());

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.meta.ai/v1/chat/completions'
                && $request->header('Authorization') === ['Bearer test-key-value']
                && $request['model'] === 'muse-spark-1.3'
                && $request['messages'][0] === ['role' => 'system', 'content' => 'system-prompt-marker']
                && $request['messages'][1] === ['role' => 'user', 'content' => 'user-prompt-marker']
                && $request['max_completion_tokens'] === 16000;
        });
    }

    public function test_sends_strict_json_schema_response_format(): void
    {
        Http::fake(['*' => Http::response($this->completion(), 200)]);

        $schema = ['type' => 'object', 'additionalProperties' => false, 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']];
        $this->provider()->complete(new AIRequest(system: 's', user: 'u', jsonSchema: $schema));

        Http::assertSent(function (Request $request) use ($schema) {
            return $request['response_format'] === [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'revival_report', 'strict' => true, 'schema' => $schema],
            ];
        });
    }

    public function test_parses_successful_completion(): void
    {
        Http::fake(['*' => Http::response($this->completion(), 200)]);

        $response = $this->provider()->complete($this->request());

        $this->assertSame('{"ok":true}', $response->content);
        $this->assertSame('muse-spark-1.3', $response->model);
        $this->assertSame('chatcmpl-abc123', $response->requestId);
        $this->assertSame(25, $response->inputTokens);
        $this->assertSame(19, $response->outputTokens);
        $this->assertSame('stop', $response->finishReason);
    }

    public function test_empty_body_404_is_reported_as_endpoint_error(): void
    {
        // Per the error-handling docs, an unknown path returns HTTP 404
        // with an EMPTY body (no JSON error envelope).
        Http::fake(['*' => Http::response('', 404)]);

        try {
            $this->provider()->complete($this->request());
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $e) {
            $this->assertStringContainsString('endpoint not found', $e->getMessage());
            $this->assertStringContainsString('META_API_BASE', $e->getMessage());
            $this->assertStringContainsString('chat/completions', $e->getMessage());
        }
    }

    public function test_model_not_found_404_names_the_model(): void
    {
        Http::fake(['*' => Http::response([
            'error' => [
                'message' => 'The model `muse-spark-1.3` does not exist or is not available',
                'type' => 'invalid_request_error',
                'param' => null,
                'code' => 'model_not_found',
            ],
        ], 404)]);

        try {
            $this->provider()->complete($this->request());
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $e) {
            $this->assertStringContainsString('muse-spark-1.3', $e->getMessage());
            $this->assertStringContainsString('model_not_found', $e->getMessage());
            $this->assertStringContainsString('META_MODEL', $e->getMessage());
        }
    }

    public function test_401_is_reported_as_authentication_failure(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['message' => 'Unauthorized', 'type' => 'authentication_error', 'param' => null, 'code' => 'invalid_api_key'],
        ], 401)]);

        try {
            $this->provider()->complete($this->request());
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $e) {
            $this->assertStringContainsString('401', $e->getMessage());
            $this->assertStringContainsString('MODEL_API_KEY', $e->getMessage());
        }
    }

    public function test_403_is_reported_as_permission_failure(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['message' => 'Forbidden', 'type' => 'authentication_error', 'param' => null, 'code' => null],
        ], 403)]);

        try {
            $this->provider()->complete($this->request());
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $e) {
            $this->assertStringContainsString('403', $e->getMessage());
            $this->assertStringContainsString('muse-spark-1.3', $e->getMessage());
        }
    }

    public function test_402_is_reported_as_billing_issue(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['message' => 'Billing account issue.', 'type' => 'billing_error', 'param' => null, 'code' => 'billing_not_configured'],
        ], 402)]);

        try {
            $this->provider()->complete($this->request());
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $e) {
            $this->assertStringContainsString('402', $e->getMessage());
            $this->assertStringContainsString('billing', strtolower($e->getMessage()));
        }
    }

    public function test_429_throws_rate_limited_with_reset(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['message' => 'Rate limit.', 'type' => 'rate_limit_error', 'param' => null, 'code' => 'rate_limit_exceeded'],
        ], 429, ['retry-after' => '45'])]);

        try {
            $this->provider()->complete($this->request());
            $this->fail('Expected AIRateLimitedException.');
        } catch (AIRateLimitedException $e) {
            $this->assertNotNull($e->resetsAt);
        }
    }

    public function test_400_includes_server_detail(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['message' => '`top_p`: The number must be `<= 1.0`.', 'type' => 'invalid_request_error', 'param' => 'top_p', 'code' => null],
        ], 400)]);

        try {
            $this->provider()->complete($this->request());
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $e) {
            $this->assertStringContainsString('top_p', $e->getMessage());
        }
    }

    public function test_retryable_server_errors_bubble_as_timeout(): void
    {
        // 503 (e.g. inconclusive model-registry lookup) must be retried
        // via the job backoff path, never recorded as a missing model.
        Http::fake(['*' => Http::response([
            'error' => ['message' => 'Backend unavailable.', 'type' => 'server_error', 'param' => null, 'code' => 'backend_unavailable'],
        ], 503, ['retry-after' => '60'])]);

        $this->expectException(AITimeoutException::class);
        $this->provider()->complete($this->request());
    }

    public function test_malformed_success_responses_are_rejected(): void
    {
        Http::fake(['*' => Http::response(['unexpected' => 'shape'], 200)]);

        try {
            $this->provider()->complete($this->request());
            $this->fail('Expected AIProviderException for missing choices.');
        } catch (AIProviderException $e) {
            $this->assertStringContainsString('choices', $e->getMessage());
        }

        Http::fake(['*' => Http::response($this->completion([
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => ''], 'finish_reason' => 'stop']],
        ]), 200)]);

        $this->expectException(AIProviderException::class);
        $this->provider()->complete($this->request());
    }

    public function test_missing_api_key_fails_without_http_call(): void
    {
        Http::fake();

        try {
            $this->provider(['api_key' => null])->complete($this->request());
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $e) {
            $this->assertStringContainsString('MODEL_API_KEY', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_base_url_with_embedded_endpoint_path_is_rejected(): void
    {
        // A common misconfiguration: pasting the full request URL into
        // META_API_BASE produces POST .../chat/completions/chat/completions
        // (empty-body 404). Fail fast with config guidance instead.
        Http::fake();

        try {
            $this->provider(['base_url' => 'https://api.meta.ai/v1/chat/completions'])->complete($this->request());
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $e) {
            $this->assertStringContainsString('META_API_BASE', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_check_command_validates_config_without_calling(): void
    {
        config(['ai.meta.api_key' => 'LLM_fake_test_key']);

        $this->artisan('ai:check-meta', ['--no-call' => true])
            ->expectsOutputToContain('https://api.meta.ai/v1')
            ->expectsOutputToContain('muse-spark-1.3')
            ->doesntExpectOutputToContain('LLM_fake_test_key')
            ->assertOk();

        Http::assertNothingSent();
    }

    public function test_check_command_reports_live_failures_safely(): void
    {
        config(['ai.meta.api_key' => 'LLM_fake_test_key']);
        Http::fake(['*' => Http::response('', 404)]);

        $this->artisan('ai:check-meta')
            ->expectsOutputToContain('endpoint not found')
            ->doesntExpectOutputToContain('LLM_fake_test_key')
            ->assertFailed();
    }

    public function test_never_logs_api_key_or_prompt_content(): void
    {
        $logged = [];
        Log::listen(function ($event) use (&$logged) {
            $logged[] = json_encode([$event->message, $event->context]);
        });
        Http::fake(['*' => Http::response($this->completion(), 200)]);

        $this->provider()->complete($this->request());

        $this->assertNotSame([], $logged);
        $blob = implode("\n", $logged);
        $this->assertStringNotContainsString('test-key-value', $blob);
        $this->assertStringNotContainsString('system-prompt-marker', $blob);
        $this->assertStringNotContainsString('user-prompt-marker', $blob);
        $this->assertStringNotContainsString('Bearer', $blob);
    }

    public function test_failed_request_logs_one_structured_entry(): void
    {
        $records = [];
        Log::listen(function ($event) use (&$records) {
            $records[] = $event;
        });
        Http::fake(['*' => Http::response([
            'internal_debug_blob' => 'raw-body-marker-xyz',
            'error' => [
                'message' => 'The model `muse-spark-1.3` does not exist or is not available',
                'type' => 'invalid_request_error',
                'param' => null,
                'code' => 'model_not_found',
            ],
        ], 404, ['x-request-id' => 'req-123'])]);

        try {
            $this->provider()->complete(new AIRequest(
                system: 'system-prompt-marker',
                user: 'user-prompt-marker',
                correlationId: 'analysis-run-7',
            ));
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException) {
        }

        $this->assertCount(1, $records);
        $this->assertSame('warning', $records[0]->level);

        $context = $records[0]->context;
        $this->assertSame('meta', $context['provider']);
        $this->assertSame('muse-spark-1.3', $context['model']);
        $this->assertSame('https://api.meta.ai/v1/chat/completions', $context['endpoint']);
        $this->assertSame(404, $context['status']);
        $this->assertSame('model_not_found', $context['error_code']);
        $this->assertSame('invalid_request_error', $context['error_type']);
        $this->assertIsInt($context['duration_ms']);
        $this->assertSame('analysis-run-7', $context['correlation_id']);
        $this->assertFalse($context['retryable']);
        $this->assertSame('req-123', $context['request_id']);
        $this->assertSame(AIProviderException::class, $context['exception_class']);
        $this->assertStringContainsString('muse-spark-1.3', $context['error']);

        $blob = (string) json_encode($context);

        foreach (['test-key-value', 'system-prompt-marker', 'user-prompt-marker', 'Bearer', 'raw-body-marker-xyz'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $blob);
        }
    }

    public function test_retryable_failure_logs_retryable_true(): void
    {
        $records = [];
        Log::listen(function ($event) use (&$records) {
            $records[] = $event;
        });
        Http::fake(['*' => Http::response([
            'error' => ['message' => 'Backend unavailable.', 'type' => 'server_error', 'param' => null, 'code' => 'backend_unavailable'],
        ], 503, ['retry-after' => '60'])]);

        try {
            $this->provider()->complete($this->request());
            $this->fail('Expected AITimeoutException.');
        } catch (AITimeoutException) {
        }

        $this->assertCount(1, $records);
        $this->assertTrue($records[0]->context['retryable']);
        $this->assertSame(503, $records[0]->context['status']);
        $this->assertSame('backend_unavailable', $records[0]->context['error_code']);
        $this->assertSame(AITimeoutException::class, $records[0]->context['exception_class']);
    }

    public function test_network_failure_logs_exception_class_and_sanitized_description(): void
    {
        $records = [];
        Log::listen(function ($event) use (&$records) {
            $records[] = $event;
        });
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: timeout [Authorization: Bearer secret-token-xyz]');
        });

        try {
            $this->provider()->complete($this->request());
            $this->fail('Expected AITimeoutException.');
        } catch (AITimeoutException) {
        }

        $this->assertCount(1, $records);
        $context = $records[0]->context;
        $this->assertSame('warning', $records[0]->level);
        $this->assertNull($context['status']);
        $this->assertNull($context['error_code']);
        $this->assertTrue($context['retryable']);
        $this->assertSame(ConnectionException::class, $context['exception_class']);
        $this->assertStringContainsString('Bearer [REDACTED]', $context['error']);
        $this->assertStringNotContainsString('secret-token-xyz', (string) json_encode($context));
    }

    public function test_success_logs_single_entry_with_endpoint(): void
    {
        $records = [];
        Log::listen(function ($event) use (&$records) {
            $records[] = $event;
        });
        Http::fake(['*' => Http::response($this->completion(), 200)]);

        $this->provider()->complete($this->request());

        $this->assertCount(1, $records);
        $this->assertSame('info', $records[0]->level);
        $this->assertSame('https://api.meta.ai/v1/chat/completions', $records[0]->context['endpoint']);
        $this->assertSame('chatcmpl-abc123', $records[0]->context['request_id']);
        $this->assertArrayNotHasKey('error_code', $records[0]->context);
    }

    public function test_endpoint_url_is_sanitized_in_logs(): void
    {
        $records = [];
        Log::listen(function ($event) use (&$records) {
            $records[] = $event;
        });
        Http::fake(['*' => Http::response($this->completion(), 200)]);

        $this->provider(['base_url' => 'https://user:pass@api.meta.ai/v1?token=secret-key-xyz'])
            ->complete($this->request());

        $this->assertCount(1, $records);
        $this->assertSame('https://api.meta.ai/v1/chat/completions', $records[0]->context['endpoint']);

        $blob = (string) json_encode($records[0]->context);
        $this->assertStringNotContainsString('user:pass', $blob);
        $this->assertStringNotContainsString('secret-key-xyz', $blob);
    }

    public function test_prompt_echo_in_server_error_is_redacted_from_logs(): void
    {
        $records = [];
        Log::listen(function ($event) use (&$records) {
            $records[] = $event;
        });
        Http::fake(['*' => Http::response([
            'error' => ['message' => 'Rejected input: user-prompt-marker', 'type' => 'invalid_request_error', 'param' => null, 'code' => null],
        ], 400)]);

        try {
            $this->provider()->complete($this->request());
            $this->fail('Expected AIProviderException.');
        } catch (AIProviderException $e) {
            // Exception behavior preserved: the run-visible message is unchanged.
            $this->assertStringContainsString('user-prompt-marker', $e->getMessage());
        }

        $this->assertCount(1, $records);
        $this->assertStringContainsString('[PROMPT REDACTED]', $records[0]->context['error']);
        $this->assertStringNotContainsString('user-prompt-marker', (string) json_encode($records[0]->context));
    }
}
