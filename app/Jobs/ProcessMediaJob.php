<?php

namespace App\Jobs;

use App\Models\ProjectMedia;
use App\Services\Media\ImageProcessor;
use App\Services\Media\UnprocessableImageException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public readonly int $mediaId) {}

    public function handle(): void
    {
        $media = ProjectMedia::find($this->mediaId);

        if ($media === null || $media->processing_status !== ProjectMedia::STATUS_PROCESSING) {
            return;
        }

        try {
            ImageProcessor::process($media->fresh());
        } catch (UnprocessableImageException $e) {
            // Permanent: the bytes can never decode. Record the code
            // for actionable messaging and drop the job quietly —
            // expected validation failures are not operator incidents.
            $media->forceFill([
                'processing_status' => ProjectMedia::STATUS_FAILED,
                'processing_error' => $e->errorCode,
            ])->save();

            $this->delete();
        }
        // Any other throwable (missing GD on the worker, storage or
        // queue fault) propagates: the worker retries with backoff,
        // and failed() records a retryable reason after exhaustion.
    }

    /**
     * Runs after all attempts are exhausted. Marks the row failed
     * with a retryable reason — the image itself was never proven
     * bad. Never overwrites a terminal classification.
     */
    public function failed(\Throwable $e): void
    {
        ProjectMedia::where('id', $this->mediaId)
            ->where('processing_status', ProjectMedia::STATUS_PROCESSING)
            ->update([
                'processing_status' => ProjectMedia::STATUS_FAILED,
                'processing_error' => ProjectMedia::ERROR_PROCESSING_UNAVAILABLE,
            ]);
    }
}
