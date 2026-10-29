<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'project_id',
        'event',
        'subject_type',
        'subject_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

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

    public static function record(
        string $event,
        ?User $user,
        ?Project $project,
        ?Model $subject = null,
        array $metadata = [],
    ): self {
        return self::create([
            'user_id' => $user?->id,
            'project_id' => $project?->id,
            'event' => $event,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    /**
     * Resolve assignees named by task.assigned events in one query.
     *
     * @param  iterable<AuditEvent>  $events
     * @return array<int, array{name: string, url: string}> keyed by user id.
     */
    public static function assigneeMap(iterable $events): array
    {
        $ids = [];

        foreach ($events as $event) {
            if ($event->event === 'task.assigned' && is_int($event->metadata['assignee_id'] ?? null)) {
                $ids[] = $event->metadata['assignee_id'];
            }
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        return User::whereIn('id', $ids)->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => [
                'name' => $user->name,
                'url' => $user->profileUrl(),
            ]])
            ->all();
    }
}
