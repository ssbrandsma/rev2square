<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A private Revival Agent dialogue about one project.
 *
 * Conversations are never published automatically: the author and the
 * project maintainers can read them; anything worth keeping becomes a
 * project record only through an explicit, authorized user action.
 */
class AgentConversation extends Model
{
    protected $fillable = [
        'project_id',
        'user_id',
        'title',
        'artifact_ids',
        'device_context',
        'artifact_consent_at',
    ];

    protected function casts(): array
    {
        return [
            'artifact_ids' => 'array',
            'artifact_consent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<AgentMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(AgentMessage::class)->orderBy('id');
    }

    public function isAccessibleBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->user_id === $user->id || $this->project->managedBy($user);
    }
}
