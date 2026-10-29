<?php

namespace App\Jobs;

use App\Models\ProjectArtifact;
use App\Services\Artifacts\ArtifactExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExtractArtifactJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $artifactId)
    {
        $this->timeout = (int) config('artifacts.extraction_timeout_seconds', 60);
    }

    public function handle(): void
    {
        $artifact = ProjectArtifact::find($this->artifactId);

        if ($artifact === null || $artifact->isTerminal()) {
            return;
        }

        $uploader = $artifact->uploader;
        if ($uploader !== null && $uploader->isSuspended()) {
            $artifact->forceFill([
                'state' => ProjectArtifact::STATE_FAILED,
                'failure_code' => 'access_revoked',
                'failure_message' => 'The uploader account was suspended before extraction ran.',
                'extracted_at' => now(),
            ])->save();

            return;
        }

        ArtifactExtractor::extract($artifact);
    }

    public function failed(): void
    {
        $artifact = ProjectArtifact::find($this->artifactId);

        if ($artifact !== null && ! $artifact->isTerminal()) {
            $artifact->forceFill([
                'state' => ProjectArtifact::STATE_FAILED,
                'failure_code' => 'job_failed',
                'failure_message' => 'Extraction did not finish (worker timeout or crash). Retry from the artifact page.',
                'extracted_at' => now(),
            ])->save();
        }
    }
}
