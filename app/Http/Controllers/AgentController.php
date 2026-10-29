<?php

namespace App\Http\Controllers;

use App\Enums\AnalysisStatus;
use App\Models\AgentConversation;
use App\Models\AgentMessage;
use App\Models\ArchitectureSnapshot;
use App\Models\AuditEvent;
use App\Models\EvidenceSource;
use App\Models\Project;
use App\Models\ProjectArtifact;
use App\Services\Admin\AiBudgets;
use App\Services\Admin\AppSettings;
use App\Services\Agent\AgentAnswer;
use App\Services\Agent\AgentPrompt;
use App\Services\Agent\AgentTurnResult;
use App\Services\Agent\AgentTurnRunner;
use App\Services\Agent\ArtifactTools;
use App\Services\Agent\BinaryTools;
use App\Services\Agent\EvidenceTools;
use App\Services\Agent\ToolContext;
use App\Services\Agent\ToolResult;
use App\Services\AI\AIProviderInterface;
use App\Services\Artifacts\ArtifactEvidence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AgentController extends Controller
{
    public function index(Project $project): View
    {
        Gate::authorize('view', $project);

        $conversations = AgentConversation::where('project_id', $project->id)
            ->when(! $project->managedBy(auth()->user()), fn ($query) => $query->where('user_id', auth()->id()))
            ->with('user')
            ->withCount('messages')
            ->latest('id')
            ->get();

        $artifacts = ProjectArtifact::where('project_id', $project->id)
            ->latest('id')
            ->get();

        return view('projects.agent.index', [
            'project' => $project,
            'conversations' => $conversations,
            'repository' => $project->repository,
            'artifacts' => $artifacts,
            'artifactsEnabled' => AppSettings::uploadsAllowed(),
            'maxBytes' => (int) config('artifacts.max_upload_bytes'),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);

        $data = $request->validate(['question' => 'required|string|min:2|max:2000']);
        $this->checkRateLimit($project);

        if ($denied = AiBudgets::precheck($request->user())) {
            return back()->with('error', $denied);
        }

        $repository = $project->repository;
        if ($repository === null || ! $repository->isReady()) {
            return back()->with('error', 'Import repository evidence before chatting with the Revival Agent.');
        }

        $conversation = AgentConversation::create([
            'project_id' => $project->id,
            'user_id' => $request->user()->id,
            'title' => Str::limit(trim($data['question']), 80),
        ]);

        $result = $this->runTurn($project, $conversation, $data['question'], []);
        $this->persistTurn($conversation, $data['question'], $result);

        return redirect()->route('projects.agent.show', [$project, $conversation]);
    }

    /**
     * Start an upload-evidence investigation: selected artifacts plus an
     * explicit per-conversation consent to transmit redacted evidence.
     */
    public function investigate(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);

        // The investigation form uses its own input name so a failed
        // submit never repopulates the separate repository-question field.
        $data = $request->validate([
            'investigation_question' => 'required|string|min:2|max:2000',
            'device_context' => 'nullable|string|max:2000',
            'artifact_ids' => 'required|array|min:1|max:10',
            'artifact_ids.*' => 'integer',
            'consent' => 'accepted',
        ], [
            'artifact_ids.required' => 'Select at least one ready or partial file.',
            'artifact_ids.min' => 'Select at least one ready or partial file.',
        ]);
        $this->checkRateLimit($project);

        if ($denied = AiBudgets::precheck($request->user())) {
            return back()->with('error', $denied);
        }

        if (! AppSettings::uploadsAllowed()) {
            return back()->withInput()->with('error', 'Upload investigation is disabled on this installation.');
        }

        $usable = $this->usableArtifacts($project, $data['artifact_ids']);

        if ($usable->isEmpty()) {
            return back()->withInput()->with('error', 'None of the selected files are ready yet. Wait for extraction to finish, then start again — your question was kept.');
        }

        $conversation = AgentConversation::create([
            'project_id' => $project->id,
            'user_id' => $request->user()->id,
            'title' => Str::limit(trim($data['investigation_question']), 80),
            'artifact_ids' => $usable->pluck('id')->all(),
            'device_context' => $data['device_context'] ?? null,
            'artifact_consent_at' => now(),
        ]);

        $result = $this->runTurn($project, $conversation, $data['investigation_question'], []);
        $this->persistTurn($conversation, $data['investigation_question'], $result);

        if ($usable->count() < count(array_unique($data['artifact_ids']))) {
            session()->flash('status', 'Some selected files were not ready and were excluded from this investigation.');
        }

        return redirect()->route('projects.agent.show', [$project, $conversation]);
    }

    public function show(Project $project, AgentConversation $conversation): View
    {
        Gate::authorize('view', $project);
        $this->authorizeConversation($project, $conversation);

        $conversation->loadMissing('messages');
        $repository = $project->repository;
        $paths = $this->citationPaths($conversation, $repository?->id);
        $artifactNames = $this->citationArtifactNames($conversation, $project->id);

        $rendered = [];
        foreach ($conversation->messages as $message) {
            $rendered[$message->id] = $message->isUser()
                ? e($message->content)
                : $this->renderAnswer($project, $conversation, $message->content, $paths, $artifactNames);
        }

        $selectedArtifacts = $conversation->artifact_ids !== null
            ? ProjectArtifact::where('project_id', $project->id)
                ->whereIn('id', (array) $conversation->artifact_ids)
                ->orderBy('id')
                ->get(['id', 'original_name', 'format', 'state'])
            : collect();

        return view('projects.agent.show', [
            'project' => $project,
            'conversation' => $conversation,
            'repository' => $repository,
            'rendered' => $rendered,
            'paths' => $paths,
            'artifactNames' => $artifactNames,
            'selectedArtifacts' => $selectedArtifacts,
            'stopLabels' => self::stopLabels(),
        ]);
    }

    public function message(Request $request, Project $project, AgentConversation $conversation): RedirectResponse
    {
        Gate::authorize('view', $project);
        $this->authorizeConversation($project, $conversation);

        $data = $request->validate(['question' => 'required|string|min:2|max:2000']);
        $this->checkRateLimit($project);

        if ($denied = AiBudgets::precheck($request->user())) {
            return back()->with('error', $denied);
        }

        $repository = $project->repository;
        $hasRepo = $repository !== null && $repository->isReady();
        $hasArtifacts = $conversation->artifact_consent_at !== null
            && $this->usableArtifacts($project, (array) ($conversation->artifact_ids ?? []))->isNotEmpty();

        if (! $hasRepo && ! $hasArtifacts) {
            return back()->with('error', 'Import repository evidence or investigate uploaded files before chatting with the Revival Agent.');
        }

        $history = $conversation->messages()
            ->orderBy('id')
            ->get(['role', 'content'])
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        $result = $this->runTurn($project, $conversation, $data['question'], $history);
        $this->persistTurn($conversation, $data['question'], $result);

        return redirect()->route('projects.agent.show', [$project, $conversation]);
    }

    /**
     * Withdraw artifact-evidence consent for one conversation. Future
     * turns lose artifact tools immediately (consent is re-checked on
     * every tool call); already-sent evidence cannot be recalled.
     */
    public function withdrawConsent(Project $project, AgentConversation $conversation): RedirectResponse
    {
        Gate::authorize('view', $project);
        $this->authorizeConversation($project, $conversation);

        if ($conversation->artifact_consent_at === null) {
            return back()->with('status', 'This conversation has no evidence consent to withdraw.');
        }

        $conversation->forceFill(['artifact_consent_at' => null])->save();

        return back()->with('status', 'Evidence consent withdrawn. Future questions in this conversation will no longer send file evidence to the AI provider.');
    }

    public function save(Request $request, Project $project, AgentMessage $message): RedirectResponse
    {
        Gate::authorize('view', $project);

        $message->loadMissing('conversation');
        $conversation = $message->conversation;

        if ($conversation === null || $conversation->project_id !== $project->id || $message->role !== AgentMessage::ROLE_ASSISTANT) {
            abort(404);
        }
        $this->authorizeConversation($project, $conversation);

        $data = $request->validate(['title' => 'required|string|max:255']);

        $question = $conversation->messages()
            ->where('id', '<', $message->id)
            ->where('role', AgentMessage::ROLE_USER)
            ->latest('id')
            ->first();

        $body = 'Saved from a Revival Agent answer on '.now()->toDateString()
            .' (unverified AI output — verify before acting).'
            .($question !== null ? "\n\nQuestion: ".$question->content : '')
            ."\n\nAnswer excerpt:\n".Str::limit($message->content, 2000);

        $observation = $project->observations()->create([
            'user_id' => $request->user()->id,
            'title' => $data['title'],
            'body' => $body,
        ]);

        AuditEvent::record('observation.created', $request->user(), $project, $observation, [
            'origin' => 'revival_agent',
            'agent_message_id' => $message->id,
        ]);

        return redirect(route('projects.show', $project).'#observations');
    }

    private function authorizeConversation(Project $project, AgentConversation $conversation): void
    {
        if ($conversation->project_id !== $project->id) {
            abort(404);
        }

        if (! $conversation->isAccessibleBy(auth()->user())) {
            abort(403);
        }
    }

    private function checkRateLimit(Project $project): void
    {
        $limits = config('ai.rate_limit');
        $key = 'agent:'.$project->id.':'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, $limits['max_attempts'])) {
            abort(429, 'Too many agent requests. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes.');
        }

        RateLimiter::hit($key, $limits['per_minutes'] * 60);
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    private function runTurn(Project $project, AgentConversation $conversation, string $question, array $history): AgentTurnResult
    {
        $user = auth()->user();
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
            $artifacts = $this->usableArtifacts($project, (array) ($conversation->artifact_ids ?? []));
            $artifactIds = $artifacts->pluck('id')->all();
        }

        $context = new ToolContext(
            $user, $project, $repository, $snapshot,
            $artifactIds, $conversation->artifact_consent_at !== null && $artifactIds !== []
        );

        $definitions = EvidenceTools::definitions();
        $names = EvidenceTools::names();
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

                return in_array($name, BinaryTools::names(), true)
                    ? BinaryTools::execute($name, $args, $context)
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
    private function usableArtifacts(Project $project, array $ids): Collection
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

    private function persistTurn(AgentConversation $conversation, string $question, AgentTurnResult $result): void
    {
        $conversation->messages()->create([
            'role' => AgentMessage::ROLE_USER,
            'content' => $question,
        ]);

        $conversation->messages()->create([
            'role' => AgentMessage::ROLE_ASSISTANT,
            'content' => $result->answer,
            'citations' => $result->citations === [] ? null : $result->citations,
            'tool_trace' => $result->toolTrace === [] ? null : $result->toolTrace,
            'stopped_reason' => $result->stoppedReason,
            'token_usage' => [
                'input' => $result->inputTokens,
                'output' => $result->outputTokens,
                'estimated' => $result->tokenEstimate,
            ],
            'duration_ms' => $result->durationMs,
        ]);
    }

    /**
     * @return array<int, string> source_id => path, scoped to this repository.
     */
    private function citationPaths(AgentConversation $conversation, ?int $repositoryId): array
    {
        if ($repositoryId === null) {
            return [];
        }

        $ids = [];
        foreach ($conversation->messages as $message) {
            foreach ($message->citations ?? [] as $citation) {
                $ids[] = (int) ($citation['source_id'] ?? 0);
            }
            preg_match_all('/\[S(\d+)\]/', (string) $message->content, $m);
            foreach ($m[1] as $raw) {
                $ids[] = (int) $raw;
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }

        return EvidenceSource::where('repository_id', $repositoryId)
            ->whereIn('id', $ids)
            ->pluck('path', 'id')
            ->map(fn ($path) => (string) $path)
            ->all();
    }

    /**
     * @return array<int, string> artifact_id => original filename, scoped to this project.
     */
    private function citationArtifactNames(AgentConversation $conversation, int $projectId): array
    {
        $ids = [];
        foreach ($conversation->messages as $message) {
            foreach ($message->citations ?? [] as $citation) {
                if (isset($citation['artifact_id'])) {
                    $ids[] = (int) $citation['artifact_id'];
                }
            }
            preg_match_all('/\[A(\d+)\]/', (string) $message->content, $m);
            foreach ($m[1] as $raw) {
                $ids[] = (int) $raw;
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }

        return ProjectArtifact::where('project_id', $projectId)
            ->whereIn('id', $ids)
            ->pluck('original_name', 'id')
            ->map(fn ($name) => (string) $name)
            ->all();
    }

    /**
     * @param  array<int, string>  $paths
     * @param  array<int, string>  $artifactNames
     */
    private function renderAnswer(Project $project, AgentConversation $conversation, string $text, array $paths, array $artifactNames = []): string
    {
        $html = AgentAnswer::render($text);
        $canInspect = auth()->check() && auth()->user()->can('update', $project);
        $canView = auth()->check() && auth()->user()->can('view', $project);

        $html = (string) preg_replace_callback('/\[S(\d+)\]/', function (array $m) use ($project, $conversation, $paths, $canInspect): string {
            $id = (int) $m[1];
            $path = $paths[$id] ?? null;

            if ($path === null) {
                return '<span class="rounded bg-red-100 px-1 font-mono text-xs font-semibold text-red-800" title="This source was not verified this turn">[S'.$id.' — unverified]</span>';
            }

            $chip = '<span class="rounded bg-emerald-100 px-1 font-mono text-xs font-semibold text-emerald-900" title="'.e($path).'">[S'.$id.']</span>';

            if (! $canInspect) {
                return $chip;
            }

            $url = route('projects.evidence.show', [$project, $id, 'from' => 'agent:'.$conversation->id]);

            return '<a href="'.e($url).'" class="hover:underline" title="'.e($path).'">'.$chip.'</a>';
        }, $html);

        return (string) preg_replace_callback('/\[A(\d+)\]/', function (array $m) use ($project, $conversation, $artifactNames, $canView): string {
            $id = (int) $m[1];
            $name = $artifactNames[$id] ?? null;

            if ($name === null) {
                return '<span class="rounded bg-red-100 px-1 font-mono text-xs font-semibold text-red-800" title="This artifact was not verified this turn">[A'.$id.' — unverified]</span>';
            }

            $chip = '<span class="rounded bg-sky-100 px-1 font-mono text-xs font-semibold text-sky-900" title="'.e($name).'">[A'.$id.']</span>';

            if (! $canView) {
                return $chip;
            }

            $url = route('projects.artifacts.show', [$project, $id, 'from' => 'agent:'.$conversation->id]);

            return '<a href="'.e($url).'" class="hover:underline" title="'.e($name).'">'.$chip.'</a>';
        }, $html);
    }

    /** @return array<string, string> */
    public static function stopLabels(): array
    {
        return [
            'tool_budget' => 'Tool budget spent — the answer covers the evidence gathered so far.',
            'token_budget' => 'Turn grew too large — ask a narrower follow-up to continue.',
            'time_budget' => 'Time budget spent — ask the agent to continue.',
            'provider_error' => 'Model request failed — please try again.',
            'empty_response' => 'The model returned an empty answer — please rephrase.',
        ];
    }
}
