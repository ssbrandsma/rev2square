<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiUsageEntry;
use App\Models\User;
use App\Services\Admin\CostEstimates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UsageController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('admin');

        $entries = $this->filtered($request)->with(['user', 'project'])->latest('id')->paginate(30)->withQueryString();

        $totals = (clone $this->filtered($request)->getQuery())
            ->selectRaw('COUNT(*) AS requests, COALESCE(SUM(input_tokens),0)+COALESCE(SUM(output_tokens),0) AS tokens, SUM(estimated_cost_micros) AS micros')
            ->first();

        $unknown = (clone $this->filtered($request)->getQuery())->whereNull('estimated_cost_micros')->exists();

        return view('admin.usage.index', [
            'entries' => $entries,
            'filters' => $request->only(['user', 'feature', 'status', 'from', 'to', 'project']),
            'totals' => [
                'requests' => (int) ($totals->requests ?? 0),
                'tokens' => (int) ($totals->tokens ?? 0),
                'cost' => $unknown && $totals->micros === null
                    ? 'unknown'
                    : CostEstimates::formatMicros($totals->micros !== null ? (int) $totals->micros : null).' (estimate)',
                'partial' => $unknown && $totals->micros !== null,
            ],
        ]);
    }

    public function user(User $user): View
    {
        Gate::authorize('admin');

        $entries = AiUsageEntry::where('user_id', $user->id)->with('project')->latest('id')->paginate(30);

        $totals = AiUsageEntry::where('user_id', $user->id)
            ->selectRaw('COUNT(*) AS requests, COALESCE(SUM(input_tokens),0)+COALESCE(SUM(output_tokens),0) AS tokens, SUM(estimated_cost_micros) AS micros')
            ->first();

        return view('admin.usage.user', [
            'user' => $user,
            'entries' => $entries,
            'totals' => [
                'requests' => (int) ($totals->requests ?? 0),
                'tokens' => (int) ($totals->tokens ?? 0),
                'cost' => CostEstimates::formatMicros($totals->micros !== null ? (int) $totals->micros : null),
            ],
        ]);
    }

    /**
     * Metadata-only CSV export. Every cell is guarded against
     * spreadsheet formula injection.
     */
    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('admin');

        $query = $this->filtered($request)->with(['user', 'project'])->latest('id')->take(5000);

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'at_utc', 'user', 'feature', 'operation', 'project', 'provider', 'model', 'status', 'input_tokens', 'output_tokens', 'duration_ms', 'correlation_id', 'estimated_cost_usd', 'pricing_version', 'error_code']);

            foreach ($query->cursor() as $entry) {
                fputcsv($out, array_map(self::cell(...), [
                    $entry->id,
                    $entry->created_at?->toIso8601String(),
                    $entry->user?->email ?? 'unattributed',
                    $entry->feature,
                    $entry->operation,
                    $entry->project?->title ?? '',
                    $entry->provider,
                    $entry->model ?? '',
                    $entry->status,
                    $entry->input_tokens,
                    $entry->output_tokens,
                    $entry->duration_ms,
                    $entry->correlation_id ?? '',
                    $entry->estimated_cost_micros !== null ? number_format($entry->estimated_cost_micros / 1000000, 6) : 'unknown',
                    $entry->pricing_version ?? '',
                    $entry->error_code ?? '',
                ]));
            }

            fclose($out);
        }, 'ai-usage-'.gmdate('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function filtered(Request $request): Builder
    {
        return AiUsageEntry::query()
            ->when($request->string('user')->toString() !== '', function ($query) use ($request): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $request->string('user')->toString()).'%';
                $query->whereHas('user', fn ($q) => $q->where('email', 'like', $term)->orWhere('name', 'like', $term));
            })
            ->when($request->string('feature')->toString() !== '', fn ($q) => $q->where('feature', $request->string('feature')->toString()))
            ->when($request->string('status')->toString() !== '', fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->string('project')->toString() !== '', function ($query) use ($request): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $request->string('project')->toString()).'%';
                $query->whereHas('project', fn ($q) => $q->where('title', 'like', $term));
            })
            ->when($request->string('from')->toString() !== '', fn ($q) => $q->whereDate('created_at', '>=', $request->string('from')->toString()))
            ->when($request->string('to')->toString() !== '', fn ($q) => $q->whereDate('created_at', '<=', $request->string('to')->toString()));
    }

    private static function cell(mixed $value): string
    {
        $text = (string) ($value ?? '');
        // Neutralize formula injection: values starting with a trigger
        // character are prefixed so spreadsheets treat them as text.
        if (preg_match('/^[=+\-@\t\r]/', $text)) {
            return "'".$text;
        }

        return $text;
    }
}
