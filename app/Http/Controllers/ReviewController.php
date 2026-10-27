<?php

namespace App\Http\Controllers;

use App\Enums\ReviewStatus;
use App\Models\AnalysisReport;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Services\Revival\ProjectPublisher;
use App\Services\Revival\PublisherException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReviewController extends Controller
{
    public function approve(Request $request, Project $project, AnalysisReport $report): RedirectResponse
    {
        Gate::authorize('update', $project);
        $this->ensureBelongs($project, $report);

        $data = $request->validate(['review_notes' => ['nullable', 'string', 'max:5000']]);

        if ($report->review_status !== ReviewStatus::PendingReview) {
            return back()->withErrors(['review' => 'Only a pending report can be approved.']);
        }

        $report->forceFill([
            'review_status' => ReviewStatus::Approved,
            'review_notes' => $data['review_notes'] ?? $report->review_notes,
            'reviewer_id' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        AuditEvent::record('report.approved', $request->user(), $project, $report);

        return redirect()->route('projects.reports.show', [$project, $report])
            ->with('status', "Report v{$report->version_number} approved. Publish it to make the roadmap public.");
    }

    public function reject(Request $request, Project $project, AnalysisReport $report): RedirectResponse
    {
        Gate::authorize('update', $project);
        $this->ensureBelongs($project, $report);

        $data = $request->validate(['review_notes' => ['nullable', 'string', 'max:5000']]);

        if ($report->review_status !== ReviewStatus::PendingReview) {
            return back()->withErrors(['review' => 'Only a pending report can be rejected.']);
        }

        $report->forceFill([
            'review_status' => ReviewStatus::Rejected,
            'review_notes' => $data['review_notes'] ?? $report->review_notes,
            'reviewer_id' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        AuditEvent::record('report.rejected', $request->user(), $project, $report);

        return redirect()->route('projects.reports.show', [$project, $report])
            ->with('status', "Report v{$report->version_number} rejected.");
    }

    public function publish(Project $project, AnalysisReport $report, ProjectPublisher $publisher): RedirectResponse
    {
        Gate::authorize('update', $project);
        $this->ensureBelongs($project, $report);

        try {
            $summary = $publisher->publish($report->fresh(), request()->user());
        } catch (PublisherException $e) {
            return back()->withErrors(['review' => $e->getMessage()]);
        }

        AuditEvent::record('report.published', request()->user(), $project, $report, $summary);

        return redirect()->route('projects.show', $project)
            ->with('status', "Report v{$report->version_number} published: {$summary['milestones_created']} new milestones, {$summary['tasks_created']} new tasks.");
    }

    private function ensureBelongs(Project $project, AnalysisReport $report): void
    {
        abort_unless($report->project_id === $project->id, 404);
    }
}
