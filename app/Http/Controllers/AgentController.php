<?php

namespace App\Http\Controllers;

use App\Enums\AnalysisStatus;
use App\Models\AgentConversation;
use App\Models\AgentMessage;
use App\Models\ArchitectureSnapshot;
use App\Models\AuditEvent;
use App\Models\EvidenceSource;
use App\Models\Project;
use App\Services\Agent\AgentPrompt;
use App\Services\Agent\AgentTurnResult;
use App\Services\Agent\AgentTurnRunner;
use App\Services\Agent\EvidenceTools;
use App\Services\Agent\ToolContext;
use App\Services\AI\AIProviderInterface;
use App\Services\Discussion\Markup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return view('projects.agent.index', [
            'project' => $project,
            'conversations' => $conversations,
            'repository' => $project->repository,
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);

        $data = $request->validate(['question' => 'required|string|min:2|max:2000']);
        $this->checkRateLimit($project);

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

    public function show(Project $project, AgentConversation $conversation): View
    {
        Gate::authorize('view', $project);
        $this->authorizeConversation($project, $conversation);

        $conversation->loadMissing('messages');
        $repository = $project->repository;
        $paths = $this->citationPaths($conversation, $repository?->id);

        $rendered = [];
        foreach ($conversation->messages as $message) {
            $rendered[$message->id] = $message->isUser()
                ? e($message->content)
                : $this->renderAnswer($project, $message->content, $paths);
        }

        return view('projects.agent.show', [
            'project' => $project,
            'conversation' => $conversation,
            'repository' => $repository,
            'rendered' => $rendered,
            'paths' => $paths,
            'stopLabels' => self::stopLabels(),
        ]);
    }

    public function message(Request $request, Project $project, AgentConversation $conversation): RedirectResponse
    {
        Gate::authorize('view', $project);
        $this->authorizeConversation($project, $conversation);

        $data = $request->validate(['question' => 'required|string|min:2|max:2000']);
        $this->checkRateLimit($project);

        $repository = $project->repository;
        if ($repository === null || ! $repository->isReady()) {
            return back()->with('error', 'Import repository evidence before chatting with the Revival Agent.');
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
        $snapshot = ArchitectureSnapshot::where('project_id', $project->id)
            ->where('repository_id', $repository->id)
            ->where('status', AnalysisStatus::Ready)
            ->latest('id')
            ->first();

        $context = new ToolContext($user, $project, $repository, $snapshot);
        $runner = new AgentTurnRunner(
            app(AIProviderInterface::class),
            fn (string $name, ?array $args) => EvidenceTools::execute($name, $args, $context),
            EvidenceTools::definitions(),
            EvidenceTools::names(),
            (int) config('ai.agent_max_tool_calls'),
            (int) config('ai.agent_max_identical_calls'),
            (int) config('ai.agent_turn_seconds'),
            (int) config('ai.agent_token_budget'),
        );

        return $runner->run(
            AgentPrompt::system(),
            $question,
            $history,
            'agent-'.$conversation->id.'-'.Str::random(8)
        );
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

    /** @param  array<int, string>  $paths */
    private function renderAnswer(Project $project, string $text, array $paths): string
    {
        $html = Markup::render($text);
        $canInspect = auth()->check() && auth()->user()->can('update', $project);
        $evidenceUrl = $canInspect ? route('projects.evidence', $project) : null;

        return (string) preg_replace_callback('/\[S(\d+)\]/', function (array $m) use ($paths, $evidenceUrl): string {
            $id = (int) $m[1];
            $path = $paths[$id] ?? null;

            if ($path === null) {
                return '<span class="rounded bg-red-100 px-1 font-mono text-xs font-semibold text-red-800" title="This source was not verified this turn">[S'.$id.' — unverified]</span>';
            }

            $chip = '<span class="rounded bg-emerald-100 px-1 font-mono text-xs font-semibold text-emerald-900" title="'.e($path).'">[S'.$id.']</span>';

            return $evidenceUrl !== null
                ? '<a href="'.e($evidenceUrl).'" class="hover:underline" title="'.e($path).'">'.$chip.'</a>'
                : $chip;
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
