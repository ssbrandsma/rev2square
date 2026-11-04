<?php

namespace App\Services\Community;

use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Enums\ProjectActivity;
use App\Models\ContributionOffer;
use App\Models\ContributorTask;
use App\Models\Project;
use App\Models\User;

/**
 * Claim and maintainer-application eligibility for new offers. Returns
 * the first rejection as a form field plus message, or null when the
 * request may proceed.
 */
class OfferEligibility
{
    /**
     * @param  array{type: string, contributor_task_id?: ?int}  $data
     * @return array{field: string, message: string}|null
     */
    public static function rejection(Project $project, User $user, array $data): ?array
    {
        // A claim needs an eligible task: open, unassigned, and current.
        // General help may reference a task for context but never claims it.
        if ($data['type'] === OfferType::ClaimTask->value) {
            $task = ContributorTask::where('project_id', $project->id)->findOrFail($data['contributor_task_id']);

            if (! $task->status->isOpenForClaim() || $task->assigned_to_user_id !== null) {
                return [
                    'field' => 'contributor_task_id',
                    'message' => 'That task is no longer available to claim ('.$task->status->label().').',
                ];
            }
        }

        // Maintainer applications are separate from task claims: no task,
        // only while the project seeks a maintainer, and never for someone
        // who already maintains it.
        if ($data['type'] === OfferType::MaintainProject->value) {
            if (($data['contributor_task_id'] ?? null) !== null) {
                return [
                    'field' => 'contributor_task_id',
                    'message' => 'A maintainer application is not attached to a task.',
                ];
            }

            if ($project->activity !== ProjectActivity::SeekingMaintainer) {
                return [
                    'field' => 'type',
                    'message' => 'This project is not looking for a maintainer right now.',
                ];
            }

            if ($project->managedBy($user)) {
                return [
                    'field' => 'type',
                    'message' => 'You already maintain this project.',
                ];
            }
        }

        // One pending request per user/project/type/task: accidental double
        // submits and refreshes land here instead of creating duplicates.
        $duplicate = ContributionOffer::where([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'type' => $data['type'],
            'status' => OfferStatus::Pending,
        ])
            ->when(
                ($data['contributor_task_id'] ?? null) === null,
                fn ($query) => $query->whereNull('contributor_task_id'),
                fn ($query) => $query->where('contributor_task_id', $data['contributor_task_id'])
            )
            ->exists();

        if ($duplicate) {
            return [
                'field' => 'type',
                'message' => 'You already have a pending request of this kind. See My applications on your dashboard.',
            ];
        }

        return null;
    }
}
