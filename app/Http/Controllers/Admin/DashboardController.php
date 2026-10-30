<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountStatus;
use App\Enums\GlobalRole;
use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Models\AiUsageEntry;
use App\Models\Report;
use App\Models\User;
use App\Models\WorkerCapability;
use App\Services\Admin\AppSettings;
use App\Services\Admin\CostEstimates;
use App\Services\Binary\Adapters\AdapterRegistry;
use App\Services\Ops\DeployHints;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        Gate::authorize('admin');

        $today = gmdate('Y-m-d');
        $usage = AiUsageEntry::whereDate('created_at', $today)
            ->selectRaw('COUNT(*) AS requests, COALESCE(SUM(input_tokens),0)+COALESCE(SUM(output_tokens),0) AS tokens, SUM(estimated_cost_micros) AS micros, SUM(status = ?) AS errors', [AiUsageEntry::STATUS_FAILED])
            ->first();

        $unknownCosts = AiUsageEntry::whereDate('created_at', $today)
            ->whereNull('estimated_cost_micros')
            ->exists();

        $warnings = [];
        if (! AppSettings::get('ai.enabled')) {
            $warnings[] = 'AI features are globally disabled.';
        }
        if (AppSettings::get('meta.api_key') === null) {
            $warnings[] = 'No Meta API key is configured; model requests will fail until one is set.';
        }
        if (AppSettings::uploadsAllowed()) {
            $warnings[] = 'Upload investigation is enabled, but OS-level worker isolation is not verified. Do not accept untrusted uploads as production-ready.';
        }
        $suspendedMaintainers = User::where('account_status', AccountStatus::Suspended)
            ->whereHas('ownedProjects')
            ->count();
        if ($suspendedMaintainers > 0) {
            $warnings[] = $suspendedMaintainers.' suspended account(s) still own projects — review ownership, which is never transferred automatically.';
        }
        $unverifiedInstalled = [];
        foreach (AdapterRegistry::all() as $adapter) {
            if ($adapter->id() === 'php-native') {
                continue;
            }
            if ($adapter->probe()['available'] && ! WorkerCapability::verifiedFor($adapter)['verified']) {
                $unverifiedInstalled[] = $adapter->name();
            }
        }
        if ($unverifiedInstalled !== []) {
            $warnings[] = 'Installed analysis backend(s) lack a fresh worker snapshot ('.implode(', ', $unverifiedInstalled).') — their targets cannot queue until refreshed ('.DeployHints::refreshSnapshotsHint().').';
        }

        return view('admin.dashboard', [
            'userCounts' => [
                'total' => User::count(),
                'admins' => User::where('global_role', GlobalRole::Admin)->where('account_status', AccountStatus::Active)->count(),
                'moderators' => User::where('global_role', GlobalRole::Moderator)->where('account_status', AccountStatus::Active)->count(),
                'suspended' => User::where('account_status', AccountStatus::Suspended)->count(),
                'ai_disabled' => User::where('ai_enabled', false)->count(),
            ],
            'pendingReports' => Report::where('status', ReportStatus::Pending)->count(),
            'today' => [
                'day' => $today,
                'requests' => (int) ($usage->requests ?? 0),
                'tokens' => (int) ($usage->tokens ?? 0),
                'errors' => (int) ($usage->errors ?? 0),
                'cost' => $unknownCosts && $usage->micros === null
                    ? 'unknown (no pricing configured)'
                    : CostEstimates::formatMicros($usage->micros !== null ? (int) $usage->micros : null).($unknownCosts ? ' (partial: some rows lack pricing)' : ' (estimate)'),
            ],
            'keySource' => AppSettings::source('meta.api_key'),
            'warnings' => $warnings,
            'suspendedMaintainers' => $suspendedMaintainers,
        ]);
    }
}
