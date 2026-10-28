<?php

namespace App\Http\Controllers;

use App\Enums\ProjectActivity;
use App\Enums\TaskStatus;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\DeviceVariant;
use App\Models\Project;
use App\Services\Community\ProjectActionPolicy;
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

        $project->load(['devices', 'repositories']);

        $showRetired = request()->boolean('show_retired');

        $milestones = $project->milestones()->orderBy('sort_order')
            ->when(! $showRetired, fn ($query) => $query->active())
            ->get();
        $tasks = $project->tasks()->with('assignee')->orderBy('id')
            ->when(! $showRetired, fn ($query) => $query->active())
            ->get();

        return view('projects.show', [
            'project' => $project,
            'milestones' => $milestones,
            'tasks' => $tasks,
            'observations' => $project->observations()->with(['author', 'task', 'variant.device', 'verifiedBy', 'corrections.author', 'attachments.uploader', 'attachments.reviewedBy'])->latest()->get(),
            'testReports' => $project->testReports()->with(['author', 'task', 'variant.device', 'confirmedBy', 'attachments'])->latest()->get(),
            'historyEvents' => AuditEvent::where('project_id', $project->id)
                ->whereIn('event', ExportController::PUBLIC_HISTORY)
                ->with('user')
                ->latest()
                ->take(20)
                ->get(),
            'openTasks' => $project->tasks()->active()->orderBy('title')->get(),
            'variants' => DeviceVariant::with('device')->whereIn('device_id', $project->devices()->pluck('devices.id'))->get(),
            'retiredMilestoneCount' => $showRetired ? 0 : $project->milestones()->whereNotNull('retired_at')->count(),
            'retiredTaskCount' => $showRetired ? 0 : $project->tasks()->where('status', TaskStatus::Retired)->count(),
            'showRetired' => $showRetired,
            'contributionDenial' => ProjectActionPolicy::contributionDenial($project, auth()->user()),
        ]);
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
        $this->syncDevice($project, $request->validated());

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
        $wasOpen = $project->discussion_open;
        $project->fill($request->validated());

        // Shelving a project closes new conversation by default; posting
        // resumes only when a maintainer deliberately reopens it. Ordinary
        // edits never flip a deliberate reopen back.
        if ($project->isDirty('activity')
            && in_array($project->activity, [ProjectActivity::Archived, ProjectActivity::Paused], true)) {
            $project->discussion_open = false;
        }

        $project->save();
        $this->syncDevice($project, $request->validated());

        if ($wasOpen && ! $project->discussion_open) {
            AuditEvent::record('discussion.closed', $request->user(), $project, null, [
                'activity' => $project->activity->value,
            ]);
        }

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

    /**
     * Link the project's device from the form's device fields. Find-or-create
     * only: shared device and variant rows are never updated here, and only
     * this project's pivot rows are touched. Blank fields unlink the device.
     *
     * @param  array<string, mixed>  $validated
     */
    private function syncDevice(Project $project, array $validated): void
    {
        $model = trim((string) ($validated['device_model'] ?? ''));
        $manufacturer = trim((string) ($validated['device_manufacturer'] ?? ''));

        if ($model === '') {
            // Blank form, or manufacturer-only input that validation already
            // rejected: never persist a model-less device row.
            $project->devices()->detach();

            return;
        }

        $device = Device::firstOrCreate([
            'manufacturer' => $manufacturer === '' ? null : $manufacturer,
            'model' => $model,
        ]);

        $hardware = trim((string) ($validated['device_hardware_revision'] ?? ''));
        $firmware = trim((string) ($validated['device_firmware_version'] ?? ''));
        $variantId = null;

        if ($hardware !== '' || $firmware !== '') {
            $variantId = DeviceVariant::firstOrCreate([
                'device_id' => $device->id,
                'hardware_revision' => $hardware === '' ? null : $hardware,
                'firmware_version' => $firmware === '' ? null : $firmware,
            ])->id;
        }

        $project->devices()->sync([$device->id => ['device_variant_id' => $variantId]]);
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
