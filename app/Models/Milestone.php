<?php

namespace App\Models;

use App\Enums\MilestoneStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Milestone extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'title',
        'description',
        'status',
        'sort_order',
        'target_date',
        'import_key',
        'origin_report_id',
        'last_seen_report_id',
        'manually_edited',
    ];

    protected function casts(): array
    {
        return [
            'status' => MilestoneStatus::class,
            'target_date' => 'date',
            'manually_edited' => 'boolean',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<ContributorTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(ContributorTask::class);
    }

    /** @param Builder<$this> $query */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
