<?php

namespace App\Services\Admin;

use App\Enums\ProjectRole;
use App\Models\AdminAuditEvent;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Notifications\ProjectOwnershipReceivedNotification;
use App\Notifications\ProjectOwnershipTransferredNotification;
use Illuminate\Support\Facades\DB;

class ProjectTransferException extends \RuntimeException {}

class StaleProjectTransferException extends ProjectTransferException {}

/**
 * Single-project ownership transfer. Runs inside a transaction that
 * locks the project plus the recipient row, so a concurrent transfer
 * or a recipient suspension between preview and confirmation is
 * rejected instead of applied half-way. Every transfer writes an
 * admin audit record plus a project-history event, and notifies both
 * affected owners through the existing in-app channel.
 *
 * Transfers ownership only: creator attribution, content, evidence,
 * tasks, contributions, analysis history, and audit history are
 * untouched. Personal inbox messages, personal Agent conversations,
 * and AI consent are never transferred.
 */
final class AdminProjectService
{
    /**
     * @throws ProjectTransferException
     */
    public static function transferOwnership(
        User $actor,
        int $projectId,
        int $recipientId,
        bool $keepPreviousOwner,
        string $reason,
        int $expectedOwnerId,
    ): Project {
        return DB::transaction(function () use ($actor, $projectId, $recipientId, $keepPreviousOwner, $reason, $expectedOwnerId): Project {
            $project = Project::whereKey($projectId)->lockForUpdate()->firstOrFail();

            if ($project->owner_id !== $expectedOwnerId) {
                throw new StaleProjectTransferException(
                    'Ownership changed since the preview was generated. Review the current owner and start a new transfer.'
                );
            }

            $recipient = User::whereKey($recipientId)->lockForUpdate()->first();

            if ($recipient === null) {
                throw new ProjectTransferException('The recipient account no longer exists.');
            }
            if ($recipient->isSuspended()) {
                throw new ProjectTransferException('The recipient account is suspended and cannot receive ownership.');
            }
            if ($recipient->id === $project->owner_id) {
                throw new ProjectTransferException('That account already owns this project — nothing to transfer.');
            }

            $previousOwner = $project->owner;
            $before = self::snapshot($project);

            // The new owner manages through owner_id; retire any
            // maintainer row they held so access has one source.
            $retiredMembership = ProjectMember::where('project_id', $project->id)
                ->where('user_id', $recipient->id)
                ->where('status', 'active')
                ->first();
            if ($retiredMembership !== null) {
                $retiredMembership->update(['status' => 'revoked']);
            }

            if ($keepPreviousOwner) {
                $membership = ProjectMember::firstOrNew([
                    'project_id' => $project->id,
                    'user_id' => $previousOwner->id,
                ]);
                $membership->role = ProjectRole::Maintainer;
                $membership->status = 'active';
                $membership->save();
                $previousOwnerResult = 'maintainer';
            } else {
                ProjectMember::where('project_id', $project->id)
                    ->where('user_id', $previousOwner->id)
                    ->where('status', 'active')
                    ->update(['status' => 'revoked']);
                $previousOwnerResult = 'removed';
            }

            $project->forceFill(['owner_id' => $recipient->id])->save();

            $after = self::snapshot($project->fresh());
            $after['previous_owner_result'] = $previousOwnerResult;
            $after['recipient_membership_revoked'] = $retiredMembership !== null;

            AdminAuditEvent::record('project.transferred', $actor, $project, $reason, $before, $after);

            AuditEvent::record('team.ownership_transferred', $actor, $project, null, [
                'from_user_id' => $previousOwner->id,
                'from_name' => $previousOwner->name,
                'to_user_id' => $recipient->id,
                'to_name' => $recipient->name,
                'previous_owner_result' => $previousOwnerResult,
            ]);

            // Database-channel notifications ride the same transaction:
            // they roll back with a failed transfer and become visible
            // exactly when the transfer commits. No emails are sent.
            $previousOwner->notify(new ProjectOwnershipTransferredNotification($project->fresh(), $recipient, $previousOwnerResult, $actor));
            $recipient->notify(new ProjectOwnershipReceivedNotification($project->fresh(), $previousOwner, $actor));

            return $project->fresh();
        });
    }

    /** @return array<string, mixed> */
    private static function snapshot(Project $project): array
    {
        return [
            'owner_id' => $project->owner_id,
            'owner_email' => $project->owner?->email,
        ];
    }
}
