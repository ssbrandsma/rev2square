<x-app-layout>
    <x-slot name="title">Report v{{ $report->version_number }} — {{ $project->title }}</x-slot>
    <x-slot name="header">
        <p class="font-mono text-xs uppercase tracking-widest text-emerald-700">AI revival report · v{{ $report->version_number }}</p>
        <h1 class="mt-1 text-2xl font-extrabold tracking-tight">{{ $project->title }}</h1>
        <p class="mt-1 text-sm text-zinc-600"><a href="{{ route('projects.evidence', $project) }}" class="font-semibold text-emerald-700">← Back to evidence</a></p>
    </x-slot>

    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6">
        <p class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900">
            AI-generated — {{ $report->review_status->label() }}. Nothing here is published or verified until a maintainer approves it.
        </p>

        <section class="mt-8">
            <h2 class="font-mono text-xs uppercase tracking-widest text-zinc-500">Assessment summary</h2>
            <p class="mt-2 leading-relaxed text-zinc-800">{{ $payload['summary'] ?? '' }}</p>
            <p class="mt-2 text-sm text-zinc-600"><span class="font-semibold text-zinc-900">Goal:</span> {{ $payload['goal'] ?? '' }}</p>
        </section>

        <section class="mt-10">
            <h2 class="font-mono text-xs uppercase tracking-widest text-zinc-500">Findings ({{ count($payload['findings'] ?? []) }})</h2>
            <div class="mt-3 space-y-4">
                @forelse($payload['findings'] ?? [] as $finding)
                    <div class="rounded-xl border border-zinc-200 p-5">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="font-bold">{{ $finding['statement'] }}</p>
                            <span class="rounded-full px-2.5 py-0.5 font-mono text-[11px] font-bold
                                @if($finding['status'] === 'SUPPORTED') bg-emerald-100 text-emerald-800
                                @elseif($finding['status'] === 'CONTRADICTED') bg-red-100 text-red-800
                                @elseif($finding['status'] === 'INFERRED') bg-sky-100 text-sky-800
                                @else bg-zinc-100 text-zinc-700 @endif">{{ $finding['status'] }}</span>
                        </div>
                        @if($finding['limitations'] ?? '')
                            <p class="mt-2 text-sm text-zinc-600"><span class="font-semibold">Limits:</span> {{ $finding['limitations'] }}</p>
                        @endif
                        <ul class="mt-3 space-y-1.5 border-t border-zinc-100 pt-3">
                            @foreach($finding['evidence_ids'] ?? [] as $ref)
                                @php($item = $evidenceById->get($ref))
                                <li class="text-sm">
                                    <span class="font-mono text-xs font-bold text-zinc-500">{{ $ref }}</span>
                                    @if($item)
                                        <span class="text-zinc-700">“{{ $item['claim'] }}”</span>
                                        @php($source = $sourcesById->get($item['source_id']))
                                        <span class="text-zinc-500">—
                                            @if($source)<a href="{{ $source->url }}" target="_blank" rel="noopener" class="font-mono text-xs hover:text-emerald-700">{{ $item['locator'] }}</a>
                                            @else<span class="font-mono text-xs">{{ $item['locator'] }}</span>@endif
                                        </span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @empty
                    <p class="text-sm text-zinc-600">No findings recorded.</p>
                @endforelse
            </div>
        </section>

        <section class="mt-10">
            <h2 class="font-mono text-xs uppercase tracking-widest text-zinc-500">Open unknowns ({{ count($payload['unknowns'] ?? []) }})</h2>
            <ul class="mt-3 space-y-2">
                @foreach($payload['unknowns'] ?? [] as $unknown)
                    <li class="rounded-xl border border-zinc-200 p-4 text-sm">
                        <p class="font-semibold">{{ $unknown['question'] }}</p>
                        <p class="mt-1 text-zinc-600">{{ $unknown['why_it_matters'] }}</p>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="mt-10">
            <h2 class="font-mono text-xs uppercase tracking-widest text-zinc-500">Revival approaches ({{ count($payload['revival_paths'] ?? []) }})</h2>
            <div class="mt-3 space-y-4">
                @foreach($payload['revival_paths'] ?? [] as $path)
                    <div class="rounded-xl border border-zinc-200 p-5">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="font-bold">{{ $path['approach'] }}</p>
                            <span class="rounded-full px-2.5 py-0.5 font-mono text-[11px] font-bold
                                @if($path['feasibility'] === 'PROMISING') bg-emerald-100 text-emerald-800
                                @elseif($path['feasibility'] === 'HIGH_RISK') bg-red-100 text-red-800
                                @elseif($path['feasibility'] === 'INSUFFICIENT_EVIDENCE') bg-zinc-200 text-zinc-800
                                @else bg-amber-100 text-amber-800 @endif">{{ $path['feasibility'] }}</span>
                        </div>
                        @if(!empty($path['assumptions']))<p class="mt-2 text-sm text-zinc-600"><span class="font-semibold text-zinc-900">Assumes:</span> {{ implode('; ', $path['assumptions']) }}</p>@endif
                        @if(!empty($path['risks']))<p class="mt-1 text-sm text-zinc-600"><span class="font-semibold text-zinc-900">Risks:</span> {{ implode('; ', $path['risks']) }}</p>@endif
                        @if(!empty($path['validation_needed']))<p class="mt-1 text-sm text-zinc-600"><span class="font-semibold text-zinc-900">Needed to validate:</span> {{ implode('; ', $path['validation_needed']) }}</p>@endif
                    </div>
                @endforeach
            </div>
        </section>

        <section class="mt-10">
            <h2 class="font-mono text-xs uppercase tracking-widest text-zinc-500">Proposed experiments ({{ count($payload['experiments'] ?? []) }})</h2>
            <div class="mt-3 space-y-4">
                @foreach($payload['experiments'] ?? [] as $experiment)
                    <div class="rounded-xl border border-zinc-200 p-5">
                        <p class="font-bold">{{ $experiment['question'] }}</p>
                        <p class="mt-1 text-sm italic text-zinc-600">Hypothesis: {{ $experiment['hypothesis'] }}</p>
                        <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-zinc-700">
                            @foreach($experiment['procedure'] ?? [] as $step)<li>{{ $step }}</li>@endforeach
                        </ol>
                        <div class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                            <p class="rounded-lg bg-emerald-50 p-3"><span class="font-semibold text-emerald-900">Pass:</span> {{ implode('; ', $experiment['pass_criteria'] ?? []) }}</p>
                            <p class="rounded-lg bg-red-50 p-3"><span class="font-semibold text-red-900">Fail:</span> {{ implode('; ', $experiment['fail_criteria'] ?? []) }}</p>
                        </div>
                        @if(!empty($experiment['safety_notes']))
                            <p class="mt-2 text-sm text-amber-800"><span class="font-semibold">Safety:</span> {{ implode('; ', $experiment['safety_notes']) }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        <section class="mt-10">
            <h2 class="font-mono text-xs uppercase tracking-widest text-zinc-500">Suggested roadmap</h2>
            <div class="mt-3 grid gap-4 md:grid-cols-2">
                <div>
                    <h3 class="text-sm font-bold">Milestones</h3>
                    <ul class="mt-2 space-y-2">
                        @foreach($payload['milestones'] ?? [] as $milestone)
                            <li class="rounded-lg border border-zinc-200 p-3 text-sm"><p class="font-semibold">{{ $milestone['title'] }}</p><p class="text-zinc-600">{{ $milestone['description'] }}</p></li>
                        @endforeach
                    </ul>
                </div>
                <div>
                    <h3 class="text-sm font-bold">Contributor tasks</h3>
                    <ul class="mt-2 space-y-2">
                        @foreach($payload['contributor_tasks'] ?? [] as $task)
                            <li class="rounded-lg border border-zinc-200 p-3 text-sm">
                                <p class="font-semibold">{{ $task['title'] }}</p>
                                <p class="mt-1 font-mono text-[11px] text-zinc-600">{{ implode(', ', $task['skill_tags'] ?? []) }}{{ ($task['requires_hardware'] ?? false) ? ' · needs hardware' : '' }}</p>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <p class="mt-3 text-xs text-zinc-500">Roadmap publishing (maintainer approval → milestones/tasks) arrives in WP4.</p>
        </section>

        @if(!empty($payload['warnings'] ?? []))
            <section class="mt-10 rounded-xl border border-amber-300 bg-amber-50 p-5">
                <h2 class="font-mono text-xs uppercase tracking-widest text-amber-800">Warnings</h2>
                <ul class="mt-2 list-disc pl-5 text-sm text-amber-900">
                    @foreach($payload['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach
                </ul>
            </section>
        @endif

        <section class="mt-10 border-t border-zinc-200 pt-6">
            <h2 class="font-mono text-xs uppercase tracking-widest text-zinc-500">Provenance</h2>
            <dl class="mt-2 grid gap-2 text-sm text-zinc-600 sm:grid-cols-2">
                <div><dt class="font-mono text-xs uppercase">Model</dt><dd>{{ $report->run->model ?? 'unknown' }} ({{ $report->run->provider }})</dd></div>
                <div><dt class="font-mono text-xs uppercase">Schema</dt><dd>v{{ $report->run->schema_version }}</dd></div>
                <div><dt class="font-mono text-xs uppercase">Run</dt><dd>#{{ $report->run->id }} · {{ $report->created_at->diffForHumans() }}</dd></div>
                <div><dt class="font-mono text-xs uppercase">Tokens</dt><dd>{{ $report->run->token_usage['total'] ?? 'n/a' }} (in {{ $report->run->token_usage['input'] ?? '?' }} / out {{ $report->run->token_usage['output'] ?? '?' }})</dd></div>
            </dl>
            <h3 class="mt-6 text-sm font-bold">Report history</h3>
            <ul class="mt-2 space-y-1 text-sm">
                @foreach($versions as $version)
                    <li>
                        @if($version->id === $report->id)
                            <span class="font-semibold">v{{ $version->version_number }} (this report) · {{ $version->review_status->label() }}</span>
                        @else
                            <a href="{{ route('projects.reports.show', [$project, $version]) }}" class="text-emerald-700 hover:text-emerald-600">v{{ $version->version_number }}</a>
                            <span class="text-zinc-500">· {{ $version->review_status->label() }} · {{ $version->created_at->diffForHumans() }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    </div>
</x-app-layout>
