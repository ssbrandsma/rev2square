<?php

namespace App\Services\Community;

use App\Enums\ProjectActivity;
use App\Models\Project;
use App\Models\User;

/**
 * Single source of truth for lifecycle-gated contributor actions.
 * Used by offer/discussion/chat controllers and their views so read paths
 * and mutation paths enforce the same matrix.
 */
class ProjectActionPolicy
{
    /**
     * Null when the user may start a new offer/claim/discussion post,
     * otherwise a human-readable denial reason.
     */
    public static function contributionDenial(Project $project, ?User $user): ?string
    {
        if (! $project->isPublished()) {
            return 'This project is not published.';
        }

        if ($project->activity === ProjectActivity::Archived) {
            return 'This project is archived and no longer accepts new contributions. Existing records stay visible.';
        }

        if ($project->activity === ProjectActivity::Paused) {
            return 'This project is paused. Contributions reopen when the maintainers reactivate it.';
        }

        return null;
    }

    /**
     * Null when the user may file a contribution offer or task claim,
     * otherwise a human-readable denial reason. Lifecycle rules come
     * first; maintainers are then excluded because they already manage
     * the project (tasks, offers, and team) directly instead of
     * applying to contribute to it.
     */
    public static function offerDenial(Project $project, ?User $user): ?string
    {
        if ($denial = self::contributionDenial($project, $user)) {
            return $denial;
        }

        if ($user !== null && $project->managedBy($user)) {
            return 'You already maintain this project. Manage its tasks, offers, and team directly instead of filing an offer.';
        }

        return null;
    }

    /**
     * Null when the user may attach evidence files on this project,
     * otherwise a human-readable reason. Unlike offers, evidence stays
     * open on drafts (managers build it there); archived and paused
     * projects accept no new uploads.
     */
    public static function evidenceDenial(Project $project, ?User $user): ?string
    {
        if ($project->activity === ProjectActivity::Archived) {
            return 'This project is archived and no longer accepts new uploads. Existing records stay visible.';
        }

        if ($project->activity === ProjectActivity::Paused) {
            return 'This project is paused. Uploads reopen when the maintainers reactivate it.';
        }

        return null;
    }

    /**
     * Null when the user may post/reply/chat on this project, otherwise a
     * human-readable reason. Draft visibility is enforced by the view gate;
     * this covers the conversation lifecycle on visible projects.
     */
    public static function discussionDenial(Project $project, ?User $user): ?string
    {
        if ($user === null) {
            return 'Sign in to join the discussion.';
        }

        if (! $project->discussion_open) {
            if ($project->activity === ProjectActivity::Archived) {
                return 'Discussion is closed because this project is archived. A maintainer can deliberately reopen it.';
            }

            if ($project->activity === ProjectActivity::Paused) {
                return 'Discussion is paused along with the project. It reopens when the maintainers reactivate it.';
            }

            return 'Discussion is closed by the maintainers.';
        }

        return null;
    }
}
