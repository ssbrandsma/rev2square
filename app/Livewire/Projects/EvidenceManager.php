<?php

namespace App\Livewire\Projects;

use App\Enums\AnalysisStatus;
use App\Jobs\AnalyzeProjectJob;
use App\Jobs\ImportRepositoryJob;
use App\Models\Project;
use App\Models\Repository;
use App\Services\GitHub\GitHubUrl;
use App\Services\GitHub\InvalidGitHubUrlException;
use App\Services\Revival\AnalysisRunRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

    public function analyze(): void
    {
        Gate::authorize('update', $this->project);

        $limits = config('ai.rate_limit');
        $key = 'analyze:'.$this->project->id.':'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, $limits['max_attempts'])) {
            $this->addError('analyze', 'Too many analyses started recently. Try again later.');

            return;
        }

        $repository = $this->project->repositories()->latest()->first();

        if ($repository === null || ! $repository->isReady()) {
            $this->addError('analyze', 'Import repository evidence before running an analysis.');

            return;
        }

        $active = $this->project->analysisRuns()
            ->whereIn('status', [AnalysisStatus::Queued->value, AnalysisStatus::Ingesting->value, AnalysisStatus::Analyzing->value])
            ->exists();

        if ($active) {
            $this->addError('analyze', 'An analysis is already running for this project.');

            return;
        }

        RateLimiter::hit($key, $limits['per_minutes'] * 60);

        $provider = config('ai.default');

        $run = app(AnalysisRunRepository::class)->createRun(
            $this->project,
            auth()->user(),
            $repository,
            $provider,
            $provider === 'meta' ? config('ai.meta.model') : 'mock',
        );

        AnalyzeProjectJob::dispatch($run->id);
    }

    public function render()
    {
        $repository = $this->project->repositories()->latest()->first();
        $runs = $this->project->analysisRuns()->with('report')->latest()->take(5)->get();

        return view('livewire.projects.evidence-manager', [
            'repository' => $repository,
            'evidence' => $repository?->currentEvidence()->get() ?? collect(),
            'runs' => $runs,
            'runsActive' => $runs->contains(fn ($run) => $run->status->isActive()),
        ]);
    }
}
