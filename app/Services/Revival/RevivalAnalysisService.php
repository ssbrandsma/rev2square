<?php

namespace App\Services\Revival;

use App\Enums\AnalysisStatus;
use App\Models\AnalysisRun;
use App\Services\AI\AIProviderException;
use App\Services\AI\AIProviderInterface;
use App\Services\AI\AIRateLimitedException;
use App\Services\AI\AIRequest;
use App\Services\AI\AITimeoutException;

class RevivalAnalysisService
{
    public function __construct(
        private readonly AIProviderInterface $provider,
        private readonly AnalysisRunRepository $runs,
    ) {}

    /**
     * Run one bounded structured analysis for the given run.
     *
     * Rate limits and timeouts bubble up for job-level retry; all other
     * failures are recorded on the run.
     *
     * @throws AIRateLimitedException
     * @throws AITimeoutException
     */
    public function analyze(AnalysisRun $run): void
    {
        $run->loadMissing(['project.devices', 'project', 'repository']);
        $project = $run->project;

        $this->runs->markAnalyzing($run);

        $repository = $run->repository
            ?? $project->repositories()->latest()->first();

        if ($repository === null || ! $repository->isReady()) {
            $this->runs->markFailed($run, AnalysisStatus::FailedIngest, 'NO_EVIDENCE', 'No ready evidence import for this project. Import a repository first.');

            return;
        }

        $sources = $repository->currentEvidence()->get();

        if ($sources->isEmpty()) {
            $this->runs->markFailed($run, AnalysisStatus::FailedIngest, 'EMPTY_EVIDENCE', 'The evidence manifest is empty.');

            return;
        }

        $selection = EvidenceSelector::select($sources, (int) config('ai.analysis_token_budget'));
        $this->runs->recordInputManifest($run, $selection['included'], $selection['excluded'], $repository->last_commit_sha);

        $deviceLine = $project->devices->map->displayName()->implode(', ');
        $goal = $project->pitch ?: 'Assess revival feasibility.';
        $context = EvidenceContextBuilder::forRepository($repository, $selection['included'], $selection['excluded']);

        $request = new AIRequest(
            system: AnalysisPrompt::system(),
            user: AnalysisPrompt::user($project, $deviceLine, $goal, $selection['included'], $context),
            jsonSchema: ReportSchema::jsonSchema(),
            correlationId: 'analysis-run-'.$run->id,
            usage: [
                'user_id' => $run->user_id,
                'project_id' => $run->project_id,
                'feature' => 'revival_investigator',
                'operation' => 'analysis-run-'.$run->id,
            ],
        );

        try {
            $response = $this->provider->complete($request);
        } catch (AIRateLimitedException|AITimeoutException $e) {
            throw $e;
        } catch (AIProviderException $e) {
            $this->runs->markFailed($run, AnalysisStatus::FailedModel, 'PROVIDER_ERROR', $this->safeMessage($e->getMessage()));

            return;
        }

        $payload = $this->decodeJson($response->content);

        if (! is_array($payload)) {
            $this->runs->markFailed(
                $run,
                AnalysisStatus::FailedValidation,
                'INVALID_JSON',
                sprintf(
                    'The model did not return valid JSON (%d bytes, finish_reason=%s).',
                    strlen($response->content),
                    $response->finishReason ?? 'unknown'
                )
            );
            $this->runs->recordTelemetry($run, $response);

            return;
        }

        $sourcePaths = $sources->mapWithKeys(fn ($row) => [$row->id => $row->path])->all();
        $errors = ReportSchema::validate($payload, $sourcePaths);

        if ($errors !== []) {
            $this->runs->markFailed(
                $run,
                AnalysisStatus::FailedValidation,
                'SCHEMA_VIOLATION',
                'Report failed validation: '.implode(' ', array_slice($errors, 0, 3))
            );

            return;
        }

        $report = $this->runs->createReport($run, $payload);

        $this->runs->markReady($run, $report, [
            'model' => $response->model,
            'token_usage' => [
                'input' => $response->inputTokens,
                'output' => $response->outputTokens,
                'total' => $response->totalTokens(),
            ],
            'duration_ms' => $response->durationMs,
        ]);
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

    private function safeMessage(string $message): string
    {
        // Provider messages are safe (no prompts/completions logged), cap length anyway.
        return substr($message, 0, 500);
    }
}
