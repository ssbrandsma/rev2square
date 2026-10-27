<?php

namespace App\Http\Controllers;

use App\Models\AnalysisReport;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function show(Project $project, AnalysisReport $report): View
    {
        Gate::authorize('view', $project);
        abort_unless($report->project_id === $project->id, 404);

        $report->load('run');

        if (Gate::allows('update', $project)) {
            return view('projects.reports.show', $this->viewData($project, $report, false));
        }

        abort_unless($report->isPublished(), 404);

        return view('projects.reports.public', $this->viewData($project, $report, true));
    }

    /** @return array<string, mixed> */
    private function viewData(Project $project, AnalysisReport $report, bool $public): array
    {
        $payload = $report->payload_json;

        $evidenceById = collect($payload['evidence'] ?? [])->keyBy('id');
        $sourceIds = $evidenceById->pluck('source_id')
            ->merge(collect($payload['experiments'] ?? [])->flatMap(fn ($x) => $x['source_ids'] ?? []))
            ->merge($payload['source_manifest_ids'] ?? [])
            ->unique()->values();

        $sourcesById = $project->repositories()
            ->with(['evidenceSources' => fn ($query) => $query->whereIn('evidence_sources.id', $sourceIds)])
            ->get()
            ->flatMap->evidenceSources
            ->keyBy('id');

        $versions = $public
            ? $project->reports()->whereNotNull('published_at')->with('run')->get()
            : $project->reports()->with('run')->get();

        return [
            'project' => $project,
            'report' => $report,
            'payload' => $payload,
            'versions' => $versions,
            'evidenceById' => $evidenceById,
            'sourcesById' => $sourcesById,
        ];
    }
}
