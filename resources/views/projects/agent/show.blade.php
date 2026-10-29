<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Revival Agent</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">{{ $conversation->title ?? 'Conversation' }}</h1>
        <p class="mt-1 text-sm text-zinc-600"><a href="{{ route('projects.agent.index', $project) }}" class="font-semibold text-emerald-700">← All conversations</a></p>
    </x-slot>
    @include('projects._nav', ['project' => $project, 'active' => 'agent'])
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        @if(session('status'))
            <p class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</p>
        @endif
        @if(session('error'))
            <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ session('error') }}</p>
        @endif

        @if($conversation->artifact_consent_at !== null)
            <section class="mb-4 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm">
                <p class="font-semibold text-sky-900">Upload investigation</p>
                <p class="mt-1 text-sky-900">
                    Files:
                    @forelse($selectedArtifacts as $selected)
                        <a href="{{ route('projects.artifacts.show', [$project, $selected->id, 'from' => 'agent:'.$conversation->id]) }}" class="font-mono font-semibold hover:underline">[A{{ $selected->id }}]</a> {{ $selected->original_name }} ({{ $selected->state }})@if(!$loop->last), @endif
                    @empty
                        <span class="text-sky-800">The selected files are no longer available.</span>
                    @endforelse
                </p>
                @if($conversation->device_context)
                    <p class="mt-1 text-sky-900"><span class="font-semibold">Device context:</span> {{ $conversation->device_context }}</p>
                @endif
                <p class="mt-1 text-xs text-sky-800">Redacted evidence from these files was shared with the AI provider with your confirmation on {{ $conversation->artifact_consent_at->format('Y-m-d H:i') }}.</p>
                <form method="POST" action="{{ route('projects.agent.withdraw-consent', [$project, $conversation]) }}" class="mt-2" onsubmit="return confirm('Withdraw AI access to these files for this conversation? Future questions here will no longer send their evidence to the AI provider.');">
                    @csrf
                    <button type="submit" class="rounded-lg border border-sky-300 px-3 py-1.5 text-xs font-semibold text-sky-900 hover:bg-sky-100">Withdraw evidence consent</button>
                </form>
            </section>
        @endif

        <div class="space-y-4">
            @foreach($conversation->messages as $message)
                @if($message->isUser())
                    <section class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 sm:p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">You</p>
                        <p class="mt-1 whitespace-pre-wrap text-sm">{{ $message->content }}</p>
                    </section>
                @else
                    <section class="rounded-xl border border-zinc-200 bg-white p-4 sm:p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Revival Agent</p>
                        @if($message->stopped_reason !== null && $message->stopped_reason !== 'complete')
                            <p class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ $stopLabels[$message->stopped_reason] ?? $message->stopped_reason }}</p>
                        @endif
                        <div class="mt-2 max-w-[70ch] space-y-3 text-sm leading-relaxed text-zinc-800">{!! $rendered[$message->id] !!}</div>

                        @if(! empty($message->citations))
                            <div class="mt-3 border-t border-zinc-100 pt-3">
                                <p class="text-xs font-semibold text-zinc-500">Sources cited</p>
                                <ul class="mt-1 space-y-1 text-xs">
                                    @foreach($message->citations as $citation)
                                        <li class="font-mono">
                                            <span class="font-semibold">{{ $citation['marker'] }}</span>
                                            @if(isset($citation['artifact_id']))
                                                @if($citation['verified'] && isset($artifactNames[$citation['artifact_id']]))
                                                    <a href="{{ route('projects.artifacts.show', [$project, $citation['artifact_id'], 'from' => 'agent:'.$conversation->id]) }}" class="text-sky-700 hover:underline">{{ $artifactNames[$citation['artifact_id']] }}</a>
                                                @else
                                                    <span class="font-semibold text-red-700">not verified this turn</span>
                                                @endif
                                            @elseif($citation['verified'] && isset($paths[$citation['source_id']]))
                                                @can('update', $project)
                                                    <a href="{{ route('projects.evidence.show', [$project, $citation['source_id'], 'from' => 'agent:'.$conversation->id]) }}" class="text-emerald-700 hover:underline">{{ $paths[$citation['source_id']] }}</a>
                                                @else
                                                    <span class="text-zinc-600">{{ $paths[$citation['source_id']] }}</span>
                                                @endcan
                                            @else
                                                <span class="font-semibold text-red-700">not verified this turn</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if(! empty($message->token_usage))
                            <p class="mt-3 text-xs text-zinc-400">
                                @if(($message->token_usage['input'] ?? null) !== null || ($message->token_usage['output'] ?? null) !== null)
                                    {{ number_format((int) ($message->token_usage['input'] ?? 0)) }} in / {{ number_format((int) ($message->token_usage['output'] ?? 0)) }} out tokens
                                @else
                                    ≈{{ number_format((int) ($message->token_usage['estimated'] ?? 0)) }} tokens (estimated)
                                @endif
                                @if($message->duration_ms) · {{ number_format($message->duration_ms / 1000, 1) }}s @endif
                            </p>
                        @endif

                        @if(! empty($message->tool_trace))
                            <details class="mt-3 text-sm">
                                <summary class="cursor-pointer text-xs font-semibold text-zinc-500">Investigation trace ({{ count($message->tool_trace) }} lookups)</summary>
                                <ul class="mt-2 space-y-1 font-mono text-xs text-zinc-600">
                                    @foreach($message->tool_trace as $step)
                                        <li>
                                            <span class="{{ $step['ok'] ? 'text-emerald-700' : 'text-red-700' }}">{{ $step['ok'] ? '✓' : '✗' }}</span>
                                            {{ $step['name'] }}({{ $step['args'] }}) · {{ $step['ms'] }} ms
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif

                        <details class="mt-3 text-sm">
                            <summary class="cursor-pointer text-xs font-semibold text-zinc-500">Save as project observation…</summary>
                            <form method="POST" action="{{ route('projects.agent.save', [$project, $message]) }}" class="mt-2 flex flex-col gap-2 sm:flex-row">
                                @csrf
                                <label for="save-title-{{ $message->id }}" class="sr-only">Observation title</label>
                                <input id="save-title-{{ $message->id }}" name="title" required maxlength="255" placeholder="Observation title"
                                    class="flex-1 rounded-lg border border-zinc-300 px-3 py-2 text-sm" />
                                <button type="submit" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50">Save as observation</button>
                            </form>
                            <p class="mt-1 text-xs text-zinc-500">Saved as your unverified observation; nothing is published automatically.</p>
                        </details>
                    </section>
                @endif
            @endforeach
        </div>

        <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
            <h2 class="text-sm font-semibold">Follow up</h2>
            <form method="POST" action="{{ route('projects.agent.message', [$project, $conversation]) }}" class="mt-2">
                @csrf
                <label for="agent-followup" class="sr-only">Follow-up question</label>
                <textarea id="agent-followup" name="question" rows="3" required minlength="2" maxlength="2000"
                    placeholder="Ask a follow-up…"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">{{ old('question') }}</textarea>
                @error('question')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
                <button type="submit" class="mt-3 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Ask</button>
            </form>
        </section>
    </div>
</x-app-layout>
