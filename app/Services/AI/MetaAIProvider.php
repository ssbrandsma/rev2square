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
 * POST {base}/chat/completions, Bearer [REDACTED] auth,
 * response_format {type: json_schema}, content at choices[0].message.content.
 * Exactly one structured entry per request (info on success, warning on
 * failure). Only non-sensitive metadata is logged — never keys, prompts,
 * completions or response bodies.
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

        $this->assertBaseUrl();

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
                ->acceptJson()
                ->withOptions(['allow_redirects' => false])
                ->timeout($this->timeout)
                ->post($this->baseUrl.'/chat/completions', $payload);
        } catch (ConnectionException $e) {
            $this->logFailure(null, (int) ((microtime(true) - $started) * 1000), true, $request, $e);

            throw new AITimeoutException('Meta Model API request timed out.', 0, $e);
        }

        $durationMs = (int) ((microtime(true) - $started) * 1000);

        // Exactly one log entry per outcome: failures log here (never in the
        // service or job), successes below. Exceptions pass through unchanged.
        try {
            $result = $this->toResponse($response, $durationMs);
        } catch (AIRateLimitedException|AITimeoutException $e) {
            $this->logFailure($response, $durationMs, true, $request, $e);

            throw $e;
        } catch (AIProviderException $e) {
            $this->logFailure($response, $durationMs, false, $request, $e);

            throw $e;
        }

        $this->logSuccess($response, $durationMs, $request);

        return $result;
    }

    /**
     * Fail fast on the most common endpoint misconfiguration: pasting the
     * full request URL into META_API_BASE would POST to
     * .../chat/completions/chat/completions (empty-body 404).
     */
    private function assertBaseUrl(): void
    {
        foreach (['/chat/completions', '/responses', '/messages'] as $suffix) {
            if (str_contains($this->baseUrl, $suffix)) {
                throw new AIProviderException(
                    "Meta Model API base URL is misconfigured (META_API_BASE must be the API root, e.g. https://api.meta.ai/v1, without '{$suffix}')."
                );
            }
        }
    }

    private function toResponse(Response $response, int $durationMs): AIResponse
    {
        $status = $response->status();

        if ($status >= 300 && $status < 400) {
            throw new AIProviderException('Meta Model API redirect rejected.');
        }

        if ($status === 401) {
            throw new AIProviderException(
                'Meta Model API authentication failed (HTTP 401'.$this->errorDetail($response).'). Check MODEL_API_KEY (starts with LLM_, set in the server environment).'
            );
        }

        if ($status === 403) {
            throw new AIProviderException(
                "Meta Model API key lacks access to model '{$this->model}' (HTTP 403".$this->errorDetail($response).'). Verify key permissions in the Model API dashboard.'
            );
        }

        if ($status === 402) {
            throw new AIProviderException(
                'Meta Model API billing issue (HTTP 402'.$this->errorDetail($response).'). Check account balance and payment method in the Model API dashboard.'
            );
        }

        if ($status === 404) {
            throw new AIProviderException($this->notFoundMessage($response));
        }

        if ($status === 429) {
            throw new AIRateLimitedException(
                'Meta Model API rate limit exceeded.',
                $this->headerInt($response, 'retry-after', fn ($v) => time() + $v)
                    ?? $this->headerInt($response, 'x-ratelimit-reset')
            );
        }

        if ($status === 408 || $status === 503 || $status === 504 || $status === 529) {
            // Retryable via the job backoff path. Note: a 503 during the
            // model-registry lookup means "unknown whether the model
            // exists", never "model missing" — it must be retried.
            throw new AITimeoutException(
                "Meta Model API temporarily unavailable (HTTP {$status}{$this->errorDetail($response)}). Retryable."
            );
        }

        if ($status === 400 || $status === 413 || $status === 422) {
            $message = $response->json('error.message') ?? 'Request rejected by the Meta Model API.';
            $param = $response->json('error.param');
            $suffix = is_string($param) && $param !== '' ? " (param: {$param})" : '';

            throw new AIProviderException('Meta Model API request invalid: '.substr((string) $message, 0, 300).$suffix);
        }

        if (! $response->successful()) {
            throw new AIProviderException("Meta Model API request failed with status {$status}{$this->errorDetail($response)}.");
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new AIProviderException('Meta Model API returned a malformed response (invalid JSON body).');
        }

        $choices = $data['choices'] ?? null;

        if (! is_array($choices) || $choices === [] || ! array_key_exists('message', $choices[0] ?? [])) {
            throw new AIProviderException('Meta Model API returned a malformed response (missing choices[0].message).');
        }

        $content = $choices[0]['message']['content'] ?? null;

        if (! is_string($content) || $content === '') {
            throw new AIProviderException(
                'Meta Model API returned an empty completion (finish_reason='.($choices[0]['finish_reason'] ?? 'unknown').').'
            );
        }

        $usage = $data['usage'] ?? [];

        return new AIResponse(
            content: $content,
            model: $data['model'] ?? $this->model,
            requestId: $data['id'] ?? $response->header('x-request-id'),
            inputTokens: isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            outputTokens: isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
            durationMs: $durationMs,
            finishReason: $choices[0]['finish_reason'] ?? null,
        );
    }

    /**
     * Per the error-handling docs, an unknown path returns HTTP 404 with an
     * EMPTY body (no JSON envelope), while an unknown model returns 404 with
     * a JSON error carrying code=model_not_found. The two causes need
     * different operator actions, so they get different messages.
     */
    private function notFoundMessage(Response $response): string
    {
        if (trim($response->body()) === '') {
            return "Meta Model API endpoint not found (HTTP 404, empty error body). Check META_API_BASE; expected POST {$this->baseUrl}/chat/completions.";
        }

        if ($response->json('error.code') === 'model_not_found') {
            return "Meta Model API model '{$this->model}' not found or not available to this key (HTTP 404, model_not_found). Verify META_MODEL and key access in the Model API dashboard.";
        }

        return "Meta Model API resource not found (HTTP 404{$this->errorDetail($response)}).";
    }

    /** Short stable error suffix (", code=X" or ": message") for 4xx/5xx detail. */
    private function errorDetail(Response $response): string
    {
        $code = $response->json('error.code');

        if (is_string($code) && $code !== '') {
            return ", code={$code}";
        }

        $message = $response->json('error.message');

        if (is_string($message) && trim($message) !== '') {
            return ': '.substr(trim($message), 0, 120);
        }

        return '';
    }

    private function headerInt(Response $response, string $name, ?callable $map = null): ?int
    {
        $value = $response->header($name);

        if (! is_numeric($value)) {
            return null;
        }

        return $map !== null ? $map((int) $value) : (int) $value;
    }

    private function logSuccess(Response $response, int $durationMs, AIRequest $request): void
    {
        Log::info('Meta Model API call completed.', [
            'provider' => 'meta',
            'model' => $this->model,
            'endpoint' => $this->sanitizedEndpoint(),
            'status' => $response->status(),
            'duration_ms' => $durationMs,
            'correlation_id' => $request->correlationId,
            'request_id' => $response->header('x-request-id') ?: $response->json('id'),
            'usage' => $response->json('usage'),
        ]);
    }

    /**
     * The single structured entry for a failed request. Metadata only: API
     * keys, Authorization headers, prompts, completions and raw response
     * bodies are never logged.
     */
    private function logFailure(?Response $response, int $durationMs, bool $retryable, AIRequest $request, \Throwable $cause): void
    {
        Log::warning('Meta Model API call failed.', [
            'provider' => 'meta',
            'model' => $this->model,
            'endpoint' => $this->sanitizedEndpoint(),
            'status' => $response?->status(),
            'error_code' => $response?->json('error.code'),
            'error_type' => $response?->json('error.type'),
            'duration_ms' => $durationMs,
            'correlation_id' => $request->correlationId,
            'retryable' => $retryable,
            'request_id' => $response?->header('x-request-id') ?: $response?->json('id'),
            'exception_class' => $cause::class,
            'error' => $this->sanitizeDescription($cause->getMessage(), $request),
        ]);
    }

    /** Endpoint URL with any userinfo, query string or fragment removed. */
    private function sanitizedEndpoint(): string
    {
        $base = strtok($this->baseUrl, '?#');
        $base = (string) preg_replace('#^(https?://)[^/@]+@#i', '$1', $base);

        return rtrim($base, '/').'/chat/completions';
    }

    /**
     * Free-text error detail safe for logs: credential patterns redacted,
     * full prompt echoes removed, length capped.
     */
    private function sanitizeDescription(string $message, ?AIRequest $request = null): string
    {
        $message = (string) preg_replace('/Bearer\s+[^\s"\']+/i', 'Bearer [REDACTED]', $message);
        $message = (string) preg_replace('/LLM_[A-Za-z0-9_]+/', 'LLM_[REDACTED]', $message);

        if ($request !== null) {
            foreach ([$request->system, $request->user] as $prompt) {
                if ($prompt !== '' && str_contains($message, $prompt)) {
                    $message = str_replace($prompt, '[PROMPT REDACTED]', $message);
                }
            }
        }

        return substr(trim($message), 0, 300);
    }
}
