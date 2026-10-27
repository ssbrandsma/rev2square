<?php

namespace App\Models;

use App\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'analysis_run_id',
        'project_id',
        'version_number',
        'supersedes_report_id',
        'payload_json',
        'review_status',
        'review_notes',
        'reviewer_id',
        'reviewed_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'review_status' => ReviewStatus::class,
            'reviewed_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AnalysisRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AnalysisRun::class, 'analysis_run_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** @return BelongsTo<AnalysisReport, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(AnalysisReport::class, 'supersedes_report_id');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }
}
