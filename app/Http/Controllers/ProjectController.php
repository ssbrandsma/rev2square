<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(): View
    {
        return view('projects.index');
    }

    public function show(Project $project): View
    {
        Gate::authorize('view', $project);

        $project->load(['devices', 'milestones', 'tasks']);

        return view('projects.show', ['project' => $project]);
    }

    public function create(): View
    {
        Gate::authorize('create', Project::class);

        return view('projects.create');
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $project = new Project($request->validated());
        $project->owner_id = $request->user()->id;
        $project->slug = $this->uniqueSlug($request->string('title')->toString());
        $project->save();

        return redirect()->route('projects.show', $project)
            ->with('status', 'Project created.');
    }

    public function edit(Project $project): View
    {
        Gate::authorize('update', $project);

        return view('projects.edit', ['project' => $project]);
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $project->fill($request->validated());
        $project->save();

        return redirect()->route('projects.show', $project)
            ->with('status', 'Project updated.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        Gate::authorize('delete', $project);

        $project->delete();

        return redirect()->route('dashboard')
            ->with('status', 'Project deleted.');
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'project';
        $slug = $base;
        $counter = 2;

        while (Project::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }
}
