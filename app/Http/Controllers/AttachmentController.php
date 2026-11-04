<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Observation;
use App\Models\Project;
use App\Models\TestReport;
use App\Services\Community\ProjectActionPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    private const MAX_KILOBYTES = 5120;

    private const ALLOWED_MIMES = [
        'text/plain',
        'text/markdown',
        'text/csv',
        'application/json',
        'application/pdf',
        'image/png',
        'image/jpeg',
    ];

    public function storeObservationFile(Request $request, Project $project, Observation $observation): RedirectResponse
    {
        $this->assertBelongsToProject($project, $observation);

        return $this->store($request, $project, $observation);
    }

    public function storeTestReportFile(Request $request, Project $project, TestReport $testReport): RedirectResponse
    {
        $this->assertBelongsToProject($project, $testReport);

        return $this->store($request, $project, $testReport);
    }

    public function download(Project $project, Attachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $project);
        abort_unless($attachment->project()?->id === $project->id, 404);

        return Storage::download($attachment->path, $attachment->original_name);
    }

    public function review(Request $request, Project $project, Attachment $attachment): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($attachment->project()?->id === $project->id, 404);

        $attachment->forceFill([
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $request->user()->id,
        ])->save();

        AuditEvent::record('attachment.reviewed', $request->user(), $project, $attachment);

        return back()->with('status', 'Attachment marked as reviewed.');
    }

    public function destroy(Request $request, Project $project, Attachment $attachment): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($attachment->project()?->id === $project->id, 404);

        $user = $request->user();
        abort_unless($attachment->user_id === $user->id || $project->managedBy($user), 403);

        Storage::delete($attachment->path);
        AuditEvent::record('attachment.deleted', $user, $project, $attachment, [
            'original_name' => $attachment->original_name,
        ]);
        $attachment->delete();

        return back()->with('status', 'Attachment deleted.');
    }

    private function store(Request $request, Project $project, Observation|TestReport $parent): RedirectResponse
    {
        Gate::authorize('view', $project);

        if ($denial = ProjectActionPolicy::evidenceDenial($project, $request->user())) {
            return back()->withErrors(['file' => $denial]);
        }

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES, 'mimetypes:'.implode(',', self::ALLOWED_MIMES)],
        ]);

        $file = $data['file'];
        $path = $file->store('attachments', 'local');

        // Never trust client-supplied metadata: strip any directory
        // adventure from the name (it is reused as the download
        // filename) and sniff the MIME server-side instead.
        $originalName = Str::limit(pathinfo((string) $file->getClientOriginalName(), PATHINFO_BASENAME) ?: 'upload', 255, '');

        $parent->attachments()->create([
            'user_id' => $request->user()->id,
            'path' => $path,
            'original_name' => $originalName,
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);

        AuditEvent::record('attachment.uploaded', $request->user(), $project, $parent, [
            'original_name' => $originalName,
        ]);

        return back()->with('status', 'File attached. A maintainer will review it for sensitive content.');
    }
}
