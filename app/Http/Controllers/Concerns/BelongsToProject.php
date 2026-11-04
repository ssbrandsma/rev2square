<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Project;
use Illuminate\Database\Eloquent\Model;

trait BelongsToProject
{
    /**
     * Abort with 404 unless the child row belongs to the project.
     * Every nested project resource guards scope this way.
     */
    protected function assertBelongsToProject(Project $project, Model $child): void
    {
        abort_unless($child->getAttribute('project_id') === $project->id, 404);
    }
}
