<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvidenceSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'analysis_run_id',
        'repository_id',
        'source_type',
        'url',
        'path',
        'commit_sha',
        'content_hash',
        'identity_hash',
        'content',
        'is_partial',
        'line_ranges',
        'original_bytes',
        'original_lines',
        'relevance_score',
        'language',
        'blob_sha',
        'extraction_note',
        'visibility',
        'fetched_at',
        'ingestion_status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
            'is_partial' => 'boolean',
            'line_ranges' => 'array',
        ];
    }

    public function isPartial(): bool
    {
        return (bool) $this->is_partial;
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Repository, $this> */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public static function identityHash(?int $repositoryId, ?string $path, ?string $sha): string
    {
        return hash('sha256', $repositoryId.'|'.$path.'|'.$sha);
    }

    public function byteSize(): int
    {
        return strlen((string) $this->content);
    }
}
