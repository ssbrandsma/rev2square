<?php

namespace App\Http\Controllers;

use App\Enums\TestOutcome;
use App\Models\AuditEvent;
use App\Models\DeviceVariant;
use App\Models\Project;
use App\Models\TestReport;
use App\Notifications\TestReportConfirmedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TestReportController extends Controller
{
    public function show(Project $project, TestReport $testReport): View
    {
        Gate::authorize('view', $project);
        abort_unless($testReport->project_id === $project->id, 404);

        $testReport->load(['author', 'task', 'variant.device', 'confirmedBy', 'attachments.uploader', 'attachments.reviewedBy']);

        return view('projects.test-reports.show', [
            'project' => $project,
            'report' => $testReport,
            'canEdit' => auth()->check() && $this->canEdit($project, $testReport, auth()->user()),
        ]);
    }

    public function create(Project $project): View
    {
        Gate::authorize('view', $project);

        return view('projects.test-reports.create', [
            'project' => $project,
            'tasks' => $project->tasks()->active()->orderBy('title')->get(),
            'variants' => $this->variants($project),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);

        $report = $project->testReports()->create([
            ...$this->validatedData($request, $project),
            'user_id' => $request->user()->id,
        ]);

        AuditEvent::record('test_report.created', $request->user(), $project, $report);

        return redirect()->route('projects.test-reports.show', [$project, $report])
            ->with('status', 'Test report filed. Another member can confirm it by reproducing your steps.');
    }

    public function edit(Project $project, TestReport $testReport): View
    {
        Gate::authorize('view', $project);
        abort_unless($testReport->project_id === $project->id, 404);
        abort_unless($this->canEdit($project, $testReport, auth()->user()), 403);

        return view('projects.test-reports.edit', [
            'project' => $project,
            'report' => $testReport,
            'tasks' => $project->tasks()->active()->orderBy('title')->get(),
            'variants' => $this->variants($project),
        ]);
    }

    public function update(Request $request, Project $project, TestReport $testReport): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($testReport->project_id === $project->id, 404);
        abort_unless($this->canEdit($project, $testReport, $request->user()), 403);

        $wasConfirmed = $testReport->isConfirmed();

        $testReport->update([
            ...$this->validatedData($request, $project),
            'confirmed_at' => null,
            'confirmed_by_user_id' => null,
        ]);

        AuditEvent::record('test_report.updated', $request->user(), $project, $testReport, $wasConfirmed
            ? ['confirmation_cleared' => true]
            : []);

        return redirect()->route('projects.test-reports.show', [$project, $testReport])->with('status', $wasConfirmed
            ? 'Test report updated. The earlier confirmation was cleared because the steps changed.'
            : 'Test report updated.');
    }

    public function confirm(Request $request, Project $project, TestReport $testReport): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($testReport->project_id === $project->id, 404);
        abort_unless($testReport->user_id !== $request->user()->id, 403, 'You cannot confirm your own report.');
        abort_unless(! $testReport->isConfirmed(), 422, 'This report is already confirmed.');

        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $testReport->update([
            'confirmed_at' => now(),
            'confirmed_by_user_id' => $request->user()->id,
        ]);

        AuditEvent::record('test_report.confirmed', $request->user(), $project, $testReport, array_filter([
            'note' => $data['note'] ?? null,
        ]));

        $testReport->author->notify(new TestReportConfirmedNotification(
            $testReport,
            $request->user()->name,
            $data['note'] ?? null,
        ));

        return redirect()->route('projects.test-reports.show', [$project, $testReport])
            ->with('status', 'Confirmation recorded. Thank you for reproducing this test.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request, Project $project): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'outcome' => ['required', 'string'],
            'body' => ['required', 'string', 'max:10000'],
            'environment' => ['nullable', 'string', 'max:2000'],
            'contributor_task_id' => ['nullable', 'integer', 'exists:contributor_tasks,id'],
            'device_variant_id' => ['nullable', 'integer', 'exists:device_variants,id'],
        ]);

        $outcome = TestOutcome::tryFrom($data['outcome']);
        abort_unless($outcome !== null, 422, 'Unknown test outcome.');

        if (($data['contributor_task_id'] ?? null) !== null) {
            abort_unless(
                $project->tasks()->whereKey($data['contributor_task_id'])->exists(),
                422,
                'Task does not belong to this project.'
            );
        }

        if (($data['device_variant_id'] ?? null) !== null) {
            abort_unless(
                DeviceVariant::whereKey((int) $data['device_variant_id'])
                    ->whereIn('device_id', $project->devices()->pluck('devices.id'))
                    ->exists(),
                422,
                'Device variant does not belong to this project.'
            );
        }

        return [
            'title' => trim($data['title']),
            'outcome' => $outcome,
            'body' => $data['body'],
            'environment' => $data['environment'] ?? null,
            'contributor_task_id' => $data['contributor_task_id'] ?? null,
            'device_variant_id' => $data['device_variant_id'] ?? null,
        ];
    }

    /** @return Collection<int, DeviceVariant> */
    private function variants(Project $project)
    {
        return DeviceVariant::with('device')
            ->whereIn('device_id', $project->devices()->pluck('devices.id'))
            ->get();
    }

    private function canEdit(Project $project, TestReport $report, mixed $user): bool
    {
        return $report->user_id === $user?->id || $project->managedBy($user);
    }
}
