<?php

namespace App\Services\AI;

use App\Models\AiUsageEntry;
use App\Models\User;
use App\Services\Admin\AiBudgets;
use App\Services\Admin\AppSettings;
use App\Services\Admin\UsageLedger;
use Illuminate\Database\QueryException;

/**
 * Metering + enforcement wrapper around the real provider. Every
 * complete() call: rechecks suspension/restriction/budgets against
 * fresh database state, reserves allowance atomically, executes, then
 * records one ledger row and reconciles the reservation. Retries and
 * multi-call turns record one row per attempt — never double-counted,
 * never silently merged.
 */
class MeteredAIProvider implements AIProviderInterface
{
    public function __construct(private readonly AIProviderInterface $inner) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function complete(AIRequest $request): AIResponse
    {
        $usage = $request->usage ?? [];
        $feature = (string) ($usage['feature'] ?? 'unknown');
        $isConnectionTest = $feature === 'connection_test';
        $user = isset($usage['user_id']) ? User::find($usage['user_id']) : null;

        if ($user === null && ! $isConnectionTest && ($usage['user_id'] ?? null) !== null) {
            throw new AiDeniedException('The requesting account no longer exists.', 'suspended');
        }

        $reservation = ['counter_id' => 0, 'estimated_tokens' => 0];
        if ($user !== null) {
            $reservation = AiBudgets::reserve($user, self::estimateTokens($request), $isConnectionTest);
        } elseif (! $isConnectionTest) {
            // Unattributed calls (should not happen for user features):
            // the kill switch still applies; budgets cannot attach.
            if (! AppSettings::get('ai.enabled')) {
                throw new AiDeniedException('AI features are currently disabled by the administrator.', 'ai_disabled');
            }
        }

        $correlation = $request->correlationId ?? 'uncorrelated-'.uniqid();
        $started = microtime(true);

        try {
            $response = $this->inner->complete($request);
        } catch (AiProviderException $e) {
            AiBudgets::reconcile($reservation, null, true);
            $this->record($usage, $correlation, AiUsageEntry::STATUS_FAILED, null, null, null, null, null, (int) ((microtime(true) - $started) * 1000), self::errorCode($e));

            throw $e;
        }

        $input = $response->inputTokens;
        $output = $response->outputTokens;
        AiBudgets::reconcile($reservation, ($input ?? 0) + ($output ?? 0));
        $this->record($usage, $correlation, AiUsageEntry::STATUS_SUCCEEDED, $response->model, $input, $output, null, null, $response->durationMs ?? (int) ((microtime(true) - $started) * 1000));

        return $response;
    }

    private function record(
        array $usage,
        string $correlation,
        string $status,
        ?string $model,
        ?int $in,
        ?int $out,
        ?int $cached,
        ?int $reasoning,
        ?int $durationMs,
        ?string $errorCode = null,
    ): void {
        // Claim-then-insert with one retry: concurrent same-correlation
        // attempts collide on the unique key at most once.
        for ($try = 0; $try < 2; $try++) {
            ['seq' => $seq, 'key' => $key] = UsageLedger::claimAttempt($correlation, $this->name());
            try {
                UsageLedger::record($key, $seq, $usage, $this->name(), $model, $status, $correlation, $in, $out, $cached, $reasoning, $durationMs, $errorCode);

                return;
            } catch (QueryException) {
                continue;
            }
        }
    }

    private static function estimateTokens(AIRequest $request): int
    {
        $chars = strlen($request->system) + strlen($request->user)
            + strlen((string) json_encode($request->messages))
            + strlen((string) json_encode($request->tools));

        return (int) ceil($chars / 4) + ($request->maxCompletionTokens ?? 4000);
    }

    private static function errorCode(\Throwable $e): string
    {
        return $e instanceof AiDeniedException ? 'denied:'.$e->reason : class_basename($e);
    }
}
