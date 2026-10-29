<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One presentation-media row: an image (cover/story/gallery) or a
 * supporting file. Never parsed as research evidence, never sent to
 * AI providers. Visibility follows the parent project.
 */
class ProjectMedia extends Model
{
    public const KIND_IMAGE = 'image';

    public const KIND_FILE = 'file';

    public const STATUS_READY = 'ready';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_FAILED = 'failed';

    // processing_error codes. Only RETRYABLE_ERRORS (plus legacy
    // NULL rows, which predate classification) may be retried.
    public const ERROR_INVALID_IMAGE = 'invalid_image';

    public const ERROR_MISSING_ORIGINAL = 'missing_original';

    public const ERROR_PROCESSING_UNAVAILABLE = 'processing_unavailable';

    public const RETRYABLE_ERRORS = [self::ERROR_PROCESSING_UNAVAILABLE];

    protected $fillable = [
        'project_id', 'user_id', 'kind', 'category', 'storage_key',
        'original_name', 'mime', 'size', 'width', 'height', 'sha256', 'original_sha256',
        'title', 'caption', 'alt', 'credit', 'license',
        'focal_x', 'focal_y', 'gallery_order', 'in_gallery',
        'processing_status', 'processing_error',
    ];

    protected function casts(): array
    {
        return ['in_gallery' => 'boolean'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isImage(): bool
    {
        return $this->kind === self::KIND_IMAGE;
    }

    public function isReady(): bool
    {
        return $this->processing_status === self::STATUS_READY;
    }

    public function isFailed(): bool
    {
        return $this->processing_status === self::STATUS_FAILED;
    }

    /**
     * Whether a manual retry may recover this row. Permanent
     * validation failures are refused; legacy rows without a code
     * are allowed one classified retry attempt.
     */
    public function isRetryable(): bool
    {
        return $this->isFailed()
            && $this->isImage()
            && ($this->processing_error === null || in_array($this->processing_error, self::RETRYABLE_ERRORS, true));
    }

    /** Safe user-facing failure explanation. Never leaks paths or traces. */
    public function processingErrorMessage(): ?string
    {
        if (! $this->isFailed()) {
            return null;
        }

        return match ($this->processing_error) {
            self::ERROR_INVALID_IMAGE => 'This file could not be decoded as an image. Delete it and upload a valid JPEG, PNG, or WebP file.',
            self::ERROR_MISSING_ORIGINAL => 'The stored file is missing. Delete this entry and upload the image again.',
            default => 'Image processing is temporarily unavailable (the background worker could not process this file). Use “Retry processing” — if it keeps failing, contact the site operator.',
        };
    }

    public function displayTitle(): string
    {
        return $this->title ?: $this->original_name;
    }

    public function altText(): string
    {
        return $this->alt ?: $this->displayTitle();
    }

    /** CSS object-position expressing the focal point. */
    public function focalPosition(): string
    {
        return $this->focal_x.'% '.$this->focal_y.'%';
    }

    public function originalPath(): string
    {
        return 'media/'.$this->storage_key.'.bin';
    }

    public function variantPath(string $variant): string
    {
        return 'media/'.$this->storage_key.'.'.$variant.'.jpg';
    }
}
