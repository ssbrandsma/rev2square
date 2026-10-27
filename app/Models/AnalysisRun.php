<?php

namespace App\Models;

use App\Enums\AnalysisStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AnalysisRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'user_id',
        'repository_id',
        'status',
        'provider',
        'model',
        'schema_version',
        'input_manifest',
        'input_manifest_hash',
        'token_usage',
        'error_code',
        'error_message',
        'duration_ms',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AnalysisStatus::class,
            'input_manifest' => 'array',
            'token_usage' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Repository, $this> */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /** @return HasOne<AnalysisReport, $this> */
    public function report(): HasOne
    {
        return $this->hasOne(AnalysisReport::class);
    }
}
