<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * Append-only administrative audit record. There is intentionally no
 * update/delete path anywhere in the application.
 */
class AdminAuditEvent extends Model
{
    protected $fillable = [
        'actor_id', 'action', 'outcome', 'target_type', 'target_id',
        'reason', 'before', 'after', 'correlation_id',
    ];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return MorphTo<Model, $this> */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Admin detail page for linkable targets. Only User and Project
     * targets link; anything else renders as plain text.
     */
    public function targetAdminUrl(): ?string
    {
        if ($this->target_type === (new User)->getMorphClass()) {
            return route('admin.users.show', $this->target_id);
        }

        if ($this->target_type === (new Project)->getMorphClass()) {
            return route('admin.projects.show', $this->target_id);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(
        string $action,
        ?User $actor,
        ?Model $target = null,
        ?string $reason = null,
        ?array $before = null,
        ?array $after = null,
        string $outcome = 'success',
    ): self {
        return self::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'outcome' => $outcome,
            'target_type' => $target?->getMorphClass(),
            'target_id' => $target?->getKey(),
            'reason' => $reason !== null ? substr($reason, 0, 2000) : null,
            'before' => $before,
            'after' => $after,
            'correlation_id' => 'adm-'.Str::random(12),
        ]);
    }

    /**
     * Secret changes record THAT a secret changed plus non-secret
     * metadata only. Callers must never pass secret values here.
     *
     * @param  array<string, mixed>  $metadata  Non-secret metadata only.
     */
    public static function recordSecretChange(string $action, ?User $actor, array $metadata = [], ?string $reason = null): self
    {
        return self::record($action, $actor, null, $reason, null, $metadata);
    }
}
