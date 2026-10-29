<?php

namespace App\Services\Artifacts;

use App\Jobs\ExtractArtifactJob;
use App\Models\Project;
use App\Models\ProjectArtifact;
use App\Models\User;
use App\Services\Admin\AppSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Validates and stores project upload artifacts on the private disk.
 *
 * Content signatures decide the format; extensions and browser MIME
 * types are display hints only. Oversized, truncated, and unsupported
 * files fail predictably with actionable messages. Identical bytes
 * (same SHA-256) reuse the existing ready row instead of storing
 * and parsing a duplicate.
 */
final class ArtifactUploadService
{
    /**
     * @return array{ok: bool, artifact?: ProjectArtifact, error?: string, reused?: bool}
     */
    public static function store(Project $project, User $uploader, UploadedFile $file, bool $asRaw = false): array
    {
        if (! AppSettings::uploadsAllowed()) {
            return ['ok' => false, 'error' => 'Upload investigation is disabled on this installation.'];
        }

        $maxBytes = (int) config('artifacts.max_upload_bytes');
        $size = $file->getSize() ?? 0;

        if (! $file->isValid()) {
            return ['ok' => false, 'error' => 'The upload did not complete. Try again with a smaller file.'];
        }

        if ($size <= 0) {
            return ['ok' => false, 'error' => 'Empty files cannot be investigated. Choose a firmware binary or capture file.'];
        }

        if ($size > $maxBytes) {
            return ['ok' => false, 'error' => 'This file is '.self::humanBytes($size).'; the limit is '.self::humanBytes($maxBytes).'. Narrow the capture or split the input and try again.'];
        }

        $count = ProjectArtifact::where('project_id', $project->id)->count();
        if ($count >= (int) config('artifacts.max_artifacts_per_project')) {
            return ['ok' => false, 'error' => 'This project already stores the maximum number of artifacts. Delete an old one before uploading.'];
        }

        $bytes = @file_get_contents($file->getRealPath());
        if ($bytes === false || strlen($bytes) !== $size) {
            return ['ok' => false, 'error' => 'The uploaded file could not be read. Try uploading it again.'];
        }

        $sha256 = hash('sha256', $bytes);
        $format = ArtifactExtractor::detect($bytes, $file->getClientOriginalName());

        // Unrecognized bytes are rejected by default: only an explicit
        // raw-image declaration turns them into a needs-configuration
        // artifact (the maintainer then supplies processor + map).
        if ($format === ProjectArtifact::FORMAT_UNKNOWN) {
            if (! $asRaw) {
                return ['ok' => false, 'error' => 'This file was not recognized as a supported executable, firmware, or capture format. Tick “Treat as raw binary image” if it is a headerless ROM/flash dump you will configure manually.'];
            }
            $format = ProjectArtifact::FORMAT_RAW;
        }

        $existing = ProjectArtifact::where('project_id', $project->id)
            ->where('sha256', $sha256)
            ->whereIn('state', [ProjectArtifact::STATE_READY, ProjectArtifact::STATE_PARTIAL, ProjectArtifact::STATE_QUEUED, ProjectArtifact::STATE_EXTRACTING, ProjectArtifact::STATE_NEEDS_CONFIGURATION])
            ->first();

        if ($existing !== null) {
            return ['ok' => true, 'artifact' => $existing, 'reused' => true];
        }

        // Never trust the client filename for storage: generated key only.
        // The original name survives as escaped display metadata.
        $originalName = Str::limit(pathinfo($file->getClientOriginalName() ?? 'upload', PATHINFO_BASENAME) ?: 'upload', 255, '');
        $storageKey = Str::random(40);

        $artifact = ProjectArtifact::create([
            'project_id' => $project->id,
            'uploader_id' => $uploader->id,
            'original_name' => $originalName,
            'byte_size' => $size,
            'sha256' => $sha256,
            'storage_key' => $storageKey,
            'format' => $format,
            'state' => ProjectArtifact::STATE_QUEUED,
        ]);

        Storage::disk('artifacts')->put($artifact->rawPath(), $bytes);

        ExtractArtifactJob::dispatch($artifact->id);

        return ['ok' => true, 'artifact' => $artifact, 'reused' => false];
    }

    public static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0).' KB';
        }

        return $bytes.' bytes';
    }
}
