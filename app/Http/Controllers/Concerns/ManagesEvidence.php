<?php

namespace App\Http\Controllers\Concerns;

use App\Models\DeviceVariant;
use App\Models\Observation;
use App\Models\Project;
use App\Models\TestReport;
use Illuminate\Support\Collection;

trait ManagesEvidence
{
    /** @return Collection<int, DeviceVariant> */
    protected function variants(Project $project): Collection
    {
        return DeviceVariant::with('device')
            ->whereHas('device.projects', fn ($query) => $query->whereKey($project->id))
            ->get();
    }

    protected function variantBelongsToProject(Project $project, int $variantId): bool
    {
        return DeviceVariant::whereKey($variantId)
            ->whereHas('device.projects', fn ($query) => $query->whereKey($project->id))
            ->exists();
    }

    protected function assertTaskBelongsToProject(Project $project, ?int $taskId): void
    {
        if ($taskId === null) {
            return;
        }

        abort_unless(
            $project->tasks()->whereKey($taskId)->exists(),
            422,
            'Task does not belong to this project.'
        );
    }

    protected function canEdit(Project $project, Observation|TestReport $evidence, mixed $user): bool
    {
        return $evidence->user_id === $user?->id || $project->managedBy($user);
    }
}
