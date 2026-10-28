<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Repository extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'provider',
        'canonical_url',
        'owner',
        'name',
        'default_branch',
        'last_commit_sha',
        'import_status',
        'import_error',
        'import_started_at',
        'next_retry_at',
        'import_stage',
        'meta',
        'last_import_summary',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'last_import_summary' => 'array',
            'import_started_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<EvidenceSource, $this> */
    public function evidenceSources(): HasMany
    {
        return $this->hasMany(EvidenceSource::class);
    }

    /** @return HasMany<EvidenceSource, $this> */
    public function currentEvidence(): HasMany
    {
        return $this->hasMany(EvidenceSource::class)
            ->where('commit_sha', $this->last_commit_sha)
            ->orderBy('path');
    }

    /** @return HasMany<RepositoryFile, $this> */
    public function inventoryFiles(): HasMany
    {
        return $this->hasMany(RepositoryFile::class);
    }

    /** @return HasMany<RepositoryFile, $this> */
    public function currentInventory(): HasMany
    {
        return $this->hasMany(RepositoryFile::class)
            ->where('commit_sha', $this->last_commit_sha)
            ->orderBy('path');
    }

    public function isImporting(): bool
    {
        return $this->import_status === 'importing';
    }

    public function isReady(): bool
    {
        return $this->import_status === 'ready';
    }
}
