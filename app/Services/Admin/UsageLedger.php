<?php

namespace App\Services\Admin;

use App\Models\AiUsageEntry;
use Illuminate\Support\Facades\DB;

/**
 * Auditable per-attempt usage ledger. One row per provider attempt;
 * retries and multi-call turns produce one row each, keyed by
 * idempotency_key so job retries never duplicate.
 */
final class UsageLedger
{
    /**
     * Reserve the next attempt sequence for a correlation id and build
     * its idempotency key. Concurrent same-correlation callers retry on
     * the unique constraint.
     *
     * @return array{seq: int, key: string}
     */
    public static function claimAttempt(string $correlationId, string $provider): array
    {
        $seq = (int) (AiUsageEntry::where('correlation_id', $correlationId)->max('attempt_seq') ?? 0) + 1;

        return ['seq' => $seq, 'key' => $correlationId.':'.$provider.':'.$seq];
    }

    /**
     * @param  array{user_id?: ?int, project_id?: ?int, feature?: string, operation?: string}  $usage
     */
    public static function record(
        string $idempotencyKey,
        int $seq,
        array $usage,
        string $provider,
        ?string $model,
        string $status,
        ?string $correlationId,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $cachedTokens = null,
        ?int $reasoningTokens = null,
        ?int $durationMs = null,
        ?string $errorCode = null,
    ): AiUsageEntry {
        [$micros, $version] = CostEstimates::for($inputTokens, $outputTokens);

        return DB::transaction(function () use (
            $idempotencyKey, $seq, $usage, $provider, $model, $status,
            $correlationId, $inputTokens, $outputTokens, $cachedTokens,
            $reasoningTokens, $durationMs, $errorCode, $micros, $version
        ): AiUsageEntry {
            $existing = AiUsageEntry::where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }

            return AiUsageEntry::create([
                'idempotency_key' => $idempotencyKey,
                'user_id' => $usage['user_id'] ?? null,
                'project_id' => $usage['project_id'] ?? null,
                'feature' => substr((string) ($usage['feature'] ?? 'unknown'), 0, 32),
                'operation' => substr((string) ($usage['operation'] ?? 'unknown'), 0, 96),
                'attempt_seq' => $seq,
                'provider' => $provider,
                'model' => $model !== null ? substr($model, 0, 64) : null,
                'status' => $status,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cached_tokens' => $cachedTokens,
                'reasoning_tokens' => $reasoningTokens,
                'duration_ms' => $durationMs,
                'correlation_id' => $correlationId !== null ? substr($correlationId, 0, 96) : null,
                'estimated_cost_micros' => $micros,
                'pricing_version' => $version,
                'error_code' => $errorCode !== null ? substr($errorCode, 0, 64) : null,
            ]);
        });
    }
}
