<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Services\Admin\Permissions;

class ProjectPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Project $project): bool
    {
        if ($project->isPublished()) {
            return true;
        }

        return $project->managedBy($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Project $project): bool
    {
        return $project->managedBy($user);
    }

    /**
     * Review member reports in this project's queue. Project managers
     * always qualify; global moderators/admins qualify through the
     * explicit 'moderate.reports' permission only — never through a
     * blanket admin bypass. Grants nothing beyond the report queue:
     * evidence, agent conversations, artifacts, offers, settings, and
     * team management stay behind their own checks.
     */
    public function moderate(User $user, Project $project): bool
    {
        return $project->managedBy($user) || Permissions::allows($user, 'moderate.reports');
    }

    public function delete(User $user, Project $project): bool
    {
        return $project->managedBy($user);
    }
}
