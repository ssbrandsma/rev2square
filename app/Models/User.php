<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'show_presence' => 'boolean',
            'notification_preferences' => 'array',
            'skills' => 'array',
            'links' => 'array',
        ];
    }

    /**
     * Initials for avatars until the profile prompt adds pictures.
     */
    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];

        $initials = mb_strtoupper(
            mb_substr($parts[0] ?? '?', 0, 1).(isset($parts[1]) ? mb_substr($parts[1], 0, 1) : '')
        );

        return $initials !== '' ? $initials : '?';
    }

    /**
     * Notification opt-ins with per-group defaults. Replies, mentions, and
     * rare official updates default on; ordinary new-post alerts default
     * off so following a busy project stays quiet.
     */
    public function wantsNotification(string $key): bool
    {
        $defaults = [
            'discussion_replies' => true,
            'mentions' => true,
            'followed_posts' => false,
            'project_updates' => true,
        ];

        return (bool) (($this->notification_preferences ?? [])[$key] ?? $defaults[$key] ?? true);
    }

    /** @return HasMany<Project, $this> */
    public function ownedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'owner_id');
    }

    /** @return HasMany<ContributionOffer, $this> */
    public function offers(): HasMany
    {
        return $this->hasMany(ContributionOffer::class);
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

    /**
     * Historical creator attribution. Ownership may move; this stays put.
     *
     * @return HasMany<Project, $this>
     */
    public function createdProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'created_by_user_id');
    }

    /** @return HasMany<ProjectMember, $this> */
    public function projectMemberships(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /** @return HasMany<ProjectMember, $this> */
    public function activeMemberships(): HasMany
    {
        return $this->projectMemberships()->where('status', 'active');
    }

    /** @return HasMany<SavedProject, $this> */
    public function savedProjects(): HasMany
    {
        return $this->hasMany(SavedProject::class);
    }

    /** @return HasMany<UserHandle, $this> */
    public function previousHandles(): HasMany
    {
        return $this->hasMany(UserHandle::class);
    }

    /**
     * Canonical public profile URL: the handle route when set, otherwise
     * the stable id route. Old handles keep resolving via user_handles.
     */
    public function profileUrl(): string
    {
        return $this->handle !== null
            ? route('users.handle', $this->handle)
            : route('users.show', $this);
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path !== null ? route('avatars.show', $this) : null;
    }

    /** @return list<string> */
    public function skillList(): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $skill): string => trim((string) $skill),
            $this->skills ?? []
        )));
    }

    /** @return list<array{label: string, url: string}> */
    public function linkList(): array
    {
        $links = [];

        foreach ($this->links ?? [] as $link) {
            $url = trim((string) ($link['url'] ?? ''));
            $label = trim((string) ($link['label'] ?? ''));

            if ($url === '') {
                continue;
            }

            $links[] = ['label' => $label !== '' ? $label : $url, 'url' => $url];
        }

        return $links;
    }
}
