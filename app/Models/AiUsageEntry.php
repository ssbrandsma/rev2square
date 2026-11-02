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

    public function featureLabel(): string
    {
        return match ($this->feature) {
            'repo_agent' => 'Agent chat',
            'upload_agent' => 'Agent file analysis',
            'revival_investigator' => 'Revival analysis',
            'architecture_investigator' => 'Architecture analysis',
            'connection_test' => 'Connection test',
            default => $this->feature !== null && $this->feature !== ''
                ? ucfirst(str_replace('_', ' ', $this->feature))
                : 'Unknown',
        };
    }

    public function operationLabel(): string
    {
        $operation = (string) $this->operation;

        if (preg_match('/^conversation-(\d+)$/', $operation, $match)) {
            return 'Conversation #'.$match[1];
        }
        if (preg_match('/^analysis-run-(\d+)$/', $operation, $match)) {
            return 'Analysis run #'.$match[1];
        }
        if (preg_match('/^architecture-snapshot-(\d+)$/', $operation, $match)) {
            return 'Architecture snapshot #'.$match[1];
        }
        if ($operation === 'connection-test') {
            return 'Connection test';
        }
        if ($operation === '' || $operation === 'unknown') {
            return 'Unknown operation';
        }

        return $operation;
    }

    public function conversationId(): ?int
    {
        if (! in_array($this->feature, ['repo_agent', 'upload_agent'], true)) {
            return null;
        }
        if (! preg_match('/^conversation-(\d+)$/', (string) $this->operation, $match)) {
            return null;
        }

        return (int) $match[1];
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_QUEUED => 'Queued',
            self::STATUS_SUCCEEDED => 'Succeeded',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_CANCELLED => 'Cancelled',
            default => 'Unknown',
        };
    }
}
