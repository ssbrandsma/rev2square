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
            return ['ok' => false, 'error' => 'The upload did not complete. Try again.'];
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

        $realPath = $file->getRealPath();
        $sha256 = @hash_file('sha256', $realPath);
        if ($sha256 === false || ($actual = @filesize($realPath)) === false || $actual !== $size) {
            return ['ok' => false, 'error' => 'The uploaded file could not be read. Try uploading it again.'];
        }

        // Single necessary in-memory pass: format detection needs
        // arbitrary offsets (PE headers, ZIP central directory). Hashing
        // and storage stream, so peak memory stays near one copy.
        $bytes = @file_get_contents($realPath);
        if ($bytes === false || strlen($bytes) !== $size) {
            return ['ok' => false, 'error' => 'The uploaded file could not be read. Try uploading it again.'];
        }
        $format = ArtifactExtractor::detect($bytes, $file->getClientOriginalName());
        unset($bytes);

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

        $stream = @fopen($realPath, 'rb');
        if ($stream === false) {
            $artifact->delete();
            return ['ok' => false, 'error' => 'The uploaded file could not be read. Try uploading it again.'];
        }
        try {
            Storage::disk('artifacts')->put($artifact->rawPath(), $stream);
        } catch (\Throwable) {
            Storage::disk('artifacts')->delete($artifact->rawPath());
            $artifact->delete();
            return ['ok' => false, 'error' => 'The uploaded file could not be stored. Try uploading it again.'];
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        unset($stream);

        if (Storage::disk('artifacts')->size($artifact->rawPath()) !== $size) {
            Storage::disk('artifacts')->delete($artifact->rawPath());
            $artifact->delete();
            return ['ok' => false, 'error' => 'The uploaded file was stored incompletely. Try uploading it again.'];
        }

        ExtractArtifactJob::dispatch($artifact->id);

        return ['ok' => true, 'artifact' => $artifact, 'reused' => false];
    }

    /**
     * Store a batch of uploaded files, collecting per-file outcomes.
     *
     * @param  iterable<mixed>  $files
     * @return array{stored: list<ProjectArtifact>, reused: int, errors: list<string>}
     */
    public static function storeBatch(Project $project, User $uploader, iterable $files, bool $asRaw = false): array
    {
        $maxBytes = (int) config('artifacts.max_upload_bytes');
        $stored = [];
        $reused = 0;
        $errors = [];

        foreach ($files as $file) {
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
                $errors[] = $name.': this file is '.self::humanBytes((int) $file->getSize())
                    .'; the limit is '.self::humanBytes($maxBytes).'. Narrow the capture or split the input and try again.';

                continue;
            }

            $result = self::store($project, $uploader, $file, $asRaw);

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

        return ['stored' => $stored, 'reused' => $reused, 'errors' => $errors];
    }

    /**
     * @param  list<ProjectArtifact>  $stored
     * @param  list<string>  $errors
     */
    public static function resultMessage(array $stored, int $reused, array $errors): string
    {
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

        return 'Upload result: '.implode('; ', $parts).'.';
    }

    /**
     * The post_max_size limit in bytes when the request body exceeds it
     * (PHP then empties the request), null otherwise.
     */
    public static function exceededPostLimit(int $contentLength): ?int
    {
        $postMax = self::phpBytes((string) ini_get('post_max_size'));

        return $postMax > 0 && $contentLength > $postMax ? $postMax : null;
    }

    public static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1).' MiB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0).' KiB';
        }

        return $bytes.' bytes';
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
                .self::humanBytes($maxBytes).'. Narrow the capture or split the input and try again.',
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
