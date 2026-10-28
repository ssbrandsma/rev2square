<?php

namespace App\Models;

use App\Enums\DiscussionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DiscussionPost extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'user_id',
        'parent_id',
        'contributor_task_id',
        'type',
        'title',
        'body',
        'submission_key',
        'edited_at',
        'last_reply_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => DiscussionType::class,
            'edited_at' => 'datetime',
            'last_reply_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<DiscussionPost, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(DiscussionPost::class, 'parent_id');
    }

    /** @return HasMany<DiscussionPost, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(DiscussionPost::class, 'parent_id')->oldest();
    }

    public function thread(): self
    {
        return $this->parent ?? $this;
    }

    /** @return BelongsTo<ContributorTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ContributorTask::class, 'contributor_task_id');
    }

    /** @return MorphMany<Report, $this> */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    /** @return MorphMany<Follow, $this> */
    public function follows(): MorphMany
    {
        return $this->morphMany(Follow::class, 'followable');
    }

    public function isThread(): bool
    {
        return $this->parent_id === null;
    }

    public function isTombstone(): bool
    {
        return $this->trashed();
    }

    public function activityAt(): \DateTimeInterface
    {
        return $this->last_reply_at ?? $this->created_at;
    }
}
