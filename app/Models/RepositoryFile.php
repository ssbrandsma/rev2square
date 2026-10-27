<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepositoryFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'repository_id',
        'commit_sha',
        'path',
        'entry_hash',
        'extension',
        'language',
        'kind',
        'size_bytes',
        'blob_sha',
        'subsystem',
        'eligible',
        'skip_reason',
        'status',
        'relevance_score',
        'score_reasons',
        'evidence_source_id',
    ];

    protected function casts(): array
    {
        return [
            'eligible' => 'boolean',
            'score_reasons' => 'array',
        ];
    }

    /** @return BelongsTo<Repository, $this> */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /** @return BelongsTo<EvidenceSource, $this> */
    public function evidenceSource(): BelongsTo
    {
        return $this->belongsTo(EvidenceSource::class);
    }

    public static function entryHash(?int $repositoryId, ?string $path, ?string $sha): string
    {
        return hash('sha256', $repositoryId.'|'.$path.'|'.$sha);
    }
}
