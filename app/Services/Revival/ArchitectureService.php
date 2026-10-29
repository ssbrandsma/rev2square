<?php

namespace App\Services\Revival;

use App\Enums\AnalysisStatus;
use App\Models\ArchitectureSnapshot;
use App\Models\EvidenceSource;
use App\Models\Repository;
use App\Services\AI\AIProviderException;
use App\Services\AI\AIProviderInterface;
use App\Services\AI\AIRateLimitedException;
use App\Services\AI\AIRequest;
use App\Services\AI\AIResponse;
use App\Services\AI\AITimeoutException;
use Illuminate\Support\Collection;

/**
 * Bounded architecture pipeline: ready snapshot -> evidence selection ->
 * deterministic signals -> model interpretation -> validated payload.
 *
 * Rate limits and timeouts bubble up for job-level retry; every other
 * failure is recorded on the snapshot. Snapshots are immutable once
 * finished: staleness is flagged, never rewritten.
 */
class ArchitectureService
{
    public function __construct(
        private readonly AIProviderInterface $provider,
    ) {}

    /**
     * @throws AIRateLimitedException
     * @throws AITimeoutException
     */
    public function analyze(ArchitectureSnapshot $snapshot): void
    {
        $started = microtime(true);
        $snapshot->loadMissing(['project.devices', 'project', 'repository']);
        $project = $snapshot->project;
        $repository = $snapshot->repository ?? $project->repositories()->latest()->first();

        $snapshot->forceFill(['status' => AnalysisStatus::Analyzing, 'started_at' => now()])->save();

        if ($repository === null || ! $repository->isReady()) {
            $this->markFailed($snapshot, AnalysisStatus::FailedIngest, 'NO_EVIDENCE', 'No ready evidence import for this project. Import a repository first.');

            return;
        }

        // Bind the snapshot to the repository revision actually analyzed.
        $snapshot->forceFill([
            'repository_id' => $repository->id,
            'manifest_commit_sha' => $repository->last_commit_sha,
        ])->save();

        $sources = $repository->currentEvidence()->get();

        if ($sources->isEmpty()) {
            $this->markFailed($snapshot, AnalysisStatus::FailedIngest, 'EMPTY_EVIDENCE', 'The evidence manifest is empty.');

            return;
        }

        $selection = EvidenceSelector::select($sources, (int) config('ai.analysis_token_budget'));
        $this->recordInputManifest($snapshot, $selection['included'], $selection['excluded'], $repository);

        $signals = ArchitecturalSignals::fromEvidence($selection['included']);

        $deviceLine = $project->devices->map->displayName()->implode(', ');
        $goal = $project->pitch ?: 'Map the architecture of this project.';
        $context = EvidenceContextBuilder::forRepository($repository, $selection['included'], $selection['excluded']);

        $request = new AIRequest(
            system: ArchitecturePrompt::system(),
            user: AnalysisPrompt::user($project, $deviceLine, $goal, $selection['included'], $context)
                ."\n\n".ArchitecturePrompt::signalsBlock($signals),
            jsonSchema: ArchitectureSchema::jsonSchema(),
            maxCompletionTokens: (int) config('ai.architecture_token_budget'),
            correlationId: 'architecture-snapshot-'.$snapshot->id,
            usage: [
                'user_id' => $snapshot->user_id,
                'project_id' => $snapshot->project_id,
                'feature' => 'architecture_investigator',
                'operation' => 'architecture-snapshot-'.$snapshot->id,
            ],
        );

        try {
            $response = $this->provider->complete($request);
        } catch (AIRateLimitedException|AITimeoutException $e) {
            throw $e;
        } catch (AIProviderException $e) {
            $this->markFailed($snapshot, AnalysisStatus::FailedModel, 'PROVIDER_ERROR', substr($e->getMessage(), 0, 500));

            return;
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $payload = $this->decodeJson($response->content);

        if (! is_array($payload)) {
            $this->markFailed(
                $snapshot,
                AnalysisStatus::FailedValidation,
                'INVALID_JSON',
                sprintf(
                    'The model did not return valid JSON (%d bytes, finish_reason=%s).',
                    strlen($response->content),
                    $response->finishReason ?? 'unknown'
                )
            );
            $this->recordTelemetry($snapshot, $response, $durationMs);

            return;
        }

        [$errors, $normalized] = ArchitectureSchema::validate(
            $payload,
            $this->evidenceIndex($sources, $selection['included'])
        );

        if ($errors !== []) {
            $this->markFailed(
                $snapshot,
                AnalysisStatus::FailedValidation,
                'SCHEMA_VIOLATION',
                'Architecture output failed validation: '.implode(' ', array_slice($errors, 0, 3))
            );

            return;
        }

        $snapshot->forceFill([
            'status' => AnalysisStatus::Ready,
            'payload' => $normalized,
            'finished_at' => now(),
        ])->save();

        $this->recordTelemetry($snapshot, $response, $durationMs);
    }

    private function decodeJson(string $content): mixed
    {
        $text = trim($content);

        // Defensive: strip markdown code fences if the provider wraps the JSON.
        if (str_starts_with($text, '```')) {
            $text = (string) preg_replace('/^```[a-zA-Z]*\s*/', '', $text);
            $text = (string) preg_replace('/\s*```$/', '', $text);
        }

        try {
            return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Build the per-source validation index for exactly the included rows.
     *
     * @param  Collection<int, EvidenceSource>  $sources
     * @param  list<array{source_id: int}>  $included
     * @return array<int, array{path: string, content_hash: string, is_partial: bool, line_ranges: ?list<array{start: int, end: int}>, original_lines: ?int, line_count: int}>
     */
    private function evidenceIndex($sources, array $included): array
    {
        $byId = $sources->keyBy('id');
        $index = [];

        foreach ($included as $item) {
            $row = $byId->get($item['source_id']);
            if ($row === null) {
                continue;
            }
            $index[$row->id] = [
                'path' => $row->path,
                'content_hash' => (string) $row->content_hash,
                'is_partial' => (bool) $row->is_partial,
                'line_ranges' => is_array($row->line_ranges) ? array_values($row->line_ranges) : null,
                'original_lines' => $row->original_lines,
                'line_count' => max(1, substr_count((string) $row->content, "\n") + 1),
            ];
        }

        return $index;
    }

    /**
     * @param  list<array{source_id: int, path: string}>  $included
     * @param  list<array{source_id: int, path: string, reason: string}>  $excluded
     */
    private function recordInputManifest(ArchitectureSnapshot $snapshot, array $included, array $excluded, Repository $repository): void
    {
        $rows = $repository->currentEvidence()
            ->whereIn('id', array_column($included, 'source_id'))
            ->get(['id', 'content_hash'])
            ->mapWithKeys(fn ($row) => [$row->id => (string) $row->content_hash])
            ->all();

        $manifest = [
            'commit_sha' => $repository->last_commit_sha,
            'included' => array_map(fn ($i) => [
                'source_id' => $i['source_id'],
                'path' => $i['path'],
                'content_hash' => $rows[$i['source_id']] ?? null,
            ], $included),
            'excluded' => $excluded,
        ];

        $snapshot->forceFill([
            'input_manifest' => $manifest,
            'input_manifest_hash' => hash('sha256', (string) json_encode($manifest)),
            'evidence_identity' => ArchitectureSnapshot::evidenceIdentity(
                array_map(fn ($id, $hash) => ['id' => $id, 'content_hash' => $hash], array_keys($rows), $rows)
            ),
        ])->save();
    }

    private function recordTelemetry(ArchitectureSnapshot $snapshot, AIResponse $response, int $durationMs): void
    {
        $snapshot->forceFill([
            'provider' => (string) config('ai.default'),
            'model' => $response->model,
            'token_usage' => [
                'input' => $response->inputTokens,
                'output' => $response->outputTokens,
                'total' => $response->totalTokens(),
            ],
            'duration_ms' => $response->durationMs ?? $durationMs,
        ])->save();
    }

    public function markFailed(ArchitectureSnapshot $snapshot, AnalysisStatus $status, string $code, string $message): void
    {
        $snapshot->forceFill([
            'status' => $status,
            'error_code' => $code,
            'error_message' => substr($message, 0, 1900),
            'finished_at' => now(),
        ])->save();
    }
}
