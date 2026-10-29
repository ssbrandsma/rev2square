<x-app-layout>
    <x-slot name="title">S{{ $source->id }} — {{ $source->path }}</x-slot>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Evidence · S{{ $source->id }}</p>
        <h1 class="mt-1 break-all font-mono text-[1.35rem] font-semibold leading-tight tracking-tight sm:text-2xl">{{ $source->path }}</h1>
        <p class="mt-1 text-sm text-zinc-600">
            <a href="{{ route('projects.evidence', $project) }}" class="font-semibold text-emerald-700">← Evidence manifest</a>
            @if($backToConversation)
                <span class="mx-1 text-zinc-300">·</span>
                <a href="{{ route('projects.agent.show', [$project, $backToConversation]) }}" class="font-semibold text-emerald-700">← Back to conversation</a>
            @endif
        </p>
    </x-slot>
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        @if($stale)
            <p class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <strong>Earlier import.</strong> This source was stored at commit {{ substr((string) $source->commit_sha, 0, 12) }};
                the current import may differ. The stored content below is exactly what past analyses cited.
            </p>
        @endif

        <section class="rounded-xl border border-zinc-200 bg-white p-6">
            <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-xs font-semibold text-zinc-500">Source</dt><dd class="mt-0.5 font-mono text-xs font-bold">S{{ $source->id }}</dd></div>
                <div><dt class="text-xs font-semibold text-zinc-500">Commit</dt><dd class="mt-0.5 font-mono text-xs">{{ substr((string) $source->commit_sha, 0, 12) }}</dd></div>
                <div><dt class="text-xs font-semibold text-zinc-500">SHA-256</dt><dd class="mt-0.5 font-mono text-xs">{{ substr((string) $source->content_hash, 0, 16) }}…</dd></div>
                <div><dt class="text-xs font-semibold text-zinc-500">Fetched</dt><dd class="mt-0.5">{{ $source->fetched_at?->diffForHumans() ?? 'unknown' }}</dd></div>
            </dl>
            <dl class="mt-3 grid gap-3 border-t border-zinc-100 pt-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-semibold text-zinc-500">Coverage</dt>
                    <dd class="mt-0.5">
                        @if($source->isPartial())
                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Partial excerpt</span>
                            @if(!empty($source->line_ranges))
                                <span class="ml-1 font-mono text-xs text-zinc-600">@foreach($source->line_ranges as $range)L{{ $range['start'] }}–L{{ $range['end'] }}@if(!$loop->last), @endif@endforeach@if($source->original_lines) of {{ number_format($source->original_lines) }} original lines@endif</span>
                            @else
                                <span class="ml-1 text-xs text-zinc-600">Original line numbers unavailable for this excerpt.</span>
                            @endif
                        @else
                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">Complete file</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold text-zinc-500">Upstream</dt>
                    <dd class="mt-0.5 text-xs">
                        @if($source->url)
                            <a href="{{ $source->url }}" target="_blank" rel="noopener" class="font-mono text-emerald-700 hover:text-emerald-600">Open on GitHub ↗</a>
                        @else
                            <span class="text-zinc-500">No upstream URL recorded.</span>
                        @endif
                    </dd>
                </div>
            </dl>
        </section>

        <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
            <h2 class="font-semibold">Stored content</h2>
            <p class="mt-1 text-xs text-zinc-500">{{ number_format($source->byteSize()) }} bytes · exactly as imported — line numbers below are positions within this stored excerpt.</p>
            <pre class="mt-3 overflow-x-auto rounded-lg bg-zinc-950 p-4 font-mono text-[13px] leading-relaxed text-zinc-100">{{ $source->content }}</pre>
        </section>
    </div>
</x-app-layout>
