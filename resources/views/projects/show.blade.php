<x-app-layout>
    <x-slot name="title">{{ $project->title }}</x-slot>
    <section class="bg-zinc-950 text-white">
        <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6">
            <p class="font-mono text-xs uppercase tracking-widest text-zinc-400">{{ $project->devices->first()?->displayName() ?? 'Device to be linked' }}</p>
            <h1 class="mt-2 text-3xl font-extrabold tracking-tight sm:text-4xl">{{ $project->title }}</h1>
            @if($project->pitch)
                <p class="mt-3 max-w-2xl text-lg text-zinc-300">{{ $project->pitch }}</p>
            @endif
            <div class="mt-4 flex flex-wrap items-center gap-2">
                <span class="rounded-full bg-emerald-900/60 px-3 py-1 text-xs font-semibold text-emerald-300">{{ $project->phase->label() }}</span>
                <span class="rounded-full bg-zinc-800 px-3 py-1 text-xs font-semibold text-zinc-200">{{ $project->activity->label() }}</span>
                @unless($project->isPublished())
                    <span class="rounded-full bg-amber-900/60 px-3 py-1 text-xs font-semibold text-amber-300">Draft — visible to maintainers only</span>
                @endunless
                @can('update', $project)
                    <a href="{{ route('projects.edit', $project) }}" class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-zinc-900 hover:bg-zinc-200">Edit project</a>
                    <a href="{{ route('projects.offers.index', $project) }}" class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-zinc-900 hover:bg-zinc-200">Offers ({{ $project->offers()->where('status', 'pending')->count() }})</a>
                @endcan
            </div>
        </div>
    </section>

    <div class="mx-auto grid max-w-6xl gap-10 px-4 py-12 sm:px-6 lg:grid-cols-3">
        <div class="space-y-10 lg:col-span-2">
            @php($publishedReport = $project->reports()->whereNotNull('published_at')->first())
            @if($publishedReport)
                @php($assessment = $publishedReport->payload_json)
                <section class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-6">
                    <p class="font-mono text-xs uppercase tracking-widest text-emerald-800">Revival assessment · v{{ $publishedReport->version_number }} · maintainer-approved</p>
                    <p class="mt-2 leading-relaxed text-zinc-800">{{ $assessment['summary'] ?? '' }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach($assessment['revival_paths'] ?? [] as $path)
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold
                                @if($path['feasibility'] === 'PROMISING') bg-emerald-100 text-emerald-900
                                @elseif($path['feasibility'] === 'HIGH_RISK') bg-red-100 text-red-900
                                @elseif($path['feasibility'] === 'INSUFFICIENT_EVIDENCE') bg-zinc-200 text-zinc-800
                                @else bg-amber-100 text-amber-900 @endif">{{ $path['feasibility'] }}: {{ \Illuminate\Support\Str::limit($path['approach'], 60) }}</span>
                        @endforeach
                    </div>
                    <p class="mt-3 text-sm"><a href="{{ route('projects.reports.show', [$project, $publishedReport]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Read the full assessment with sources →</a></p>
                </section>
            @endif
            <section>
                <h2 class="font-mono text-xs uppercase tracking-widest text-zinc-500">Project story</h2>
                <div class="mt-3 space-y-3 text-[15px] leading-relaxed text-zinc-700">
                    @foreach(preg_split('/\n\s*\n/', trim($project->body ?? '')) ?: [] as $paragraph)
                        <p>{{ $paragraph }}</p>
                    @endforeach
                    @if(blank($project->body))
                        <p class="text-zinc-500">The maintainer has not written the project story yet.</p>
                    @endif
                </div>
            </section>

            <section>
                <h2 class="font-mono text-xs uppercase tracking-widest text-zinc-500">Roadmap</h2>
                <div class="mt-3 space-y-4">
                    @forelse($project->milestones as $milestone)
                        <div class="rounded-xl border border-zinc-200 p-5">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="font-bold">{{ $milestone->title }}</h3>
                                <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-semibold text-zinc-700">{{ $milestone->status->label() }}</span>
                            </div>
                            @if($milestone->description)
                                <p class="mt-2 text-sm text-zinc-600">{{ $milestone->description }}</p>
                            @endif
                        </div>
                    @empty
                        <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-600">No milestones yet. When the maintainers run an AI analysis and approve the roadmap, milestones appear here.</p>
                    @endforelse
                </div>
            </section>

            <section>
                <h2 class="font-mono text-xs uppercase tracking-widest text-zinc-500">Help wanted</h2>
                <div class="mt-3 space-y-4">
                    @forelse($project->tasks as $task)
                        <div class="rounded-xl border border-zinc-200 p-5">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="font-bold">{{ $task->title }}</h3>
                                <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-semibold text-zinc-700">{{ $task->status->label() }}</span>
                            </div>
                            @if($task->description)
                                <p class="mt-2 text-sm text-zinc-600">{{ $task->description }}</p>
                            @endif
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                @foreach($task->skillTags() as $skill)
                                    <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 font-mono text-[11px] text-zinc-700">{{ $skill }}</span>
                                @endforeach
                                @if($task->requires_hardware)
                                    <span class="rounded-full bg-amber-100 px-2.5 py-0.5 font-mono text-[11px] font-semibold text-amber-800">needs hardware</span>
                                @endif
                            </div>
                            @if($task->definition_of_done)
                                <p class="mt-3 border-t border-zinc-100 pt-3 text-sm text-zinc-600"><span class="font-semibold text-zinc-900">Done means:</span> {{ $task->definition_of_done }}</p>
                            @endif
                            @if($task->status->value === 'OPEN')
                                <p class="mt-3"><a href="{{ route('projects.offers.create', [$project, 'task' => $task->id]) }}" class="text-sm font-semibold text-emerald-700 hover:text-emerald-600">Offer help →</a></p>
                            @endif
                        </div>
                    @empty
                        <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-600">No contributor tasks yet.</p>
                    @endforelse
                </div>
            </section>
        </div>

        <aside class="space-y-6">
            <section class="rounded-xl border border-zinc-200 p-5">
                <h2 class="font-mono text-xs uppercase tracking-widest text-zinc-500">Device</h2>
                @forelse($project->devices as $device)
                    <p class="mt-2 font-bold">{{ $device->displayName() }}</p>
                    @if($device->category)
                        <p class="text-sm text-zinc-600">{{ $device->category }}</p>
                    @endif
                    @if($device->description)
                        <p class="mt-2 text-sm text-zinc-600">{{ $device->description }}</p>
                    @endif
                @empty
                    <p class="mt-2 text-sm text-zinc-600">No device linked yet.</p>
                @endforelse
            </section>

            <section class="rounded-xl border {{ $publishedReport ? 'border-emerald-200 bg-emerald-50/50' : 'border-amber-300 bg-amber-50' }} p-5">
                <h2 class="font-mono text-xs uppercase tracking-widest {{ $publishedReport ? 'text-emerald-800' : 'text-amber-800' }}">AI revival assessment</h2>
                @if($publishedReport)
                    <p class="mt-2 text-sm text-zinc-700">Version {{ $publishedReport->version_number }}, approved {{ $publishedReport->reviewed_at?->toDateString() ?? '—' }}{{ $publishedReport->run?->model ? ' · drafted with '.$publishedReport->run->model : '' }}.</p>
                    <p class="mt-2 text-sm"><a href="{{ route('projects.reports.show', [$project, $publishedReport]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Read the full assessment →</a></p>
                @else
                    <p class="mt-2 text-sm text-amber-900">Not yet available. The maintainers run the AI analysis from the evidence page; approved assessments appear here.</p>
                @endif
            </section>

            <section class="rounded-xl border border-zinc-200 p-5">
                <h2 class="font-mono text-xs uppercase tracking-widest text-zinc-500">Evidence sources</h2>
                @php($repository = $project->repositories()->latest()->first())
                @if($repository?->isReady())
                    <p class="mt-2 text-sm text-zinc-600">{{ $repository->currentEvidence()->count() }} imported files from <a href="{{ $repository->canonical_url }}" target="_blank" rel="noopener" class="font-mono text-xs hover:text-emerald-700">{{ $repository->owner }}/{{ $repository->name }}</a>, pinned to commit <span class="font-mono text-xs">{{ substr($repository->last_commit_sha, 0, 12) }}</span>.</p>
                @elseif($repository)
                    <p class="mt-2 text-sm text-zinc-600">Repository import {{ $repository->import_status }}.</p>
                @else
                    <p class="mt-2 text-sm text-zinc-600">No evidence imported yet.</p>
                @endif
                @can('update', $project)
                    <p class="mt-3"><a href="{{ route('projects.evidence', $project) }}" class="text-sm font-semibold text-emerald-700 hover:text-emerald-600">Manage evidence →</a></p>
                @endcan
            </section>
        </aside>
    </div>
</x-app-layout>
