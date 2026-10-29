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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

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
        'discussion_open',
    ];

    protected function casts(): array
    {
        return [
            'phase' => ProjectPhase::class,
            'activity' => ProjectActivity::class,
            'publication_status' => PublicationStatus::class,
            'discussion_open' => 'boolean',
            'story_document' => 'array',
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

    /**
     * Historical creator. Defaults to the owner at creation; ownership
     * transfers (not yet implemented) must leave this untouched.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<SavedProject, $this> */
    public function saves(): HasMany
    {
        return $this->hasMany(SavedProject::class);
    }

    protected static function booted(): void
    {
        static::creating(function (Project $project): void {
            $project->created_by_user_id ??= $project->owner_id;
        });
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

    /** @return HasOne<Repository, $this> */
    public function repository(): HasOne
    {
        return $this->hasOne(Repository::class)->latestOfMany();
    }

    /** @return HasMany<AnalysisRun, $this> */
    public function analysisRuns(): HasMany
    {
        return $this->hasMany(AnalysisRun::class);
    }

    /** @return HasMany<ProjectArtifact, $this> */
    public function artifacts(): HasMany
    {
        return $this->hasMany(ProjectArtifact::class);
    }

    /** @return HasMany<AgentConversation, $this> */
    public function agentConversations(): HasMany
    {
        return $this->hasMany(AgentConversation::class);
    }

    /** @return HasMany<ProjectMedia, $this> */
    public function media(): HasMany
    {
        return $this->hasMany(ProjectMedia::class)->orderBy('id');
    }

    /** @return HasMany<ProjectMedia, $this> */
    public function gallery(): HasMany
    {
        return $this->hasMany(ProjectMedia::class)
            ->where('kind', ProjectMedia::KIND_IMAGE)
            ->where('in_gallery', true)
            ->orderBy('gallery_order')
            ->orderBy('id');
    }

    /** @return BelongsTo<ProjectMedia, $this> */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(ProjectMedia::class, 'cover_media_id');
    }

    /** The rich document is authoritative only when explicitly set. */
    public function hasRichStory(): bool
    {
        return $this->story_format === 'rich' && is_array($this->story_document);
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

    /** @return HasMany<DiscussionPost, $this> */
    public function discussionPosts(): HasMany
    {
        return $this->hasMany(DiscussionPost::class);
    }

    /** @return HasMany<DiscussionPost, $this> */
    public function discussionThreads(): HasMany
    {
        return $this->hasMany(DiscussionPost::class)->whereNull('parent_id');
    }

    /** @return HasMany<ChatMessage, $this> */
    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    /** @return MorphMany<Follow, $this> */
    public function follows(): MorphMany
    {
        return $this->morphMany(Follow::class, 'followable');
    }

    /** @return HasMany<Observation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }

    /** @return HasMany<TestReport, $this> */
    public function testReports(): HasMany
    {
        return $this->hasMany(TestReport::class);
    }

    /** @param Builder<$this> $query */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('publication_status', PublicationStatus::Published->value);
    }

    /**
     * Homepage feature policy: published, not archived, and investigating a
     * concrete linked device — matching the "Real devices" heading. Newest
     * first; fewer than two eligible projects simply shows fewer cards.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeFeaturable(Builder $query): Builder
    {
        return $query->published()
            ->where('activity', '!=', ProjectActivity::Archived->value)
            ->whereHas('devices');
    }

    public function isPublished(): bool
    {
        return $this->publication_status === PublicationStatus::Published;
    }

    /**
     * Owner plus active owner/maintainer members: everyone who should hear
     * maintainer-side events (new offers, verification requests, ...).
     *
     * @return Collection<int, User>
     */
    public function managers(): Collection
    {
        $users = collect([$this->owner])->filter();

        $memberUsers = $this->members()->with('user')
            ->where('status', 'active')
            ->whereIn('role', [ProjectRole::Owner->value, ProjectRole::Maintainer->value])
            ->get()
            ->pluck('user')
            ->filter();

        return $users->concat($memberUsers)->unique('id')->values();
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
