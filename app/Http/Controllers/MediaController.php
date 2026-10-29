<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMediaJob;
use App\Models\Project;
use App\Models\ProjectMedia;
use App\Services\Media\ImageProcessor;
use App\Services\Media\MediaUploadService;
use App\Services\Media\StoryDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Project presentation media: images (cover/story/gallery) and
 * supporting files. Management requires project update rights;
 * delivery requires project view rights — draft media stays private
 * and unpublishing revokes access immediately.
 */
class MediaController extends Controller
{
    public function storeImage(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validate([
            'images' => 'required|array|min:1|max:10',
            'images.*' => 'required|file|max:'.(MediaUploadService::MAX_IMAGE_BYTES / 1024),
        ]);

        $stored = 0;
        $reused = 0;
        $reusedProcessing = 0;
        $reusedFailed = 0;
        $errors = [];

        foreach ($data['images'] as $file) {
            $result = MediaUploadService::storeImage($project, $request->user(), $file);
            if ($result['ok']) {
                if ($result['reused']) {
                    $reused++;
                    // A reused row schedules no new job: report its
                    // actual state instead of claiming background work.
                    $status = $result['media']->processing_status ?? null;
                    if ($status === ProjectMedia::STATUS_PROCESSING) {
                        $reusedProcessing++;
                    } elseif ($status === ProjectMedia::STATUS_FAILED) {
                        $reusedFailed++;
                    }
                } else {
                    $stored++;
                }
            } else {
                $errors[] = ($file->getClientOriginalName() ?? 'file').': '.$result['error'];
            }
        }

        $this->bumpVersion($project);

        if ($stored === 0 && $reused === 0) {
            return back()->with('error', implode(' ', $errors));
        }

        $message = "{$stored} new image(s), {$reused} reused.";
        if ($stored > 0) {
            $message .= ' Processing runs in the background; refresh shortly.';
        }
        if ($reusedProcessing > 0) {
            $message .= " {$reusedProcessing} reused image(s) still processing.";
        }
        if ($reusedFailed > 0) {
            $message .= " {$reusedFailed} reused image(s) failed processing — use “Retry processing” in the media library.";
        }
        if ($errors !== []) {
            $message .= ' Rejected: '.implode(' ', $errors);
        }

        return back()->with('status', trim($message));
    }

    /**
     * Idempotent manual retry for recoverable processing failures.
     * The atomic failed→processing transition makes concurrent
     * clicks schedule exactly one job; permanent failures are
     * refused with a re-upload hint instead of a doomed retry.
     */
    public function retry(Project $project, ProjectMedia $media): RedirectResponse
    {
        Gate::authorize('update', $project);
        $this->authorizeMedia($project, $media);

        if (! $media->isImage()) {
            return back()->with('error', 'Only images can be reprocessed.');
        }

        if ($media->isReady()) {
            return back()->with('status', 'This image is already processed — nothing to retry.');
        }

        if ($media->processing_status === ProjectMedia::STATUS_PROCESSING) {
            return back()->with('status', 'This image is already queued for processing — refresh shortly.');
        }

        if (! $media->isRetryable()) {
            return back()->with('error', 'This image cannot be recovered automatically. Delete it and upload a valid JPEG, PNG, or WebP file.');
        }

        $claimed = ProjectMedia::where('id', $media->id)
            ->where('processing_status', ProjectMedia::STATUS_FAILED)
            ->update([
                'processing_status' => ProjectMedia::STATUS_PROCESSING,
                'processing_error' => null,
            ]);

        if ($claimed === 0) {
            return back()->with('status', 'This image is already queued for processing — refresh shortly.');
        }

        ProcessMediaJob::dispatch($media->id);
        $this->bumpVersion($project);

        return back()->with('status', 'Retry queued — processing runs in the background; refresh shortly.');
    }

    public function storeFile(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validate([
            'files' => 'required|array|min:1|max:10',
            'files.*' => 'required|file|max:'.(MediaUploadService::MAX_FILE_BYTES / 1024),
            'category' => 'nullable|string|max:32',
        ]);

        $stored = 0;
        $errors = [];

        foreach ($data['files'] as $file) {
            $result = MediaUploadService::storeFile($project, $request->user(), $file, (string) ($data['category'] ?? 'other'));
            if ($result['ok']) {
                $stored++;
            } else {
                $errors[] = ($file->getClientOriginalName() ?? 'file').': '.$result['error'];
            }
        }

        $this->bumpVersion($project);

        if ($stored === 0) {
            return back()->with('error', implode(' ', $errors));
        }

        $message = "{$stored} file(s) attached.";
        if ($errors !== []) {
            $message .= ' Rejected: '.implode(' ', $errors);
        }

        return back()->with('status', $message);
    }

    public function update(Request $request, Project $project, ProjectMedia $media): RedirectResponse
    {
        Gate::authorize('update', $project);
        $this->authorizeMedia($project, $media);

        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'caption' => 'nullable|string|max:500',
            'alt' => 'nullable|string|max:500',
            'credit' => 'nullable|string|max:255',
            'license' => 'nullable|string|max:255',
            'category' => 'nullable|string|in:photo,documentation,schematics,other',
            'focal_x' => 'nullable|integer|min:0|max:100',
            'focal_y' => 'nullable|integer|min:0|max:100',
            'in_gallery' => 'nullable|boolean',
        ]);

        $media->forceFill(array_filter($data, fn ($v) => $v !== null))->save();
        if (array_key_exists('in_gallery', $data) && $data['in_gallery'] === null) {
            $media->forceFill(['in_gallery' => false])->save();
        }
        $this->bumpVersion($project);

        return back()->with('status', 'Media details saved.');
    }

    public function reorder(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validate([
            'order' => 'required|array|min:1|max:200',
            'order.*' => 'integer',
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['order'])));
        $owned = ProjectMedia::where('project_id', $project->id)
            ->where('kind', ProjectMedia::KIND_IMAGE)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        // Foreign or non-image ids are rejected, not silently skipped.
        if (count($owned) !== count($ids)) {
            return back()->with('error', 'Gallery order references items outside this project.');
        }

        foreach ($ids as $position => $id) {
            ProjectMedia::where('id', $id)->update(['gallery_order' => $position, 'in_gallery' => true]);
        }
        $this->bumpVersion($project);

        return back()->with('status', 'Gallery order saved.');
    }

    public function setCover(Request $request, Project $project, ProjectMedia $media): RedirectResponse
    {
        Gate::authorize('update', $project);
        $this->authorizeMedia($project, $media);

        if (! $media->isImage()) {
            return back()->with('error', 'Only images can be the cover.');
        }

        if (! $media->isReady()) {
            return back()->with('error', 'This image is not processed yet — wait for processing or use “Retry processing” first.');
        }

        $project->forceFill(['cover_media_id' => $media->id])->save();
        $this->bumpVersion($project);

        return back()->with('status', 'Cover image set.');
    }

    public function removeCover(Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $project->forceFill(['cover_media_id' => null])->save();
        $this->bumpVersion($project);

        return back()->with('status', 'Cover removed. The image stays in the media library.');
    }

    public function destroy(Project $project, ProjectMedia $media): RedirectResponse
    {
        Gate::authorize('update', $project);
        $this->authorizeMedia($project, $media);

        // Never silently break the story or cover: unlink explicitly.
        $unlinked = [];
        if ($project->cover_media_id === $media->id) {
            $project->forceFill(['cover_media_id' => null])->save();
            $unlinked[] = 'cover';
        }

        if ($project->hasRichStory() && in_array($media->id, StoryDocument::mediaIds($project->story_document), true)) {
            return back()->with('error', 'This image is used in the project story. Remove it from the story first — deleting it would break the story.');
        }

        $disk = Storage::disk('local');
        $disk->delete($media->originalPath());
        foreach (array_keys(ImageProcessor::VARIANTS) as $variant) {
            $disk->delete($media->variantPath($variant));
        }
        $media->delete();
        $this->bumpVersion($project);

        $message = 'Media deleted.';
        if ($unlinked !== []) {
            $message .= ' It was unlinked from the '.implode(' and ', $unlinked).'.';
        }

        return back()->with('status', $message);
    }

    /**
     * Authorized image delivery. Draft projects: managers only.
     * Published: public. Nothing is served from guessable public URLs.
     */
    public function show(Request $request, Project $project, ProjectMedia $media): BinaryFileResponse|StreamedResponse
    {
        Gate::authorize('view', $project);
        $this->authorizeMedia($project, $media);
        abort_unless($media->isImage(), 404);

        $variant = $request->query('variant', 'card');
        abort_unless(array_key_exists($variant, ImageProcessor::VARIANTS), 404);

        $disk = Storage::disk('local');
        $path = $media->variantPath($variant);
        if (! $media->isReady() || ! $disk->exists($path)) {
            abort(404);
        }

        $response = response()->file($disk->path($path), ['Content-Type' => 'image/jpeg']);

        // Published media may cache publicly; drafts never leave a
        // reusable cached copy behind after unpublishing.
        if ($project->isPublished()) {
            $response->setPublic()->setMaxAge(86400);
        } else {
            $response->setPrivate()->setMaxAge(0);
            $response->headers->addCacheControlDirective('no-store', true);
        }

        return $response;
    }

    /**
     * Supporting-file download. Always an attachment with nosniff:
     * uploaded content is never rendered as active content.
     */
    public function download(Project $project, ProjectMedia $media): StreamedResponse
    {
        Gate::authorize('view', $project);
        $this->authorizeMedia($project, $media);
        abort_unless($media->kind === ProjectMedia::KIND_FILE, 404);

        return Storage::disk('local')->download(
            $media->originalPath(),
            $media->original_name,
            ['X-Content-Type-Options' => 'nosniff']
        );
    }

    private function authorizeMedia(Project $project, ProjectMedia $media): void
    {
        if ($media->project_id !== $project->id) {
            abort(404);
        }
    }

    private function bumpVersion(Project $project): void
    {
        $project->forceFill(['presentation_version' => $project->presentation_version + 1])->save();
    }
}
