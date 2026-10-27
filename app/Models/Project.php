<?php

namespace App\Models;

use App\Enums\ProjectActivity;
use App\Enums\ProjectPhase;
use App\Enums\ProjectRole;
use App\Enums\PublicationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'owner_id',
        'title',
        'pitch',
        'body',
        'phase',
        'activity',
        'publication_status',
    ];

    protected function casts(): array
    {
        return [
            'phase' => ProjectPhase::class,
            'activity' => ProjectActivity::class,
            'publication_status' => PublicationStatus::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsToMany<Device, $this> */
    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class, 'project_devices')
            ->withPivot('device_variant_id');
    }

    /** @return HasMany<ProjectMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /** @return HasMany<Repository, $this> */
    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    /** @return HasMany<AnalysisRun, $this> */
    public function analysisRuns(): HasMany
    {
        return $this->hasMany(AnalysisRun::class);
    }

    /** @return HasMany<AnalysisReport, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(AnalysisReport::class)->orderByDesc('version_number');
    }

    /** @return HasMany<ContributionOffer, $this> */
    public function offers(): HasMany
    {
        return $this->hasMany(ContributionOffer::class);
    }

    /** @return HasMany<Milestone, $this> */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->ordered();
    }

    /** @return HasMany<ContributorTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(ContributorTask::class);
    }

    /** @param Builder<$this> $query */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('publication_status', PublicationStatus::Published->value);
    }

    public function isPublished(): bool
    {
        return $this->publication_status === PublicationStatus::Published;
    }

    public function managedBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->owner_id === $user->id) {
            return true;
        }

        return $this->members()
            ->where('user_id', $user->id)
            ->whereIn('role', [ProjectRole::Owner->value, ProjectRole::Maintainer->value])
            ->where('status', 'active')
            ->exists();
    }
}
