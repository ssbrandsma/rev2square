<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractArtifactJob;
use App\Models\BinaryAnalysis;
use App\Models\Project;
use App\Models\ProjectArtifact;
use App\Services\Artifacts\ArtifactEvidence;
use App\Services\Artifacts\ArtifactUploadService;
use App\Services\Binary\CapabilityMatrix;
use App\Services\Binary\Targets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ArtifactController extends Controller
{
    public function index(Project $project): View
    {
        Gate::authorize('view', $project);

        $artifacts = ProjectArtifact::where('project_id', $project->id)
            ->with('uploader')
            ->latest('id')
            ->get();

        return view('projects.artifacts.index', [
            'project' => $project,
            'artifacts' => $artifacts,
            'maxBytes' => (int) config('artifacts.max_upload_bytes'),
            'maxPerProject' => (int) config('artifacts.max_artifacts_per_project'),
        ]);
    }

    /**
     * @return View|RedirectResponse
     */
    public function store(Request $request, Project $project)
    {
        Gate::authorize('view', $project);

        $data = $request->validate([
            'files' => 'required|array|min:1|max:5',
            'files.*' => 'required|file|max:'.((int) config('artifacts.max_upload_bytes') / 1024),
            'as_raw' => 'nullable|boolean',
        ]);

        $stored = [];
        $reused = 0;
        $errors = [];

        foreach ($data['files'] as $file) {
            $result = ArtifactUploadService::store($project, $request->user(), $file, (bool) ($data['as_raw'] ?? false));

            if ($result['ok']) {
                if (! empty($result['reused'])) {
                    $reused++;
                } else {
                    $stored[] = $result['artifact'];
                }
            } else {
                $errors[] = ($file->getClientOriginalName() ?? 'file').': '.$result['error'];
            }
        }

        // Preserve the investigation question across upload failures.
        $question = $request->input('investigation_question');
        $deviceContext = $request->input('device_context');

        if ($stored === [] && $reused === 0) {
            return back()
                ->withInput($question !== null ? ['investigation_question' => $question, 'device_context' => $deviceContext] : [])
                ->with('error', implode(' ', $errors));
        }

        $parts = [];
        if ($stored !== []) {
            $parts[] = count($stored).' new ('.implode(', ', array_map(fn ($a) => $a->original_name, $stored)).' — extraction queued)';
        }
        if ($reused > 0) {
            $parts[] = $reused.' duplicate'.($reused === 1 ? '' : 's').' reused without re-uploading';
        }
        if ($errors !== []) {
            $parts[] = count($errors).' rejected: '.implode(' ', $errors);
        }
        $message = 'Upload result: '.implode('; ', $parts).'.';

        return redirect()->route('projects.artifacts.index', $project)
            ->withInput($question !== null ? ['investigation_question' => $question, 'device_context' => $deviceContext] : [])
            ->with('status', $message);
    }

    public function show(Project $project, ProjectArtifact $artifact): View
    {
        Gate::authorize('view', $project);
        $this->authorizeArtifact($project, $artifact);

        $snapshot = $artifact->isUsable() ? $artifact->readSnapshot() : null;
        $analyses = BinaryAnalysis::where('project_artifact_id', $artifact->id)->latest('id')->get();

        $backToConversation = null;
        $from = request()->query('from');

        if (is_string($from) && preg_match('/^agent:(\d+)$/', $from, $m)) {
            $conversation = $project->agentConversations()->find((int) $m[1]);
            if ($conversation !== null && $conversation->isAccessibleBy(auth()->user())) {
                $backToConversation = $conversation;
            }
        }

        return view('projects.artifacts.show', [
            'project' => $project,
            'artifact' => $artifact,
            'snapshot' => $snapshot,
            'details' => ArtifactEvidence::viewerDetails($artifact, $snapshot),
            'disclosure' => ArtifactEvidence::disclosure($artifact),
            'backToConversation' => $backToConversation,
            'analyses' => $analyses,
            'capabilities' => CapabilityMatrix::forArtifact($artifact, $snapshot),
            'targets' => Targets::all(),
            'candidates' => Targets::candidatesFor($artifact->format, $artifact->arch, $snapshot ?? []),
        ]);
    }

    public function retry(Project $project, ProjectArtifact $artifact): RedirectResponse
    {
        Gate::authorize('view', $project);
        $this->authorizeArtifact($project, $artifact);

        if (! in_array($artifact->state, [ProjectArtifact::STATE_FAILED, ProjectArtifact::STATE_UPLOADED], true)) {
            return back()->with('error', 'Only failed uploads can be retried. This artifact is '.$artifact->stateLabel().'.');
        }

        if (! Storage::disk('artifacts')->exists($artifact->rawPath())) {
            return back()->with('error', 'The stored file is gone. Upload it again to investigate.');
        }

        $artifact->forceFill([
            'state' => ProjectArtifact::STATE_QUEUED,
            'failure_code' => null,
            'failure_message' => null,
        ])->save();

        ExtractArtifactJob::dispatch($artifact->id);

        return back()->with('status', 'Extraction queued again.');
    }

    public function destroy(Project $project, ProjectArtifact $artifact): RedirectResponse
    {
        Gate::authorize('update', $project);
        $this->authorizeArtifact($project, $artifact);

        $disk = Storage::disk('artifacts');
        $paths = [$artifact->rawPath(), $artifact->snapshotPath()];
        foreach (BinaryAnalysis::where('project_artifact_id', $artifact->id)->get() as $analysis) {
            if ($analysis->evidencePath() !== null) {
                $paths[] = $analysis->evidencePath();
            }
        }
        $disk->delete($paths);
        $artifact->delete();

        return redirect()->route('projects.artifacts.index', $project)
            ->with('status', 'Artifact deleted. Historical citations to it keep their recorded metadata but no longer resolve to content.');
    }

    private function authorizeArtifact(Project $project, ProjectArtifact $artifact): void
    {
        if ($artifact->project_id !== $project->id) {
            abort(404);
        }
    }
}
