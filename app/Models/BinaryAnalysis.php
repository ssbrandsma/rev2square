<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One deterministic binary-analysis run over an artifact: adapter,
 * target configuration, lifecycle state, and a pointer to the
 * evidence document on the private artifacts disk.
 *
 * Provenance (adapter identity + version + target config) is part of
 * the row so viewers and citations stay valid when backends change.
 * AI annotations live in conversations, never in this evidence.
 */
class BinaryAnalysis extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_ANALYZING = 'analyzing';

    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** Failure codes a maintainer may retry. */
    public const RETRYABLE_ERRORS = [
        'resource_exhausted',
        'backend_crash',
        'backend_timeout',
        'environmental',
        'job_failed',
        'cancelled_race',
        // Storage outages (missing mount, restore in progress): the bytes
        // may come back, so retry stays available after a restore.
        'missing_original',
        'storage_unreadable',
    ];

    protected $fillable = [
        'project_artifact_id',
        'requested_by',
        'adapter',
        'adapter_version',
        'target',
        'config',
        'overrides',
        'status',
        'progress_note',
        'error_code',
        'error_message',
        'evidence_key',
        'dedupe_key',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'overrides' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProjectArtifact, $this> */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(ProjectArtifact::class, 'project_artifact_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_READY, self::STATUS_PARTIAL,
            self::STATUS_FAILED, self::STATUS_CANCELLED,
        ], true);
    }

    public function isUsable(): bool
    {
        return in_array($this->status, [self::STATUS_READY, self::STATUS_PARTIAL], true);
    }

    public function isRetryable(): bool
    {
        return $this->status === self::STATUS_FAILED
            && in_array((string) $this->error_code, self::RETRYABLE_ERRORS, true);
    }

    public function evidencePath(): ?string
    {
        if ($this->evidence_key === null) {
            return null;
        }

        return $this->artifact->project_id.'/'.$this->evidence_key.'.analysis.json';
    }

    /** @return array<string, mixed>|null */
    public function readEvidence(): ?array
    {
        $path = $this->evidencePath();
        if ($path === null) {
            return null;
        }

        $disk = Storage::disk('artifacts');
        if (! $disk->exists($path)) {
            return null;
        }

        $decoded = json_decode($disk->get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_QUEUED => 'Queued',
            self::STATUS_ANALYZING => 'Analyzing',
            self::STATUS_READY => 'Ready',
            self::STATUS_PARTIAL => 'Partial',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_CANCELLED => 'Cancelled',
            default => $this->status,
        };
    }

    /**
     * Idempotency key: same artifact bytes + adapter/version + target
     * config reuses the existing run instead of dispatching a twin.
     */
    public static function dedupeKey(ProjectArtifact $artifact, string $adapter, ?string $version, ?string $target, array $config): string
    {
        return hash('sha256', implode("\n", [
            $artifact->sha256, $adapter, (string) $version, (string) $target,
            json_encode($config) ?: '{}',
        ]));
    }
}
