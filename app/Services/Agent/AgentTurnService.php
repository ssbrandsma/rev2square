<?php

namespace App\Services\Agent;

use App\Enums\AnalysisStatus;
use App\Models\AgentConversation;
use App\Models\ArchitectureSnapshot;
use App\Models\Project;
use App\Models\ProjectArtifact;
use App\Models\User;
use App\Services\AI\AIProviderInterface;
use App\Services\Artifacts\ArtifactEvidence;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * One Revival Agent turn: assemble the tool context and definitions,
 * run the provider/tool loop, and return the answered result.
 */
final class AgentTurnService
{
    /** @param list<array{role: string, content: string}> $history */
    public static function runTurn(User $user, Project $project, AgentConversation $conversation, string $question, array $history): AgentTurnResult
    {
        $repository = $project->repository;
        $snapshot = $repository !== null
            ? ArchitectureSnapshot::where('project_id', $project->id)
                ->where('repository_id', $repository->id)
                ->where('status', AnalysisStatus::Ready)
                ->latest('id')
                ->first()
            : null;

        // Server-side scope: only usable project artifacts already stored
        // on the conversation, and only with its explicit consent.
        $artifactIds = [];
        $artifacts = collect();
        if ($conversation->artifact_consent_at !== null) {
            $artifacts = self::usableArtifacts($project, (array) ($conversation->artifact_ids ?? []));
            $artifactIds = $artifacts->pluck('id')->all();
        }

        $context = new ToolContext(
            $user, $project, $repository, $snapshot,
            $artifactIds, $conversation->artifact_consent_at !== null && $artifactIds !== []
        );

        // Docs tools ride every turn; execution re-checks project
        // view access and draft visibility, so readers never see
        // drafts through the agent.
        $definitions = array_merge(EvidenceTools::definitions(), DocsTools::definitions());
        $names = array_merge(EvidenceTools::names(), DocsTools::names());
        if ($context->allowsArtifacts()) {
            $definitions = array_merge($definitions, ArtifactTools::definitions(), BinaryTools::definitions());
            $names = array_merge($names, ArtifactTools::names(), BinaryTools::names());
        }

        $runner = new AgentTurnRunner(
            app(AIProviderInterface::class),
            function (string $name, ?array $args) use ($context): ToolResult {
                if (in_array($name, ArtifactTools::names(), true)) {
                    return ArtifactTools::execute($name, $args, $context);
                }

                if (in_array($name, BinaryTools::names(), true)) {
                    return BinaryTools::execute($name, $args, $context);
                }

                return in_array($name, DocsTools::names(), true)
                    ? DocsTools::execute($name, $args, $context)
                    : EvidenceTools::execute($name, $args, $context);
            },
            $definitions,
            $names,
            (int) config('ai.agent_max_tool_calls'),
            (int) config('ai.agent_max_identical_calls'),
            (int) config('ai.agent_turn_seconds'),
            (int) config('ai.agent_token_budget'),
            8,
            2,
            [
                'user_id' => $user->id,
                'project_id' => $project->id,
                'feature' => $context->allowsArtifacts() ? 'upload_agent' : 'repo_agent',
                'operation' => 'conversation-'.$conversation->id,
            ],
        );

        $system = AgentPrompt::system();
        if ($context->allowsArtifacts()) {
            $blocks = $artifacts->map(fn (ProjectArtifact $a) => ArtifactEvidence::contextBlock($a))->all();
            $system .= "\n\n".AgentPrompt::artifactInvestigation((string) ($conversation->device_context ?? ''), implode("\n\n", $blocks));
        }

        return $runner->run(
            $system,
            $question,
            $history,
            'agent-'.$conversation->id.'-'.Str::random(8)
        );
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, ProjectArtifact>
     */
    public static function usableArtifacts(Project $project, array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));

        if ($ids === []) {
            return collect();
        }

        return ProjectArtifact::where('project_id', $project->id)
            ->whereIn('id', $ids)
            ->whereIn('state', [ProjectArtifact::STATE_READY, ProjectArtifact::STATE_PARTIAL])
            ->orderBy('id')
            ->get();
    }
}
