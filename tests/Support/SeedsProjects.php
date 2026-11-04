<?php

namespace Tests\Support;

use App\Models\Project;
use App\Models\ProjectArtifact;
use App\Models\User;
use App\Services\Artifacts\ArtifactExtractor;
use Illuminate\Support\Facades\Storage;

/**
 * Shared binary-analysis fixtures: a draft project owned by a fresh
 * user, and raw-bytes artifact storage with extraction.
 */
trait SeedsProjects
{
    /** @return array{Project, User} */
    protected function seedProject(): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->draft()->create(['owner_id' => $owner->id]);

        return [$project->fresh(), $owner];
    }

    protected function storeBytes(Project $project, User $uploader, string $bytes, string $name, ?string $format = null): ProjectArtifact
    {
        $artifact = ProjectArtifact::create([
            'project_id' => $project->id,
            'uploader_id' => $uploader->id,
            'original_name' => $name,
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'storage_key' => str()->random(40),
            // Explicit format mirrors the upload "treat as raw" opt-in
            // for unrecognized bytes (without it they fail extraction).
            'format' => $format ?? ArtifactExtractor::detect($bytes, $name),
            'state' => ProjectArtifact::STATE_QUEUED,
        ]);
        Storage::disk('artifacts')->put($artifact->rawPath(), $bytes);
        ArtifactExtractor::extract($artifact->fresh());

        return $artifact->fresh();
    }
}
