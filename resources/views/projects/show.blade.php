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
                @endcan
            </div>
        </div>
    </section>

    <div class="mx-auto grid max-w-6xl gap-10 px-4 py-12 sm:px-6 lg:grid-cols-3">
        <div class="space-y-10 lg:col-span-2">
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
                        <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-600">No milestones yet. The AI investigator will propose a roadmap from WP3 on; maintainers can already add context via the project story.</p>
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

            <section class="rounded-xl border border-amber-300 bg-amber-50 p-5">
                <h2 class="font-mono text-xs uppercase tracking-widest text-amber-800">AI revival assessment</h2>
                <p class="mt-2 text-sm text-amber-900">Not yet available. Source-backed feasibility analysis arrives with the AI Revival Investigator.</p>
            </section>

            <section class="rounded-xl border border-zinc-200 p-5">
                <h2 class="font-mono text-xs uppercase tracking-widest text-zinc-500">Evidence sources</h2>
                <p class="mt-2 text-sm text-zinc-600">Repository import arrives next. Linked sources will appear here with provenance.</p>
            </section>
        </aside>
    </div>
</x-app-layout>
