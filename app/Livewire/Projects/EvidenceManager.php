<?php

namespace App\Livewire\Projects;

use App\Jobs\ImportRepositoryJob;
use App\Models\Project;
use App\Models\Repository;
use App\Services\GitHub\GitHubUrl;
use App\Services\GitHub\InvalidGitHubUrlException;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class EvidenceManager extends Component
{
    public Project $project;

    public string $url = '';

    public function import(): void
    {
        Gate::authorize('update', $this->project);

        $this->validate(['url' => ['required', 'string', 'max:500']]);

        try {
            $identity = GitHubUrl::parse($this->url);
        } catch (InvalidGitHubUrlException $e) {
            $this->addError('url', $e->getMessage());

            return;
        }

        $repository = Repository::firstOrCreate(
            [
                'project_id' => $this->project->id,
                'canonical_url' => GitHubUrl::canonical($identity['owner'], $identity['name']),
            ],
            [
                'provider' => 'github',
                'owner' => $identity['owner'],
                'name' => $identity['name'],
            ]
        );

        $repository->forceFill(['import_status' => 'importing', 'import_error' => null])->save();

        ImportRepositoryJob::dispatch($repository->id);

        $this->url = '';
    }

    public function render()
    {
        $repository = $this->project->repositories()->latest()->first();

        return view('livewire.projects.evidence-manager', [
            'repository' => $repository,
            'evidence' => $repository?->currentEvidence()->get() ?? collect(),
        ]);
    }
}
