<?php

namespace App\Http\Controllers;

use App\Models\AiUsageEntry;
use App\Services\Admin\AiBudgets;
use App\Services\Admin\AppSettings;
use App\Services\Admin\CostEstimates;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * A user's own private AI usage: consumption, limits, and remaining
 * allowance. Users see only their own rows, never anyone else's.
 */
class MyUsageController extends Controller
{
    public function show(): View
    {
        $user = auth()->user();
        Gate::authorize('view-my-usage', $user);

        $today = AiBudgets::todayUsage($user->id);
        $requestLimit = $user->ai_daily_request_limit ?? AppSettings::get('ai.default_daily_request_limit');
        $tokenLimit = $user->ai_daily_token_limit ?? AppSettings::get('ai.default_daily_token_limit');

        $entries = AiUsageEntry::where('user_id', $user->id)
            ->with('project')
            ->latest('id')
            ->paginate(25);

        $totalMicros = AiUsageEntry::where('user_id', $user->id)->whereNotNull('estimated_cost_micros')->sum('estimated_cost_micros');
        $hasUnknown = AiUsageEntry::where('user_id', $user->id)->whereNull('estimated_cost_micros')->exists();

        return view('usage.mine', [
            'entries' => $entries,
            'today' => $today,
            'requestLimit' => $requestLimit,
            'tokenLimit' => $tokenLimit,
            'unlimited' => $user->ai_unlimited,
            'cost' => $totalMicros > 0 || ! $hasUnknown
                ? CostEstimates::formatMicros((int) $totalMicros).' (estimate)'
                : 'unknown',
            'costPartial' => $hasUnknown && $totalMicros > 0,
        ]);
    }
}
