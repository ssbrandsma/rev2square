<?php

namespace App\Http\Controllers;

use App\Enums\AnalysisStatus;
use App\Jobs\AnalyzeArchitectureJob;
use App\Models\ArchitectureSnapshot;
use App\Models\Project;
use App\Services\Revival\ArchitecturePrompt;
use App\Services\Revival\ArchitectureSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class ArchitectureController extends Controller
{
    public function show(Project $project): View
    {
        Gate::authorize('view', $project);

        $project->loadMissing('repository');
        $repository = $project->repository;

        $latest = ArchitectureSnapshot::where('project_id', $project->id)
            ->latest('id')
            ->first();

        $ready = ArchitectureSnapshot::where('project_id', $project->id)
            ->where('status', AnalysisStatus::Ready)
            ->latest('id')
            ->first();

        $staleness = $ready !== null && $repository !== null
            ? $ready->staleness($repository)
            : null;

        $cacheHit = false;
        if ($repository?->isReady() && $ready !== null && $staleness === null) {
            $cacheHit = hash_equals(
                $ready->cache_key,
                ArchitectureSnapshot::cacheKey($project->id, $repository->id, (string) $repository->last_commit_sha)
            );
        }

        return view('projects.architecture.show', [
            'project' => $project,
            'repository' => $repository,
            'latest' => $latest,
            'snapshot' => $ready,
            'staleness' => $staleness,
            'cacheHit' => $cacheHit,
        ]);
    }

    public function store(Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $project->loadMissing('repository');
        $repository = $project->repository;

        if ($repository === null || ! $repository->isReady()) {
            return back()->with('error', 'Import repository evidence before running an architecture analysis.');
        }

        $key = 'arch-analyze:'.$project->id.':'.auth()->id();
        $limits = config('ai.rate_limit');

        if (RateLimiter::tooManyAttempts($key, $limits['max_attempts'])) {
            $seconds = RateLimiter::availableIn($key);

            return back()->with('error', 'Too many analysis requests. Try again in '.ceil($seconds / 60).' minutes.');
        }

        $active = ArchitectureSnapshot::where('project_id', $project->id)
            ->whereIn('status', [AnalysisStatus::Queued, AnalysisStatus::Ingesting, AnalysisStatus::Analyzing])
            ->exists();

        if ($active) {
            return back()->with('error', 'An architecture analysis is already running for this project.');
        }

        $cacheKey = ArchitectureSnapshot::cacheKey($project->id, $repository->id, (string) $repository->last_commit_sha);

        $existing = ArchitectureSnapshot::where('project_id', $project->id)
            ->where('cache_key', $cacheKey)
            ->where('status', AnalysisStatus::Ready)
            ->latest('id')
            ->first();

        if ($existing !== null && $existing->staleness($repository) === null) {
            return redirect()
                ->route('projects.architecture.show', $project)
                ->with('status', 'An up-to-date architecture snapshot already exists.');
        }

        RateLimiter::hit($key, $limits['per_minutes'] * 60);

        $snapshot = ArchitectureSnapshot::create([
            'project_id' => $project->id,
            'repository_id' => $repository->id,
            'user_id' => auth()->id(),
            'status' => AnalysisStatus::Queued,
            'cache_key' => $cacheKey,
            'manifest_commit_sha' => $repository->last_commit_sha,
            'schema_version' => ArchitectureSchema::VERSION,
            'prompt_version' => ArchitecturePrompt::VERSION,
        ]);

        AnalyzeArchitectureJob::dispatch($snapshot->id);

        return redirect()
            ->route('projects.architecture.show', $project)
            ->with('status', 'Architecture analysis queued.');
    }
}
