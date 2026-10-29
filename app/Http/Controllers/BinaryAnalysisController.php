<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeBinaryJob;
use App\Models\BinaryAnalysis;
use App\Models\Project;
use App\Models\ProjectArtifact;
use App\Services\Binary\AnalysisRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BinaryAnalysisController extends Controller
{
    public function show(Project $project, ProjectArtifact $artifact, BinaryAnalysis $analysis): View
    {
        Gate::authorize('view', $project);
        $this->authorizeAnalysis($project, $artifact, $analysis);

        return view('projects.artifacts.analysis-show', [
            'project' => $project,
            'artifact' => $artifact,
            'analysis' => $analysis,
            'evidence' => $analysis->isUsable() ? $analysis->readEvidence() : null,
        ]);
    }

    public function store(Request $request, Project $project, ProjectArtifact $artifact): RedirectResponse
    {
        Gate::authorize('view', $project);
        $this->authorizeArtifact($project, $artifact);

        $data = $request->validate([
            'target' => 'nullable|string|max:64',
            'base_address' => 'nullable|string|max:32',
            'entry' => 'nullable|string|max:32',
            'platform' => 'nullable|string|max:32',
            'adapter' => 'nullable|string|max:32',
        ]);

        $result = AnalysisRequest::request($artifact, $request->user(), $data);
        if (! ($result['ok'] ?? false)) {
            return back()->withInput()->with('error', $result['error'] ?? 'Analysis could not be queued.');
        }

        $analysis = $result['analysis'];
        if (! empty($result['reused'])) {
            $message = match ($analysis->status) {
                BinaryAnalysis::STATUS_QUEUED => 'Identical analysis already queued — no duplicate job was scheduled.',
                BinaryAnalysis::STATUS_ANALYZING => 'Identical analysis already running — no duplicate job was scheduled.',
                BinaryAnalysis::STATUS_READY, BinaryAnalysis::STATUS_PARTIAL => 'Identical analysis already exists — showing the existing run.',
                BinaryAnalysis::STATUS_CANCELLED => 'The identical run was cancelled. Retry it from the analysis page if still needed.',
                default => 'The identical run failed ('.($analysis->error_message ?? 'unknown error').'). Retry it from the analysis page.',
            };

            return redirect()->route('projects.artifacts.analyses.show', [$project, $artifact, $analysis])
                ->with($analysis->isUsable() || ! $analysis->isTerminal() ? 'status' : 'error', $message);
        }

        return redirect()->route('projects.artifacts.analyses.show', [$project, $artifact, $analysis])
            ->with('status', 'Analysis queued ('.$analysis->adapter.', target '.$analysis->target.').');
    }

    public function cancel(Project $project, ProjectArtifact $artifact, BinaryAnalysis $analysis): RedirectResponse
    {
        Gate::authorize('view', $project);
        $this->authorizeAnalysis($project, $artifact, $analysis);

        if ($analysis->isTerminal()) {
            return back()->with('error', 'This run is already '.$analysis->statusLabel().'.');
        }

        $analysis->forceFill([
            'status' => BinaryAnalysis::STATUS_CANCELLED,
            'progress_note' => null,
            'finished_at' => now(),
        ])->save();

        return back()->with('status', 'Analysis run cancelled. A queued job for it will exit without working.');
    }

    public function retry(Project $project, ProjectArtifact $artifact, BinaryAnalysis $analysis): RedirectResponse
    {
        Gate::authorize('view', $project);
        $this->authorizeAnalysis($project, $artifact, $analysis);

        if (! $analysis->isRetryable()) {
            $reason = $analysis->status === BinaryAnalysis::STATUS_FAILED
                ? 'This failure ('.($analysis->error_code ?? 'unknown').') is not recoverable by retry. Adjust the target configuration and queue a fresh run.'
                : 'Only failed runs can be retried. This run is '.$analysis->statusLabel().'.';

            return back()->with('error', $reason);
        }

        $claimed = BinaryAnalysis::where('id', $analysis->id)
            ->where('status', BinaryAnalysis::STATUS_FAILED)
            ->update([
                'status' => BinaryAnalysis::STATUS_QUEUED,
                'progress_note' => null,
                'error_code' => null,
                'error_message' => null,
                'started_at' => null,
                'finished_at' => null,
            ]);

        if ($claimed === 0) {
            return back()->with('status', 'This run is no longer failed — no duplicate job was scheduled.');
        }

        AnalyzeBinaryJob::dispatch($analysis->id);

        return back()->with('status', 'Analysis queued again.');
    }

    private function authorizeArtifact(Project $project, ProjectArtifact $artifact): void
    {
        if ($artifact->project_id !== $project->id) {
            abort(404);
        }
    }

    private function authorizeAnalysis(Project $project, ProjectArtifact $artifact, BinaryAnalysis $analysis): void
    {
        $this->authorizeArtifact($project, $artifact);
        if ($analysis->project_artifact_id !== $artifact->id) {
            abort(404);
        }
    }
}
