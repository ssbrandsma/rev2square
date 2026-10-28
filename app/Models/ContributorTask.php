<?php

namespace App\Models;

use App\Enums\ContentOrigin;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContributorTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'milestone_id',
        'title',
        'description',
        'skills',
        'status',
        'assigned_to_user_id',
        'definition_of_done',
        'requires_hardware',
        'import_key',
        'origin_report_id',
        'last_seen_report_id',
        'manually_edited',
        'origin',
    ];

    protected function casts(): array
    {
        return [
            'skills' => 'array',
            'status' => TaskStatus::class,
            'requires_hardware' => 'boolean',
            'manually_edited' => 'boolean',
            'origin' => ContentOrigin::class,
        ];
    }

    /** @param Builder<ContributorTask> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', TaskStatus::Retired);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Milestone, $this> */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** @return HasMany<DiscussionPost, $this> */
    public function discussionPosts(): HasMany
    {
        return $this->hasMany(DiscussionPost::class, 'contributor_task_id');
    }

    /** @return list<string> */
    public function skillTags(): array
    {
        return array_values(array_filter((array) ($this->skills ?? [])));
    }
}
