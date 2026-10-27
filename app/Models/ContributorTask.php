<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContributorTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'milestone_id',
        'title',
        'description',
        'skills',
        'status',
        'definition_of_done',
        'requires_hardware',
        'import_key',
        'origin_report_id',
        'last_seen_report_id',
        'manually_edited',
    ];

    protected function casts(): array
    {
        return [
            'skills' => 'array',
            'status' => TaskStatus::class,
            'requires_hardware' => 'boolean',
            'manually_edited' => 'boolean',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Milestone, $this> */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    /** @return list<string> */
    public function skillTags(): array
    {
        return array_values(array_filter((array) ($this->skills ?? [])));
    }
}
