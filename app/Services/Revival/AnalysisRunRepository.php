<?php

namespace App\Services\Revival;

use App\Enums\AnalysisStatus;
use App\Enums\ReviewStatus;
use App\Models\AnalysisReport;
use App\Models\AnalysisRun;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Services\AI\AIResponse;
use Illuminate\Support\Facades\DB;

class AnalysisRunRepository
{
    public function createRun(Project $project, User $user, ?Repository $repository, string $provider, ?string $model): AnalysisRun
    {
        return AnalysisRun::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'repository_id' => $repository?->id,
            'status' => AnalysisStatus::Queued,
            'provider' => $provider,
            'model' => $model,
            'schema_version' => ReportSchema::VERSION,
        ]);
    }

    public function markAnalyzing(AnalysisRun $run): void
    {
        $run->forceFill(['status' => AnalysisStatus::Analyzing, 'started_at' => now()])->save();
    }

    public function markReady(AnalysisRun $run, AnalysisReport $report, array $telemetry): void
    {
        $run->forceFill([
            'status' => AnalysisStatus::ReadyForReview,
            'model' => $telemetry['model'] ?? $run->model,
            'token_usage' => $telemetry['token_usage'] ?? null,
            'duration_ms' => $telemetry['duration_ms'] ?? null,
            'completed_at' => now(),
        ])->save();
    }

    public function recordTelemetry(AnalysisRun $run, AIResponse $response): void
    {
        $run->forceFill([
            'model' => $response->model,
            'token_usage' => [
                'input' => $response->inputTokens,
                'output' => $response->outputTokens,
                'total' => $response->totalTokens(),
            ],
            'duration_ms' => $response->durationMs,
        ])->save();
    }

    public function markFailed(AnalysisRun $run, AnalysisStatus $status, string $code, string $message): void
    {
        $run->forceFill([
            'status' => $status,
            'error_code' => $code,
            'error_message' => $message,
            'completed_at' => now(),
        ])->save();
    }

    public function createReport(AnalysisRun $run, array $payload): AnalysisReport
    {
        return DB::transaction(function () use ($run, $payload) {
            $version = (int) (AnalysisReport::where('project_id', $run->project_id)
                ->lockForUpdate()
                ->max('version_number') ?? 0) + 1;

            $previous = AnalysisReport::where('project_id', $run->project_id)
                ->where('version_number', $version - 1)
                ->first();

            return AnalysisReport::create([
                'analysis_run_id' => $run->id,
                'project_id' => $run->project_id,
                'version_number' => $version,
                'supersedes_report_id' => $previous?->id,
                'payload_json' => $payload,
                'review_status' => ReviewStatus::PendingReview,
            ]);
        });
    }

    public function recordInputManifest(AnalysisRun $run, array $included, array $excluded): void
    {
        $manifest = [
            'included' => array_map(fn ($i) => ['source_id' => $i['source_id'], 'path' => $i['path']], $included),
            'excluded' => $excluded,
        ];

        $run->forceFill([
            'input_manifest' => $manifest,
            'input_manifest_hash' => hash('sha256', json_encode($manifest)),
        ])->save();
    }
}
