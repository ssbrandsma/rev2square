<x-app-layout>
    <x-slot name="title">Report v{{ $report->version_number }} — {{ $project->title }}</x-slot>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">AI revival report · v{{ $report->version_number }}</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">{{ $project->title }}</h1>
        <p class="mt-1 text-sm text-zinc-600"><a href="{{ route('projects.evidence', $project) }}" class="font-semibold text-emerald-700">← Back to evidence</a></p>
    </x-slot>

    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6">
        @if(session('status'))
            <p class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</p>
        @endif
        @if($errors->has('review'))
            <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ $errors->first('review') }}</p>
        @endif

        <p class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900">
            AI-generated — {{ $report->review_status->label() }}. Nothing here is published or verified until a maintainer approves it.
            @if($report->isPublished()) This version was published {{ $report->published_at->diffForHumans() }}. Re-publishing refreshes the roadmap without duplicating tasks. @endif
        </p>

        <section class="mt-6 rounded-xl border border-zinc-200 bg-zinc-50 p-5">
            <h2 class="text-sm font-semibold">Maintainer review</h2>
            @if($report->review_status->value === 'PENDING_REVIEW')
                <form method="POST" action="{{ route('projects.reports.approve', [$project, $report]) }}" class="mt-3">
                    @csrf
                    <label for="review_notes" class="block text-sm font-semibold">Review notes <span class="font-normal text-zinc-500">(stored separately; the AI JSON is never edited)</span></label>
                    <textarea id="review_notes" name="review_notes" rows="2" maxlength="5000" class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('review_notes', $report->review_notes) }}</textarea>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Approve report</button>
                        <button type="submit" formaction="{{ route('projects.reports.reject', [$project, $report]) }}" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:border-zinc-400">Reject</button>
                    </div>
                </form>
            @elseif($report->review_status->value === 'APPROVED')
                <p class="mt-2 text-sm text-zinc-600">Approved{{ $report->reviewed_at ? ' '.$report->reviewed_at->diffForHumans() : '' }}. Publishing converts the suggested roadmap into project milestones and tasks.</p>
                <form method="POST" action="{{ route('projects.reports.publish', [$project, $report]) }}" class="mt-3">
                    @csrf
                    <button type="submit" class="rounded-lg bg-zinc-950 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-800">{{ $report->isPublished() ? 'Re-publish roadmap' : 'Publish roadmap' }}</button>
                </form>
            @else
                <p class="mt-2 text-sm text-zinc-600">This report was rejected. It stays in history; run a new analysis for a fresh version.</p>
            @endif
            @if($report->review_notes)
                <p class="mt-3 border-t border-zinc-200 pt-3 text-sm text-zinc-700"><span class="font-semibold">Maintainer notes:</span> {{ $report->review_notes }}</p>
            @endif
        </section>

        <section class="mt-8">
            <h2 class="text-sm font-semibold text-zinc-500">Assessment summary</h2>
            <p class="mt-2 leading-relaxed text-zinc-800">{{ $payload['summary'] ?? '' }}</p>
            <p class="mt-2 text-sm text-zinc-600"><span class="font-semibold text-zinc-900">Goal:</span> {{ $payload['goal'] ?? '' }}</p>
        </section>

        <section class="mt-10">
            <h2 class="text-sm font-semibold text-zinc-500">Findings ({{ count($payload['findings'] ?? []) }})</h2>
            <div class="mt-3 space-y-4">
                @forelse($payload['findings'] ?? [] as $finding)
                    @include('projects.reports._finding', ['finding' => $finding, 'evidenceById' => $evidenceById, 'sourcesById' => $sourcesById])
                @empty
                    <p class="text-sm text-zinc-600">No findings recorded.</p>
                @endforelse
            </div>
        </section>

        <section class="mt-10">
            <h2 class="text-sm font-semibold text-zinc-500">Open unknowns ({{ count($payload['unknowns'] ?? []) }})</h2>
            <ul class="mt-3 space-y-2">
                @foreach($payload['unknowns'] ?? [] as $unknown)
                    <li class="rounded-xl border border-zinc-200 bg-white p-4 text-sm">
                        <p class="font-semibold">{{ $unknown['question'] }}</p>
                        <p class="mt-1 text-zinc-600">{{ $unknown['why_it_matters'] }}</p>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="mt-10">
            <h2 class="text-sm font-semibold text-zinc-500">Revival approaches ({{ count($payload['revival_paths'] ?? []) }})</h2>
            <div class="mt-3 space-y-4">
                @foreach($payload['revival_paths'] ?? [] as $path)
                    @include('projects.reports._path', ['path' => $path])
                @endforeach
            </div>
        </section>

        <section class="mt-10">
            <h2 class="text-sm font-semibold text-zinc-500">Proposed experiments ({{ count($payload['experiments'] ?? []) }})</h2>
            <div class="mt-3 space-y-4">
                @foreach($payload['experiments'] ?? [] as $experiment)
                    @include('projects.reports._experiment', ['experiment' => $experiment])
                @endforeach
            </div>
        </section>

        <section class="mt-10">
            <h2 class="text-sm font-semibold text-zinc-500">Suggested roadmap</h2>
            <div class="mt-3 grid gap-4 md:grid-cols-2">
                <div>
                    <h3 class="text-sm font-bold">Milestones</h3>
                    <ul class="mt-2 space-y-2">
                        @foreach($payload['milestones'] ?? [] as $milestone)
                            <li class="rounded-lg border border-zinc-200 bg-white p-3 text-sm"><p class="font-semibold">{{ $milestone['title'] }}</p><p class="text-zinc-600">{{ $milestone['description'] }}</p></li>
                        @endforeach
                    </ul>
                </div>
                <div>
                    <h3 class="text-sm font-bold">Contributor tasks</h3>
                    <ul class="mt-2 space-y-2">
                        @foreach($payload['contributor_tasks'] ?? [] as $task)
                            <li class="rounded-lg border border-zinc-200 bg-white p-3 text-sm">
                                <p class="font-semibold">{{ $task['title'] }}</p>
                                <p class="mt-1 font-mono text-[11px] text-zinc-600">{{ implode(', ', $task['skill_tags'] ?? []) }}{{ ($task['requires_hardware'] ?? false) ? ' · needs hardware' : '' }}</p>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </section>

        @if(!empty($payload['warnings'] ?? []))
            <section class="mt-10 rounded-xl border border-amber-300 bg-amber-50 p-5">
                <h2 class="text-sm font-semibold text-amber-800">Warnings</h2>
                <ul class="mt-2 list-disc pl-5 text-sm text-amber-900">
                    @foreach($payload['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach
                </ul>
            </section>
        @endif

        <section class="mt-10 border-t border-zinc-200 pt-6">
            <h2 class="text-sm font-semibold text-zinc-500">Provenance</h2>
            <dl class="mt-2 grid gap-2 text-sm text-zinc-600 sm:grid-cols-2">
                <div><dt class="text-xs font-semibold text-zinc-500">Model</dt><dd>{{ $report->run->model ?? 'unknown' }} ({{ $report->run->provider }})</dd></div>
                <div><dt class="text-xs font-semibold text-zinc-500">Schema</dt><dd>v{{ $report->run->schema_version }}</dd></div>
                <div><dt class="text-xs font-semibold text-zinc-500">Run</dt><dd>#{{ $report->run->id }} · {{ $report->created_at->diffForHumans() }}</dd></div>
                <div><dt class="text-xs font-semibold text-zinc-500">Tokens</dt><dd>{{ $report->run->token_usage['total'] ?? 'n/a' }} (in {{ $report->run->token_usage['input'] ?? '?' }} / out {{ $report->run->token_usage['output'] ?? '?' }})</dd></div>
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
