<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Architecture</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">{{ $project->title }}</h1>
        <p class="mt-1 text-sm text-zinc-600"><a href="{{ route('projects.show', $project) }}" class="font-semibold text-emerald-700">← Back to project</a></p>
    </x-slot>
    @include('projects._nav', ['project' => $project, 'active' => 'architecture'])
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        @if(session('status'))
            <p class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</p>
        @endif
        @if(session('error'))
            <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ session('error') }}</p>
        @endif

        @if($repository === null || ! $repository->isReady())
            <section class="rounded-xl border border-zinc-200 bg-white p-6">
                <h2 class="font-semibold">No architecture yet</h2>
                <p class="mt-1 text-sm text-zinc-600">The Architecture Investigator maps components and relationships from imported repository evidence. Import a repository first, then run the analysis.</p>
                @can('update', $project)
                    <p class="mt-4"><a href="{{ route('projects.evidence', $project) }}" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Manage evidence</a></p>
                @endcan
            </section>
        @elseif($latest !== null && $latest->isActive())
            <section class="rounded-xl border border-zinc-200 bg-white p-6" data-architecture-poll>
                <h2 class="font-semibold">Analysis {{ strtolower($latest->status->label()) }}…</h2>
                <p class="mt-1 text-sm text-zinc-600">Started {{ $latest->created_at->diffForHumans() }}. This view refreshes automatically.</p>
                <p class="mt-4 text-sm text-zinc-500" aria-live="polite">Resolving the snapshot, selecting evidence, and interpreting the architecture.</p>
            </section>
            @if($snapshot !== null)
                <p class="mt-4 text-sm text-zinc-600">A previous snapshot is shown below while the new analysis runs.</p>
            @endif
        @elseif($latest !== null && $latest->status->isFailure() && $snapshot === null)
            <section class="rounded-xl border border-red-200 bg-white p-6">
                <h2 class="font-semibold">Architecture analysis failed</h2>
                <p class="mt-1 text-sm text-zinc-600">{{ $latest->error_message ?? 'The analysis did not complete.' }}</p>
                <p class="mt-1 text-xs text-zinc-500">Error code: {{ $latest->error_code ?? 'unknown' }} · {{ $latest->finished_at?->toDateTimeString() }}</p>
                @can('update', $project)
                    <form method="POST" action="{{ route('projects.architecture.store', $project) }}" class="mt-4">
                        @csrf
                        <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Retry analysis</button>
                    </form>
                @endcan
            </section>
        @endif

        @if($snapshot !== null)
            @if($staleness !== null)
                <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    @if($staleness === 'stale_commit')
                        <strong>Stale snapshot.</strong> The repository was re-imported after this analysis. Run a new analysis to map the current revision.
                    @elseif($staleness === 'stale_evidence')
                        <strong>Stale snapshot.</strong> The evidence selection changed after this analysis. Run a new analysis to refresh it.
                    @else
                        <strong>Outdated format.</strong> This snapshot uses an older schema. Run a new analysis to refresh it.
                    @endif
                    @can('update', $project)
                        <form method="POST" action="{{ route('projects.architecture.store', $project) }}" class="mt-2">
                            @csrf
                            <button type="submit" class="rounded-lg bg-amber-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-amber-500">Run new analysis</button>
                        </form>
                    @endcan
                </div>
            @endif

            <section class="rounded-xl border border-zinc-200 bg-white p-6">
                <h2 class="font-semibold">Architecture overview</h2>
                <p class="mt-2 max-w-[70ch] text-sm leading-relaxed text-zinc-700">{{ $snapshot->summary() }}</p>
                <dl class="mt-4 grid gap-2 text-xs text-zinc-500 sm:grid-cols-2 lg:grid-cols-4">
                    <div><dt class="font-semibold">Snapshot</dt><dd>Commit {{ substr((string) $snapshot->manifest_commit_sha, 0, 12) }} · {{ $snapshot->finished_at?->diffForHumans() }}</dd></div>
                    <div><dt class="font-semibold">Findings</dt><dd>{{ count($snapshot->components()) }} components · {{ count($snapshot->relationships()) }} relationships · {{ count($snapshot->unknowns()) }} open questions</dd></div>
                    <div><dt class="font-semibold">Schema / prompt</dt><dd>v{{ $snapshot->schema_version }} / v{{ $snapshot->prompt_version }}</dd></div>
                    <div><dt class="font-semibold">Model</dt><dd>{{ $snapshot->model ?? 'unknown' }}{{ $snapshot->token_usage ? ' · '.number_format((int) ($snapshot->token_usage['total'] ?? 0)).' tokens' : '' }}</dd></div>
                </dl>
                @can('update', $project)
                    <form method="POST" action="{{ route('projects.architecture.store', $project) }}" class="mt-4">
                        @csrf
                        <button type="submit" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50">Re-run analysis</button>
                    </form>
                @endcan
            </section>

            <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                <h2 class="font-semibold">Diagram</h2>
                <p class="mt-1 text-sm text-zinc-600">Select a component or a relationship to inspect its explanation and evidence.</p>
                <div class="mt-4 grid gap-6 lg:grid-cols-5">
                    <div class="overflow-x-auto lg:col-span-3">
                        <div data-architecture-diagram
                            data-diagram-empty="Diagram unavailable in this browser — the full component and relationship lists below carry the same findings."
                            class="min-w-[320px] rounded-lg border border-zinc-100 bg-zinc-50 p-2"></div>
                        <script type="application/json" data-architecture-graph>@json($snapshot->payload)</script>
                    </div>
                    <div class="lg:col-span-2">
                        <div data-architecture-details class="rounded-lg border border-zinc-200 p-4 text-sm">
                            <p class="text-zinc-500">Nothing selected yet.</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                <h2 class="font-semibold">Components ({{ count($snapshot->components()) }})</h2>
                <ul class="mt-2 divide-y divide-zinc-100">
                    @foreach($snapshot->components() as $component)
                        <li class="py-3" id="component-{{ $component['id'] }}">
                            <p class="text-sm">
                                <span class="font-semibold">{{ $component['label'] }}</span>
                                <span class="text-zinc-500">· {{ \App\Services\Revival\ArchitectureSchema::componentTypeLabel($component['type']) }}</span>
                                <span class="ml-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ \App\Services\Revival\ArchitectureSchema::certaintyBadge($component['certainty']) }}">{{ $component['certainty'] }}</span>
                            </p>
                            <p class="mt-1 max-w-[70ch] text-sm text-zinc-700">{{ $component['explanation'] }}</p>
                            <p class="mt-1 text-xs text-zinc-500">Evidence:
                                @foreach($component['evidence'] as $ref)
                                    @can('update', $project)
                                        <a href="{{ route('projects.evidence', $project) }}" class="font-mono text-emerald-700 hover:underline">{{ $ref['locator'] }}</a>@if(!$loop->last), @endif
                                    @else
                                        <span class="font-mono">{{ $ref['locator'] }}</span>@if(!$loop->last), @endif
                                    @endcan
                                @endforeach
                            </p>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                <h2 class="font-semibold">Relationships ({{ count($snapshot->relationships()) }})</h2>
                <ul class="mt-2 divide-y divide-zinc-100">
                    @foreach($snapshot->relationships() as $edge)
                        <li class="py-3" id="relationship-{{ $edge['id'] }}">
                            <p class="text-sm">
                                <a href="#component-{{ $edge['from'] }}" class="font-semibold text-emerald-700 hover:underline">{{ $edge['from'] }}</a>
                                <span class="text-zinc-500">— {{ \App\Services\Revival\ArchitectureSchema::relationshipTypeLabel($edge['type']) }} →</span>
                                <a href="#component-{{ $edge['to'] }}" class="font-semibold text-emerald-700 hover:underline">{{ $edge['to'] }}</a>
                                <span class="ml-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ \App\Services\Revival\ArchitectureSchema::certaintyBadge($edge['certainty']) }}">{{ $edge['certainty'] }}</span>
                            </p>
                            <p class="mt-1 text-sm text-zinc-700"><span class="font-medium">{{ $edge['label'] }}.</span> {{ $edge['explanation'] }}</p>
                            <p class="mt-1 text-xs text-zinc-500">Evidence:
                                @foreach($edge['evidence'] as $ref)
                                    <span class="font-mono">{{ $ref['locator'] }}</span>@if(!$loop->last), @endif
                                @endforeach
                            </p>
                        </li>
                    @endforeach
                </ul>
            </section>

            @if($snapshot->unknowns() !== [])
                <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                    <h2 class="font-semibold">Open questions ({{ count($snapshot->unknowns()) }})</h2>
                    <ul class="mt-2 divide-y divide-zinc-100">
                        @foreach($snapshot->unknowns() as $unknown)
                            <li class="py-3 text-sm">
                                <p class="font-medium">{{ $unknown['question'] }}</p>
                                <p class="mt-1 text-zinc-600">{{ $unknown['why_it_matters'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section class="mt-6 rounded-xl border border-zinc-200 bg-zinc-50 p-6">
                <h2 class="text-sm font-semibold text-zinc-500">Coverage</h2>
                <p class="mt-1 max-w-[70ch] text-sm text-zinc-700">{{ $snapshot->payload['coverage_note'] ?? '' }}</p>
            </section>
        @elseif($latest === null && $repository !== null && $repository->isReady())
            <section class="rounded-xl border border-zinc-200 bg-white p-6">
                <h2 class="font-semibold">Map this project's architecture</h2>
                <p class="mt-1 text-sm text-zinc-600">The investigator reads the imported evidence, measures entry points, imports, and configuration files, and produces a validated, clickable diagram with source citations.</p>
                @can('update', $project)
                    <form method="POST" action="{{ route('projects.architecture.store', $project) }}" class="mt-4">
                        @csrf
                        <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Run architecture analysis</button>
                    </form>
                @else
                    <p class="mt-4 text-sm text-zinc-500">Only maintainers can run the analysis.</p>
                @endcan
            </section>
        @endif
    </div>
</x-app-layout>
