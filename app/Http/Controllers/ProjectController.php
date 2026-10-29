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
use App\Models\ProjectMedia;
use App\Services\Community\ProjectActionPolicy;
use App\Services\Media\StoryDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $project->load(['devices', 'repositories', 'cover', 'gallery']);

        $showRetired = request()->boolean('show_retired');

        $milestones = $project->milestones()->orderBy('sort_order')
            ->when(! $showRetired, fn ($query) => $query->active())
            ->get();
        $tasks = $project->tasks()->with('assignee')->orderBy('id')
            ->when(! $showRetired, fn ($query) => $query->active())
            ->get();

        $historyEvents = AuditEvent::where('project_id', $project->id)
            ->whereIn('event', ExportController::PUBLIC_HISTORY)
            ->with('user')
            ->latest()
            ->take(20)
            ->get();

        return view('projects.show', [
            'project' => $project,
            'milestones' => $milestones,
            'tasks' => $tasks,
            'observations' => $project->observations()->with(['author', 'task', 'variant.device', 'verifiedBy', 'corrections.author', 'attachments.uploader', 'attachments.reviewedBy'])->latest()->get(),
            'testReports' => $project->testReports()->with(['author', 'task', 'variant.device', 'confirmedBy', 'attachments'])->latest()->get(),
            'historyEvents' => $historyEvents,
            'assignees' => AuditEvent::assigneeMap($historyEvents),
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

        $project->load(['cover', 'media']);

        $storyIds = $project->hasRichStory()
            ? StoryDocument::mediaIds($project->story_document)
            : [];

        return view('projects.edit', [
            'project' => $project,
            'images' => $project->media->where('kind', ProjectMedia::KIND_IMAGE)->values(),
            'files' => $project->media->where('kind', ProjectMedia::KIND_FILE)->values(),
            'storyMediaIds' => $storyIds,
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $wasOpen = $project->discussion_open;
        $validated = $request->validated();

        // The rich document is authoritative once set: the legacy body
        // field can never overwrite it through the basics form.
        if ($project->hasRichStory()) {
            unset($validated['body']);
        }

        $project->fill($validated);

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
     * Save the rich story document. Optimistic concurrency on
     * presentation_version prevents silent lost updates; the legacy
     * body is refreshed as a derived plain-text fallback so search
     * and compatibility readers never see stale content.
     */
    public function updateStory(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validate([
            'story_document' => ['required', 'string', 'max:200000'],
            'presentation_version' => ['required', 'integer'],
        ]);

        if ((int) $data['presentation_version'] !== $project->presentation_version) {
            return back()->withInput()->with('error', 'Someone else changed the presentation while you were editing. Your draft was kept — review the current version, then save again.');
        }

        $decoded = json_decode($data['story_document'], true);
        if (! is_array($decoded)) {
            return back()->withInput()->with('error', 'The story document could not be read. Your draft was kept.');
        }

        $result = StoryDocument::validate($decoded, $project);
        if (! $result['ok']) {
            return back()->withInput()->with('error', $result['error']);
        }

        $project->forceFill([
            'story_document' => $result['document'],
            'story_format' => 'rich',
            'body' => Str::limit(StoryDocument::toPlainText($result['document']), 20000, ''),
            'presentation_version' => $project->presentation_version + 1,
        ])->save();

        return redirect()->route('projects.edit', $project)
            ->with('status', 'Story saved.');
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
