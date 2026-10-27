<x-app-layout>
    <x-slot name="title">Revival assessment v{{ $report->version_number }} — {{ $project->title }}</x-slot>
    <section class="bg-zinc-950 text-white">
        <div class="mx-auto max-w-4xl px-4 py-12 sm:px-6">
            <p class="font-mono text-xs uppercase tracking-widest text-zinc-400">Revival assessment · v{{ $report->version_number }} · reviewed by the maintainers</p>
            <h1 class="mt-2 text-3xl font-extrabold tracking-tight">{{ $project->title }}</h1>
            <p class="mt-3 max-w-2xl text-zinc-300">{{ $payload['summary'] ?? '' }}</p>
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach($payload['revival_paths'] ?? [] as $path)
                    <span class="rounded-full px-3 py-1 text-xs font-semibold
                        @if($path['feasibility'] === 'PROMISING') bg-emerald-900/60 text-emerald-300
                        @elseif($path['feasibility'] === 'HIGH_RISK') bg-red-900/60 text-red-300
                        @elseif($path['feasibility'] === 'INSUFFICIENT_EVIDENCE') bg-zinc-800 text-zinc-300
                        @else bg-amber-900/60 text-amber-300 @endif">
                        {{ $path['feasibility'] }}: {{ \Illuminate\Support\Str::limit($path['approach'], 60) }}
                    </span>
                @endforeach
            </div>
        </div>
    </section>

    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6">
        <p class="rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-700">
            <span class="font-semibold">How to read this:</span>
            AI-drafted conclusions are labeled with their uncertainty; every cited fact links to its source.
            Proposed experiments are plans, not completed tests. Maintainer notes speak for the humans.
        </p>

        @if($report->review_notes)
            <section class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-5">
                <h2 class="font-mono text-xs uppercase tracking-widest text-emerald-800">Maintainer notes</h2>
                <p class="mt-2 text-sm text-emerald-950">{{ $report->review_notes }}</p>
            </section>
        @endif

        <section class="mt-8">
            <h2 class="font-mono text-xs uppercase tracking-widest text-zinc-500">Key findings</h2>
            <div class="mt-3 space-y-4">
                @foreach(array_slice($payload['findings'] ?? [], 0, 5) as $finding)
                    @include('projects.reports._finding', ['finding' => $finding, 'evidenceById' => $evidenceById, 'sourcesById' => $sourcesById])
                @endforeach
            </div>
            @if(count($payload['findings'] ?? []) > 5)
                <details class="mt-4 rounded-xl border border-zinc-200">
                    <summary class="cursor-pointer px-5 py-3 text-sm font-semibold text-emerald-700">Show all {{ count($payload['findings']) }} findings</summary>
                    <div class="space-y-4 border-t border-zinc-100 p-5">
                        @foreach(array_slice($payload['findings'], 5) as $finding)
                            @include('projects.reports._finding', ['finding' => $finding, 'evidenceById' => $evidenceById, 'sourcesById' => $sourcesById])
                        @endforeach
                    </div>
                </details>
            @endif
        </section>

        <section class="mt-8">
            <h2 class="font-mono text-xs uppercase tracking-widest text-zinc-500">What is still unknown</h2>
            <ul class="mt-3 space-y-2">
                @foreach($payload['unknowns'] ?? [] as $unknown)
                    <li class="rounded-xl border border-zinc-200 bg-white p-4 text-sm">
                        <p class="font-semibold">{{ $unknown['question'] }}</p>
                        <p class="mt-1 text-zinc-600">{{ $unknown['why_it_matters'] }}</p>
                    </li>
                @endforeach
            </ul>
        </section>

        <details class="mt-8 rounded-xl border border-zinc-200" open>
            <summary class="cursor-pointer px-5 py-4 font-bold">Revival approaches ({{ count($payload['revival_paths'] ?? []) }})</summary>
            <div class="space-y-4 border-t border-zinc-100 p-5">
                @foreach($payload['revival_paths'] ?? [] as $path)
                    @include('projects.reports._path', ['path' => $path])
                @endforeach
            </div>
        </details>

        <details class="mt-4 rounded-xl border border-zinc-200">
            <summary class="cursor-pointer px-5 py-4 font-bold">Proposed experiments ({{ count($payload['experiments'] ?? []) }}) — plans, not results</summary>
            <div class="space-y-4 border-t border-zinc-100 p-5">
                @foreach($payload['experiments'] ?? [] as $experiment)
                    @include('projects.reports._experiment', ['experiment' => $experiment])
                @endforeach
            </div>
        </details>

        @if(!empty($payload['warnings'] ?? []))
            <section class="mt-8 rounded-xl border border-amber-300 bg-amber-50 p-5">
                <h2 class="font-mono text-xs uppercase tracking-widest text-amber-800">Caveats</h2>
                <ul class="mt-2 list-disc pl-5 text-sm text-amber-900">
                    @foreach($payload['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach
                </ul>
            </section>
        @endif

        <section class="mt-8 border-t border-zinc-200 pt-6">
            <h2 class="font-mono text-xs uppercase tracking-widest text-zinc-500">Provenance</h2>
            <p class="mt-2 text-sm text-zinc-600">AI-drafted with {{ $report->run->model ?? 'unknown model' }} and approved by the project maintainers on {{ $report->reviewed_at?->toDateString() ?? '—' }}. Published {{ $report->published_at?->diffForHumans() ?? '' }}.</p>
            @if($versions->count() > 1)
                <h3 class="mt-4 text-sm font-bold">Earlier published assessments</h3>
                <ul class="mt-2 space-y-1 text-sm">
                    @foreach($versions as $version)
                        @if($version->id === $report->id)
                            <li><span class="font-semibold">v{{ $version->version_number }} (this version)</span></li>
                        @else
                            <li><a href="{{ route('projects.reports.show', [$project, $version]) }}" class="text-emerald-700 hover:text-emerald-600">v{{ $version->version_number }}</a> <span class="text-zinc-500">· published {{ $version->published_at?->diffForHumans() }}</span></li>
                        @endif
                    @endforeach
                </ul>
            @endif
            <p class="mt-4"><a href="{{ route('projects.show', $project) }}" class="text-sm font-semibold text-emerald-700">← Back to {{ $project->title }}</a></p>
        </section>
    </div>
</x-app-layout>
