<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BinaryAnalysis;
use App\Models\WorkerCapability;
use App\Services\Binary\Adapters\AdapterRegistry;
use App\Services\Binary\Adapters\SandboxedBackend;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AnalysisController extends Controller
{
    public function index(): View
    {
        Gate::authorize('admin');

        $backends = [];
        foreach (AdapterRegistry::all() as $adapter) {
            $check = WorkerCapability::verifiedFor($adapter);
            $runner = null;
            if ($adapter instanceof SandboxedBackend) {
                $details = WorkerCapability::where('adapter', $adapter->id())->first()?->details ?? [];
                $runner = [
                    'arch' => $details['runner_arch'] ?? null,
                    'image' => $details['tool_image'] ?? null,
                    'digest' => isset($details['image_digest']) && is_string($details['image_digest']) ? substr($details['image_digest'], 0, 19) : null,
                ];
            }
            $backends[] = [
                'id' => $adapter->id(),
                'name' => $adapter->name(),
                'verified' => $check['verified'],
                'version' => $check['version'],
                'note' => $check['note'],
                'runner' => $runner,
            ];
        }

        $queue = (string) config('artifacts.binary_analysis_queue', 'analysis');
        try {
            $waiting = DB::table('jobs')->where('queue', $queue)->count();
            $reserved = DB::table('jobs')->where('queue', $queue)->whereNotNull('reserved_at')->count();
        } catch (\Throwable) {
            $waiting = null;
            $reserved = null;
        }

        $running = BinaryAnalysis::where('status', BinaryAnalysis::STATUS_ANALYZING)
            ->orderBy('started_at')->limit(5)->get();

        $failures = BinaryAnalysis::where('status', BinaryAnalysis::STATUS_FAILED)
            ->where('finished_at', '>=', now()->subDay())
            ->selectRaw('error_code, COUNT(*) AS n')
            ->groupBy('error_code')->orderByDesc('n')->limit(10)->get();

        return view('admin.analysis.index', [
            'backends' => $backends,
            'queue' => $queue,
            'waiting' => $waiting,
            'reserved' => $reserved,
            'running' => $running,
            'failures' => $failures,
        ]);
    }
}
