<?php

namespace App\Services\Media;

use App\Jobs\ProcessMediaJob;
use App\Models\Project;
use App\Models\ProjectMedia;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Presentation-media uploads: allowlisted types, per-project dedupe by
 * SHA-256 (never cross-project, so dedupe cannot probe other
 * projects' private files), generated storage keys, and queued
 * derivative processing. Originals are re-encoded on processing to
 * strip EXIF/location metadata.
 */
final class MediaUploadService
{
    public const MAX_IMAGE_BYTES = 12 * 1024 * 1024;

    public const MAX_FILE_BYTES = 25 * 1024 * 1024;

    public const MAX_DIMENSION = 8000;

    // Pixels cap against decompression bombs (before GD allocates).
    public const MAX_PIXELS = 40_000_000;

    public const MAX_PER_PROJECT = 200;

    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const FILE_MIMES = [
        'application/pdf',
        'text/plain',
        'text/markdown',
        'text/csv',
        'application/json',
    ];

    public const FILE_EXTENSIONS = [
        'pdf', 'txt', 'log', 'md', 'markdown', 'csv', 'json',
    ];

    /** @return array{ok: bool, media?: ProjectMedia, error?: string, reused?: bool} */
    public static function storeImage(Project $project, User $uploader, UploadedFile $file): array
    {
        $check = self::precheck($project, $file, self::MAX_IMAGE_BYTES);
        if ($check !== null) {
            return $check;
        }

        $mime = (string) $file->getMimeType();
        if (! in_array($mime, self::IMAGE_MIMES, true)) {
            return ['ok' => false, 'error' => 'Only JPEG, PNG, and WebP images are supported.'];
        }

        $size = @getimagesize($file->getRealPath());
        if ($size === false) {
            return ['ok' => false, 'error' => 'This file is not a readable image.'];
        }
        [$width, $height] = $size;

        if ($width <= 0 || $height <= 0 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            return ['ok' => false, 'error' => 'Images must be between 1px and '.self::MAX_DIMENSION.'px per side.'];
        }
        if ($width * $height > self::MAX_PIXELS) {
            return ['ok' => false, 'error' => 'This image has too many pixels to process safely.'];
        }

        return self::persist($project, $uploader, $file, $mime, ProjectMedia::KIND_IMAGE, 'photo', $width, $height, true);
    }

    /** @return array{ok: bool, media?: ProjectMedia, error?: string, reused?: bool} */
    public static function storeFile(Project $project, User $uploader, UploadedFile $file, string $category): array
    {
        $check = self::precheck($project, $file, self::MAX_FILE_BYTES);
        if ($check !== null) {
            return $check;
        }

        $mime = (string) $file->getMimeType();
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! in_array($mime, self::FILE_MIMES, true) || ! in_array($extension, self::FILE_EXTENSIONS, true)) {
            return ['ok' => false, 'error' => 'Supporting files must be PDF, TXT, LOG, Markdown, CSV, or JSON (content and extension must agree).'];
        }

        if (! in_array($category, ['documentation', 'schematics', 'other'], true)) {
            $category = 'other';
        }

        return self::persist($project, $uploader, $file, $mime, ProjectMedia::KIND_FILE, $category, null, null, false);
    }

    /** @return array{ok: bool, error: string}|null */
    private static function precheck(Project $project, UploadedFile $file, int $maxBytes): ?array
    {
        if (! $file->isValid()) {
            return ['ok' => false, 'error' => 'The upload did not complete. Try again with a smaller file.'];
        }

        $size = $file->getSize() ?? 0;
        if ($size <= 0) {
            return ['ok' => false, 'error' => 'Empty files cannot be added.'];
        }
        if ($size > $maxBytes) {
            return ['ok' => false, 'error' => 'This file is too large (limit '.number_format($maxBytes / 1048576, 0).' MB).'];
        }

        if (ProjectMedia::where('project_id', $project->id)->count() >= self::MAX_PER_PROJECT) {
            return ['ok' => false, 'error' => 'This project already has the maximum number of media items.'];
        }

        return null;
    }

    /** @return array{ok: bool, media?: ProjectMedia, error?: string, reused?: bool} */
    private static function persist(
        Project $project,
        User $uploader,
        UploadedFile $file,
        string $mime,
        string $kind,
        string $category,
        ?int $width,
        ?int $height,
        bool $process,
    ): array {
        $bytes = @file_get_contents($file->getRealPath());
        if ($bytes === false) {
            return ['ok' => false, 'error' => 'The uploaded file could not be read.'];
        }

        $sha256 = hash('sha256', $bytes);

        // Same-project dedupe only: reuse avoids duplicate storage
        // without ever revealing another project's private files.
        $existing = ProjectMedia::where('project_id', $project->id)
            ->where('original_sha256', $sha256)
            ->where('kind', $kind)
            ->first();

        if ($existing !== null) {
            return ['ok' => true, 'media' => $existing, 'reused' => true];
        }

        $media = ProjectMedia::create([
            'project_id' => $project->id,
            'user_id' => $uploader->id,
            'kind' => $kind,
            'category' => $category,
            'storage_key' => Str::random(40),
            'original_name' => Str::limit(pathinfo($file->getClientOriginalName() ?? 'upload', PATHINFO_BASENAME) ?: 'upload', 255, ''),
            'mime' => $mime,
            'size' => strlen($bytes),
            'width' => $width,
            'height' => $height,
            'sha256' => $sha256,
            'original_sha256' => $sha256,
            'processing_status' => $process ? ProjectMedia::STATUS_PROCESSING : ProjectMedia::STATUS_READY,
        ]);

        Storage::disk('local')->put($media->originalPath(), $bytes);

        if ($process) {
            ProcessMediaJob::dispatch($media->id);
        }

        return ['ok' => true, 'media' => $media, 'reused' => false];
    }
}
