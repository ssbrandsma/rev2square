<?php

namespace App\Services\Admin;

use App\Models\AiBudgetCounter;
use App\Models\AiUsageEntry;
use App\Models\User;
use App\Services\AI\AiDeniedException;
use Illuminate\Support\Facades\DB;

/**
 * Server-side AI budgets. Reservations lock the per-user-day counter
 * row, so concurrent requests serialize on the same allowance instead
 * of overspending it. Days are UTC; the UI labels the timezone.
 */
final class AiBudgets
{
    /**
     * Authorize one provider attempt: kill switch, account state, and
     * allowance. Returns the reservation to reconcile after completion.
     *
     * @return array{counter_id: int, estimated_tokens: int}
     *
     * @throws AiDeniedException
     */
    public static function reserve(User $user, int $estimatedTokens, bool $isConnectionTest = false): array
    {
        if (! $isConnectionTest && ! AppSettings::get('ai.enabled')) {
            throw new AiDeniedException('AI features are currently disabled by the administrator.', 'ai_disabled');
        }

        $fresh = User::find($user->id);
        if ($fresh === null || $fresh->isSuspended()) {
            throw new AiDeniedException('This account has been suspended.', 'suspended');
        }
        if ($fresh->aiDisabled()) {
            throw new AiDeniedException('AI access is disabled for this account.', 'ai_restricted');
        }

        if ($fresh->ai_unlimited || $isConnectionTest) {
            return ['counter_id' => 0, 'estimated_tokens' => 0];
        }

        $requestLimit = $fresh->ai_daily_request_limit ?? AppSettings::get('ai.default_daily_request_limit');
        $tokenLimit = $fresh->ai_daily_token_limit ?? AppSettings::get('ai.default_daily_token_limit');
        $costCap = AppSettings::get('ai.daily_cost_cap_micros');
        $day = gmdate('Y-m-d');

        return DB::transaction(function () use ($fresh, $day, $requestLimit, $tokenLimit, $costCap, $estimatedTokens): array {
            $counter = AiBudgetCounter::where('user_id', $fresh->id)
                ->where('day', $day)
                ->lockForUpdate()
                ->first();

            if ($counter === null) {
                $counter = AiBudgetCounter::create([
                    'user_id' => $fresh->id, 'day' => $day, 'requests' => 0, 'tokens' => 0,
                ]);
                $counter = AiBudgetCounter::where('id', $counter->id)->lockForUpdate()->first();
            }

            if (is_int($requestLimit) && $counter->requests + 1 > $requestLimit) {
                throw new AiDeniedException('Daily AI request allowance exhausted. It resets at 00:00 UTC.', 'budget_requests');
            }
            if (is_int($tokenLimit) && $counter->tokens + $estimatedTokens > $tokenLimit) {
                throw new AiDeniedException('Daily AI token allowance exhausted. It resets at 00:00 UTC.', 'budget_tokens');
            }
            if (is_int($costCap)) {
                $inPrice = AppSettings::get('ai.price_input_per_1k_micros');
                $outPrice = AppSettings::get('ai.price_output_per_1k_micros');
                if (! is_int($inPrice) || ! is_int($outPrice)) {
                    throw new AiDeniedException('A daily spend cap is configured but no model pricing is available, so requests cannot be costed reliably. Ask an administrator to set pricing or remove the cap.', 'budget_pricing_unknown');
                }
                $spent = (int) AiUsageEntry::where('user_id', $fresh->id)
                    ->whereDate('created_at', $day)
                    ->whereNotNull('estimated_cost_micros')
                    ->sum('estimated_cost_micros');
                $worstCase = (int) round($estimatedTokens / 1000 * max($inPrice, $outPrice));
                if ($spent + $worstCase > $costCap) {
                    throw new AiDeniedException('Daily AI spend allowance exhausted. It resets at 00:00 UTC.', 'budget_cost');
                }
            }

            $counter->increment('requests');
            $counter->increment('tokens', $estimatedTokens);

            return ['counter_id' => $counter->id, 'estimated_tokens' => $estimatedTokens];
        });
    }

    /**
     * Replace the pre-call token estimate with reconciled actuals.
     * Failed attempts release the estimate (requests stay counted).
     */
    public static function reconcile(array $reservation, ?int $actualTokens, bool $failed = false): void
    {
        if (($reservation['counter_id'] ?? 0) === 0) {
            return;
        }

        $actual = $failed ? 0 : max(0, $actualTokens ?? 0);
        $delta = $actual - (int) ($reservation['estimated_tokens'] ?? 0);

        if ($delta === 0) {
            return;
        }

        DB::transaction(function () use ($reservation, $delta): void {
            $counter = AiBudgetCounter::where('id', $reservation['counter_id'])->lockForUpdate()->first();
            if ($counter === null) {
                return;
            }
            $counter->forceFill(['tokens' => max(0, $counter->tokens + $delta)])->save();
        });
    }

    /**
     * Non-atomic friendly pre-check for controllers (the decorator
     * enforces atomically). Returns a denial message or null.
     */
    public static function precheck(User $user): ?string
    {
        if (! AppSettings::get('ai.enabled')) {
            return 'AI features are currently disabled by the administrator.';
        }
        // Fresh state: in-memory instances may predate the columns.
        $fresh = $user->exists ? ($user->fresh() ?? $user) : $user;
        if ($fresh->isSuspended()) {
            return 'This account has been suspended.';
        }
        if ($fresh->aiDisabled()) {
            return 'AI access is disabled for this account.';
        }

        return null;
    }

    /** @return array{requests: int, tokens: int} */
    public static function todayUsage(int $userId): array
    {
        $counter = AiBudgetCounter::where('user_id', $userId)->where('day', gmdate('Y-m-d'))->first();

        return ['requests' => $counter?->requests ?? 0, 'tokens' => $counter?->tokens ?? 0];
    }

    /**
     * Rebuild today's counters from reconciled ledger rows. Repairs
     * drift from crashed processes that reserved but never reconciled.
     */
    public static function rebuildToday(): int
    {
        $day = gmdate('Y-m-d');
        $rows = AiUsageEntry::where('status', AiUsageEntry::STATUS_SUCCEEDED)
            ->whereDate('created_at', $day)
            ->whereNotNull('user_id')
            ->selectRaw('user_id, COUNT(*) AS requests, COALESCE(SUM(input_tokens),0)+COALESCE(SUM(output_tokens),0) AS tokens')
            ->groupBy('user_id')
            ->get();

        $touched = 0;
        foreach ($rows as $row) {
            AiBudgetCounter::updateOrCreate(
                ['user_id' => $row->user_id, 'day' => $day],
                ['requests' => $row->requests, 'tokens' => $row->tokens]
            );
            $touched++;
        }

        return $touched;
    }
}
