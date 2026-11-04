<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMediaJob;
use App\Models\Project;
use App\Models\ProjectMedia;
use App\Services\Docs\DocMediaUsage;
use App\Services\Media\ImageProcessor;
use App\Services\Media\MediaUploadService;
use App\Services\Media\StoryDocument;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
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
    public function storeImage(Request $request, Project $project): RedirectResponse|JsonResponse
    {
        Gate::authorize('update', $project);

        // Docs uploads ride along with the in-progress page draft so
        // uploading mid-edit never loses unsaved work; other callers
        // post none of these fields and are unaffected. The version
        // token rides along too: without it, uploading from a stale
        // editor would re-render the form with the current token and
        // let the next save silently overwrite another editor.
        $draft = $request->only(['title', 'summary', 'category', 'publication_status', 'sort_order', 'doc_document', 'doc_version', 'rev_note']);

        // A request past post_max_size arrives with POST and FILES
        // emptied by PHP: say so instead of "choose a photo".
        if (count($request->request->all()) === 0 && count($request->files->all()) === 0
            && (int) $request->server('CONTENT_LENGTH', 0) > 0) {
            $message = 'The request was too large for the server to receive — nothing was stored. Split your selection into smaller batches and try again.';

            return $this->uploadFailure($request, $draft, ['images' => [$message]], $message);
        }

        // The browser reports how many files it sent; PHP may have
        // truncated the request (max_file_uploads) before Laravel saw
        // it. Never claim every selected file was considered.
        $received = $request->file('images', []);
        $received = is_array($received) ? array_values($received) : [$received];
        $expected = (int) $request->input('expected_count', 0);
        if ($expected > 0 && count($received) !== $expected) {
            $message = 'You selected '.$expected.' photos but the server received '.count($received).'. The request was truncated by a server limit — split your selection into smaller batches and try again. Nothing was stored from this request.';

            return $this->uploadFailure($request, $draft, ['images' => [$message]], $message);
        }

        $validator = Validator::make($request->all(), [
            'images' => ['required', 'array', 'min:1', 'max:'.MediaUploadService::MAX_IMAGES_PER_REQUEST],
            'images.*' => ['required', 'file', 'max:'.(MediaUploadService::MAX_IMAGE_BYTES / 1024)],
        ]);
        if ($validator->fails()) {
            $messages = $this->uploadRuleMessages($validator, $received);

            return $this->uploadFailure($request, $draft, $messages, (string) collect($messages)->flatten()->first());
        }

        $stored = 0;
        $reused = 0;
        $reusedProcessing = 0;
        $reusedFailed = 0;
        $uploaded = [];
        $reusedPayload = [];
        $rejected = [];

        foreach ($received as $file) {
            $result = MediaUploadService::storeImage($project, $request->user(), $file);
            if ($result['ok']) {
                if ($result['reused']) {
                    $reused++;
                    $reusedPayload[] = $this->imagePayload($project, $result['media']);
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
                    $uploaded[] = $this->imagePayload($project, $result['media']);
                }
            } else {
                $rejected[] = ['name' => $file->getClientOriginalName() ?? 'file', 'error' => $result['error']];
            }
        }

        $this->bumpVersion($project);

        $message = $this->uploadSummary($stored, $reused, $reusedProcessing, $reusedFailed, $rejected);

        if ($stored === 0 && $reused === 0) {
            return $this->uploadFailure($request, $draft, [], $message, $rejected);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true, 'message' => $message,
                'counts' => ['uploaded' => $stored, 'reused' => $reused, 'rejected' => count($rejected)],
                'uploaded' => $uploaded, 'reused' => $reusedPayload, 'rejected' => $rejected,
            ]);
        }

        return back()->withInput($draft)->with('status', $message);
    }

    /**
     * Failed upload, both transports: the redirect carries the named
     * bag the form renders beside itself (plus the preserved draft);
     * the fetch path gets structured JSON. Neither claims storage.
     */
    private function uploadFailure(Request $request, array $draft, array $messages, string $message, array $rejected = []): RedirectResponse|JsonResponse
    {
        if ($request->wantsJson()) {
            return response()->json([
                'ok' => false, 'message' => $message,
                'errors' => $messages, 'rejected' => $rejected,
            ], 422);
        }

        if ($messages === []) {
            return back()->withInput($draft)->with('error', $message);
        }

        return back()->withInput($draft)->withErrors(new MessageBag($messages), 'upload');
    }

    /** Per-rule upload messages with filenames where available. */
    private function uploadRuleMessages(ValidatorContract $validator, array $files): array
    {
        $messages = [];
        foreach ($validator->failed() as $key => $rules) {
            foreach ($rules as $rule => $params) {
                $messages[$key][] = $this->uploadRuleMessage($key, $rule, $files);
            }
        }

        return $messages;
    }

    private function uploadRuleMessage(string $key, string $rule, array $files): string
    {
        if (preg_match('/^images\.(\d+)$/', $key, $match)) {
            $name = ($files[(int) $match[1]] ?? null)?->getClientOriginalName() ?? 'file';
            $limit = number_format(MediaUploadService::MAX_IMAGE_BYTES / 1048576, 0).' MB';

            return match ($rule) {
                'Max' => $name.' is too large — the limit is '.$limit.' per photo.',
                'File' => $name.' did not arrive as a readable file. Try again.',
                'Required' => $name.' did not arrive completely. Try again.',
                default => $name.': this file cannot be used.',
            };
        }

        $max = MediaUploadService::MAX_IMAGES_PER_REQUEST;

        return match ($rule) {
            'Max' => 'You sent '.count($files).' photos. Upload up to '.$max.' photos at a time. Split your selection into smaller batches.',
            'Required', 'Array', 'Min' => 'Choose at least one photo to upload.',
            default => 'The photo selection is invalid. Choose the photos again.',
        };
    }

    private function uploadSummary(int $stored, int $reused, int $reusedProcessing, int $reusedFailed, array $rejected): string
    {
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
        if ($rejected !== []) {
            $message .= ' Rejected: '.implode(' ', array_map(fn ($row) => ($row['name'] ?? 'file').': '.($row['error'] ?? 'rejected'), $rejected));
        }

        return trim($message);
    }

    /** Picker-ready payload; derivative URLs only when ready. */
    private function imagePayload(Project $project, ProjectMedia $media): array
    {
        $ready = $media->processing_status === ProjectMedia::STATUS_READY;

        return [
            'id' => $media->id,
            'title' => $media->title,
            'thumb_url' => $ready ? route('projects.media.show', [$project, $media, 'variant' => 'thumb']) : null,
            'card_url' => $ready ? route('projects.media.show', [$project, $media, 'variant' => 'card']) : null,
            'wide_url' => $ready ? route('projects.media.show', [$project, $media, 'variant' => 'wide']) : null,
            'status' => $media->processing_status,
        ];
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
        $this->assertBelongsToProject($project, $media);

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
        $this->assertBelongsToProject($project, $media);

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
        $this->assertBelongsToProject($project, $media);

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
        $this->assertBelongsToProject($project, $media);

        // Never silently break the story or cover: unlink explicitly.
        $unlinked = [];
        if ($project->cover_media_id === $media->id) {
            $project->forceFill(['cover_media_id' => null])->save();
            $unlinked[] = 'cover';
        }

        if ($project->hasRichStory() && in_array($media->id, StoryDocument::mediaIds($project->story_document), true)) {
            return back()->with('error', 'This image is used in the project story. Remove it from the story first — deleting it would break the story.');
        }

        $docsPage = $project->docPages()->get()->first(fn ($page): bool => in_array($media->id, $page->mediaIds(), true));
        if ($docsPage !== null) {
            return back()->with('error', 'This image is used in the documentation page “'.$docsPage->title.'”. Remove it there first — deleting it would break the page.');
        }

        // Retained revisions and finding references pin historical
        // evidence: deleting bytes they point at would silently
        // destroy cited sources and break later restoration.
        if (DocMediaUsage::inHistory($project->id, $media->id)) {
            return back()->with('error', 'This image is pinned by documentation history or findings. Historical evidence keeps its media — the bytes cannot be removed while a revision or finding references them.');
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
        $this->assertBelongsToProject($project, $media);
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
        $this->assertBelongsToProject($project, $media);
        abort_unless($media->kind === ProjectMedia::KIND_FILE, 404);

        return Storage::disk('local')->download(
            $media->originalPath(),
            $media->original_name,
            ['X-Content-Type-Options' => 'nosniff']
        );
    }

    private function bumpVersion(Project $project): void
    {
        $project->forceFill(['presentation_version' => $project->presentation_version + 1])->save();
    }
}
