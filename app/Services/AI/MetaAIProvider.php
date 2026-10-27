<?php

namespace App\Services\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Meta Model API provider (Chat Completions, OpenAI-compatible).
 *
 * Request/response facts verified against https://dev.meta.ai/docs:
 * POST {base}/chat/completions, Bearer MODEL_API_KEY auth,
 * response_format {type: json_schema}, content at choices[0].message.content.
 * Only non-sensitive telemetry is logged — never prompts or completions.
 */
class MetaAIProvider implements AIProviderInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly int $timeout,
        private readonly int $maxCompletionTokens,
    ) {}

    public static function fromConfig(): self
    {
        $config = config('ai.meta');

        return new self(
            rtrim($config['base_url'], '/'),
            $config['api_key'] ?: null,
            $config['model'],
            (int) $config['timeout'],
            (int) $config['max_completion_tokens'],
        );
    }

    public function name(): string
    {
        return 'meta';
    }

    public function complete(AIRequest $request): AIResponse
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new AIProviderException('Meta Model API key is not configured. Set MODEL_API_KEY.');
        }

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $request->system],
                ['role' => 'user', 'content' => $request->user],
            ],
            'max_completion_tokens' => $request->maxCompletionTokens ?? $this->maxCompletionTokens,
        ];

        if ($request->jsonSchema !== null) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'revival_report',
                    'strict' => true,
                    'schema' => $request->jsonSchema,
                ],
            ];
        }

        $started = microtime(true);

        try {
            $response = Http::withToken($this->apiKey)
                ->withOptions(['allow_redirects' => false])
                ->timeout($this->timeout)
                ->post($this->baseUrl.'/chat/completions', $payload);
        } catch (ConnectionException $e) {
            throw new AITimeoutException('Meta Model API request timed out.', 0, $e);
        }

        $durationMs = (int) ((microtime(true) - $started) * 1000);

        $this->logTelemetry($response, $durationMs);

        return $this->toResponse($response, $durationMs);
    }

    private function toResponse(Response $response, int $durationMs): AIResponse
    {
        $status = $response->status();

        if ($status >= 300 && $status < 400) {
            throw new AIProviderException('Meta Model API redirect rejected.');
        }

        if ($status === 401 || $status === 403) {
            throw new AIProviderException('Meta Model API rejected the credentials (HTTP '.$status.').');
        }

        if ($status === 404) {
            throw new AIProviderException('Meta Model API model or endpoint not found (HTTP 404).');
        }

        if ($status === 429) {
            throw new AIRateLimitedException(
                'Meta Model API rate limit exceeded.',
                $this->headerInt($response, 'retry-after', fn ($v) => time() + $v)
                    ?? $this->headerInt($response, 'x-ratelimit-reset')
            );
        }

        if ($status === 400) {
            $message = $response->json('error.message') ?? 'Request rejected by the Meta Model API.';

            throw new AIProviderException('Meta Model API request invalid: '.substr((string) $message, 0, 300));
        }

        if (! $response->successful()) {
            throw new AIProviderException("Meta Model API request failed with status {$status}.");
        }

        $data = $response->json();
        $content = $data['choices'][0]['message']['content'] ?? null;

        if (! is_string($content) || $content === '') {
            throw new AIProviderException('Meta Model API returned an empty completion.');
        }

        $usage = $data['usage'] ?? [];

        return new AIResponse(
            content: $content,
            model: $data['model'] ?? $this->model,
            requestId: $data['id'] ?? $response->header('x-request-id'),
            inputTokens: isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            outputTokens: isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
            durationMs: $durationMs,
            finishReason: $data['choices'][0]['finish_reason'] ?? null,
        );
    }

    private function headerInt(Response $response, string $name, ?callable $map = null): ?int
    {
        $value = $response->header($name);

        if (! is_numeric($value)) {
            return null;
        }

        return $map !== null ? $map((int) $value) : (int) $value;
    }

    private function logTelemetry(Response $response, int $durationMs): void
    {
        Log::info('Meta Model API call completed.', [
            'provider' => 'meta',
            'model' => $this->model,
            'status' => $response->status(),
            'duration_ms' => $durationMs,
            'request_id' => $response->json('id'),
            'usage' => $response->json('usage'),
        ]);
    }
}
