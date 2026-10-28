<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Observation extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'user_id',
        'contributor_task_id',
        'device_variant_id',
        'title',
        'body',
        'source_url',
        'verified_at',
        'verified_by_user_id',
    ];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
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

    /** @return BelongsTo<ContributorTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ContributorTask::class, 'contributor_task_id');
    }

    /** @return BelongsTo<DeviceVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(DeviceVariant::class, 'device_variant_id');
    }

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /** @return HasMany<ObservationCorrection, $this> */
    public function corrections(): HasMany
    {
        return $this->hasMany(ObservationCorrection::class);
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function variantLabel(): ?string
    {
        return $this->variant?->displayLabel();
    }
}
