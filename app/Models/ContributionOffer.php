<?php

namespace App\Models;

use App\Enums\OfferStatus;
use App\Enums\OfferType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContributionOffer extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'contributor_task_id',
        'user_id',
        'type',
        'message',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'type' => OfferType::class,
            'status' => OfferStatus::class,
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ContributorTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ContributorTask::class, 'contributor_task_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
