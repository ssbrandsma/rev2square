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
use App\Services\Revival\GitHubRepositoryImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

class EvidenceManager extends Component
{
    public Project $project;

    public string $url = '';

    public function mount(): void
    {
        $this->url = $this->project->repositories()->latest()->first()?->canonical_url ?? '';
    }

    public function import(): void
    {
        Gate::authorize('update', $this->project);

        $limits = config('revival.import_rate_limit');
        $key = 'import:'.$this->project->id.':'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, $limits['max_attempts'])) {
            $this->addError('url', 'Too many imports started recently. Try again later.');

            return;
        }

        $this->validate(['url' => ['required', 'string', 'max:500']]);

        try {
            $identity = GitHubUrl::parse($this->url);
        } catch (InvalidGitHubUrlException $e) {
            $this->addError('url', $e->getMessage());

            return;
        }

        $canonical = GitHubUrl::canonical($identity['owner'], $identity['name']);

        // One active import per project, decided inside a row lock so two
        // tabs or a double-click cannot dispatch duplicates. A repeat while
        // running is rejected with an explanation; the active job keeps its
        // state instead of being reset.
        $outcome = DB::transaction(function () use ($canonical, $identity) {
            Project::whereKey($this->project->id)->lockForUpdate()->firstOrFail();

            $active = $this->project->repositories()->where('import_status', 'importing')->first();

            if ($active !== null) {
                return ['active' => $active];
            }

            $repository = Repository::firstOrCreate(
                [
                    'project_id' => $this->project->id,
                    'canonical_url' => $canonical,
                ],
                [
                    'provider' => 'github',
                    'owner' => $identity['owner'],
                    'name' => $identity['name'],
                ]
            );

            $repository->forceFill([
                'import_status' => 'importing',
                'import_error' => null,
                'import_started_at' => now(),
                'next_retry_at' => null,
                'import_stage' => null,
            ])->save();

            return ['repository' => $repository];
        });

        if (isset($outcome['active'])) {
            $this->addError('url', 'An import for '.$outcome['active']->canonical_url.' is already running.');

            return;
        }

        RateLimiter::hit($key, $limits['per_minutes'] * 60);

        ImportRepositoryJob::dispatch($outcome['repository']->id);

        $this->url = $outcome['repository']->canonical_url;
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

    public function fetchMissing(string $path): void
    {
        Gate::authorize('update', $this->project);

        $limits = config('revival.import_rate_limit');
        $key = 'fetchmore:'.$this->project->id.':'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, $limits['max_attempts'])) {
            $this->addError('fetchMissing', 'Too many evidence fetches started recently. Try again later.');

            return;
        }

        $repository = $this->project->repositories()->latest()->first();

        if ($repository === null || ! $repository->isReady()) {
            $this->addError('fetchMissing', 'Import repository evidence before fetching more files.');

            return;
        }

        RateLimiter::hit($key, $limits['per_minutes'] * 60);

        app(GitHubRepositoryImporter::class)->fetchAdditionalPaths($repository, [$path]);
    }

    public function render()
    {
        $repository = $this->project->repositories()->latest()->first();
        $runs = $this->project->analysisRuns()->with(['report', 'repository'])->latest()->take(5)->get();

        return view('livewire.projects.evidence-manager', [
            'repository' => $repository,
            'evidence' => $repository?->currentEvidence()->get() ?? collect(),
            'runs' => $runs,
            'runsActive' => $runs->contains(fn ($run) => $run->status->isActive()),
        ]);
    }
}
