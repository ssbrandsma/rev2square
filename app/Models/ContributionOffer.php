<?php

namespace App\Models;

use App\Enums\OfferStatus;
use App\Enums\OfferType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'decision_reason',
        'decided_by_user_id',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => OfferType::class,
            'status' => OfferStatus::class,
            'decided_at' => 'datetime',
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

    /** @return HasMany<OfferMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(OfferMessage::class, 'offer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function nextStep(): string
    {
        if ($this->status === OfferStatus::Accepted && $this->type === OfferType::MaintainProject) {
            return 'Accepted — check your inbox for the maintainer invitation and accept it to join the team.';
        }

        return match ($this->status) {
            OfferStatus::Pending => 'Waiting for maintainer review. You can withdraw it below.',
            OfferStatus::Accepted => $this->type === OfferType::ClaimTask && $this->task
                ? 'Accepted — you are assigned to “'.$this->task->title.'”. Coordinate with the maintainers.'
                : 'Accepted — coordinate next steps with the maintainers.',
            OfferStatus::Declined => 'Not accepted this time. You can ask for reconsideration or submit a new offer.',
            OfferStatus::Withdrawn => 'Withdrawn by you. You can reopen it or submit a new offer any time.',
        };
    }
}
