<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One auditable AI provider attempt. Metadata only: never prompts,
 * completions, credentials, or uploaded payloads.
 */
class AiUsageEntry extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_UNKNOWN = 'unknown';

    protected $fillable = [
        'idempotency_key', 'user_id', 'project_id', 'feature', 'operation',
        'attempt_seq', 'provider', 'model', 'status', 'input_tokens',
        'output_tokens', 'cached_tokens', 'reasoning_tokens', 'duration_ms',
        'correlation_id', 'estimated_cost_micros', 'pricing_version', 'error_code',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tokenTotal(): ?int
    {
        if ($this->input_tokens === null && $this->output_tokens === null) {
            return null;
        }

        return (int) ($this->input_tokens ?? 0) + (int) ($this->output_tokens ?? 0);
    }
}
