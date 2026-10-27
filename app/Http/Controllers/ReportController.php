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
        Gate::authorize('update', $project);

        abort_unless($report->project_id === $project->id, 404);

        $report->load('run');

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

        return view('projects.reports.show', [
            'project' => $project,
            'report' => $report,
            'payload' => $payload,
            'versions' => $project->reports()->with('run')->get(),
            'evidenceById' => $evidenceById,
            'sourcesById' => $sourcesById,
        ]);
    }
}
