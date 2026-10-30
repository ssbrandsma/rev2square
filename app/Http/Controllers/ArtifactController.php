<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractArtifactJob;
use App\Models\BinaryAnalysis;
use App\Models\Project;
use App\Models\ProjectArtifact;
use App\Services\Artifacts\ArtifactEvidence;
use App\Services\Artifacts\ArtifactStorage;
use App\Services\Artifacts\ArtifactUploadService;
use App\Services\Binary\CapabilityMatrix;
use App\Services\Binary\Targets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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

        // PHP silently empties the request when the body exceeds
        // post_max_size. Detect that here so an oversized batch is
        // never misreported as a missing file or a CSRF failure.
        $postMax = self::phpBytes((string) ini_get('post_max_size'));
        $contentLength = (int) $request->headers->get('Content-Length', 0);
        if ($postMax > 0 && $contentLength > $postMax) {
            return back()
                ->withInput($request->only(['investigation_question', 'device_context']))
                ->with('error', 'This upload batch exceeds the server request limit of '
                    .ArtifactUploadService::humanBytes($postMax).' in total. Upload large files one at a time.');
        }

        $data = $request->validate([
            'files' => 'required|array|min:1|max:5',
            'files.*' => 'required',
            'as_raw' => 'nullable|boolean',
        ]);

        $maxBytes = (int) config('artifacts.max_upload_bytes');
        $stored = [];
        $reused = 0;
        $errors = [];

        foreach ($request->file('files', []) as $file) {
            if (! $file instanceof UploadedFile) {
                $errors[] = 'One upload slot did not contain a file. Select the files again.';
                continue;
            }
            $name = $file->getClientOriginalName() ?: 'file';
            if (! $file->isValid()) {
                $errors[] = $name.': '.self::uploadErrorMessage($file->getError(), $maxBytes);
                continue;
            }
            if (($file->getSize() ?? 0) > $maxBytes) {
                $errors[] = $name.': this file is '.ArtifactUploadService::humanBytes((int) $file->getSize())
                    .'; the limit is '.ArtifactUploadService::humanBytes($maxBytes).'. Narrow the capture or split the input and try again.';
                continue;
            }

            $result = ArtifactUploadService::store($project, $request->user(), $file, (bool) ($data['as_raw'] ?? false));

            if ($result['ok']) {
                if (! empty($result['reused'])) {
                    $reused++;
                } else {
                    $stored[] = $result['artifact'];
                }
            } else {
                $errors[] = $name.': '.$result['error'];
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
        // A usable artifact always has a snapshot: a null read means the
        // stored files are currently unavailable, not that extraction is
        // still running. Say so explicitly instead of rendering an
        // unexplained empty viewer.
        $storageNotice = null;
        if ($artifact->isUsable() && $snapshot === null) {
            $status = ArtifactStorage::status('artifacts', $artifact->snapshotPath());
            if ($status === ArtifactStorage::OK) {
                Log::warning('Stored artifact snapshot failed to decode.', [
                    'artifact_id' => $artifact->id,
                    'project_id' => $project->id,
                ]);
            } else {
                ArtifactStorage::logUnavailable('artifacts', $artifact->snapshotPath(), $status, [
                    'artifact_id' => $artifact->id,
                    'project_id' => $project->id,
                ]);
            }
            $storageNotice = 'The stored analysis files are temporarily unavailable, so details below may be incomplete. Reload shortly; nothing needs re-uploading unless this persists.';
        }
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
            'storageNotice' => $storageNotice,
            'details' => ArtifactEvidence::viewerDetails($artifact, $snapshot),
            'disclosure' => ArtifactEvidence::disclosure($artifact),
            'backToConversation' => $backToConversation,
            'analyses' => $analyses,
            'capabilities' => CapabilityMatrix::forArtifact($artifact, $snapshot),
            'targetOptions' => CapabilityMatrix::targetOptions($artifact, $snapshot),
            'memberOptions' => self::memberOptions($artifact, $snapshot),
            'candidates' => Targets::candidatesFor($artifact->format, $artifact->arch, $snapshot ?? []),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return list<array{name: string, label: string, disabled: bool}>
     */
    private static function memberOptions(ProjectArtifact $artifact, ?array $snapshot): array
    {
        if ($artifact->format !== ProjectArtifact::FORMAT_ARCHIVE) {
            return [];
        }
        $cap = (int) config('artifacts.binary_max_archive_member_bytes', 32 * 1024 * 1024);
        $options = [];
        foreach (($snapshot['data']['members'] ?? []) as $member) {
            $size = (int) ($member['size'] ?? 0);
            $method = $member['method'] ?? '?';
            $disabled = ! empty($member['encrypted']) || $size <= 0 || $size > $cap || ! in_array($method, ['stored', 'deflated'], true);
            $note = ! empty($member['encrypted']) ? 'encrypted' : ($size <= 0 ? 'empty' : ($size > $cap ? 'over staging cap' : $method));
            $options[] = [
                'name' => (string) ($member['name'] ?? '?'),
                'label' => (string) ($member['name'] ?? '?').' ('.number_format($size).' bytes, '.$note.')',
                'disabled' => $disabled,
            ];
        }

        return $options;
    }

    public function retry(Project $project, ProjectArtifact $artifact): RedirectResponse
    {
        Gate::authorize('view', $project);
        $this->authorizeArtifact($project, $artifact);

        if (! in_array($artifact->state, [ProjectArtifact::STATE_FAILED, ProjectArtifact::STATE_UPLOADED], true)) {
            return back()->with('error', 'Only failed uploads can be retried. This artifact is '.$artifact->stateLabel().'.');
        }

        $status = ArtifactStorage::status('artifacts', $artifact->rawPath());
        if ($status !== ArtifactStorage::OK) {
            ArtifactStorage::logUnavailable('artifacts', $artifact->rawPath(), $status, [
                'artifact_id' => $artifact->id,
                'project_id' => $project->id,
            ]);

            return back()->with('error', 'The stored file is still unavailable, so there is nothing to retry yet. Wait for the restore to finish, then retry again; re-upload only if the file never comes back.');
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

    /**
     * Distinct, actionable message per PHP upload failure. Oversized
     * files name the application limit; interruptions and server-side
     * failures say so instead of blaming the file format.
     */
    private static function uploadErrorMessage(int $error, int $maxBytes): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'exceeds the per-file limit of '
                .ArtifactUploadService::humanBytes($maxBytes).'. Narrow the capture or split the input and try again.',
            UPLOAD_ERR_PARTIAL => 'was interrupted during upload. Check the connection and try again.',
            UPLOAD_ERR_NO_FILE => 'no file was received. Select the file again.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'could not be saved on the server. Try again later.',
            default => 'did not complete. Try again.',
        };
    }

    /**
     * Parse a php.ini shorthand byte value (e.g. "80M", "1G", "512K").
     * Returns 0 for unlimited/empty.
     */
    private static function phpBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return 0;
        }
        $last = strtolower(substr($value, -1));
        if (ctype_digit($last)) {
            return (int) $value;
        }

        return match ($last) {
            'g' => (int) substr($value, 0, -1) * 1024 * 1024 * 1024,
            'm' => (int) substr($value, 0, -1) * 1024 * 1024,
            'k' => (int) substr($value, 0, -1) * 1024,
            default => (int) $value,
        };
    }
}
