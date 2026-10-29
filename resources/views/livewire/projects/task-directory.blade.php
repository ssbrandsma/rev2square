<div>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <div>
            <label for="tasks-search" class="sr-only">Search tasks</label>
            <input id="tasks-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search tasks…"
                class="block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
        </div>
        <div>
            <label for="tasks-skill" class="sr-only">Skill</label>
            <input id="tasks-skill" type="text" wire:model.live.debounce.300ms="skill" placeholder="Skill (e.g. soldering)"
                class="block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
        </div>
        <div>
            <label for="tasks-project" class="sr-only">Project</label>
            <select id="tasks-project" wire:model.live="project" class="block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">All projects</option>
                @foreach($projects as $project)
                    <option value="{{ $project->slug }}">{{ $project->title }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="tasks-hardware" class="sr-only">Hardware requirement</label>
            <select id="tasks-hardware" wire:model.live="hardware" class="block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">Any hardware need</option>
                <option value="no">No hardware needed</option>
                <option value="yes">Needs hardware</option>
            </select>
        </div>
        <div>
            <label for="tasks-status" class="sr-only">Status</label>
            <select id="tasks-status" wire:model.live="status" class="block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">Any status</option>
                @foreach($statuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="tasks-sort" class="sr-only">Sort</label>
            <select id="tasks-sort" wire:model.live="sort" class="block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                <option value="newest">Newest first</option>
                <option value="oldest">Oldest first</option>
                <option value="title">Title A–Z</option>
            </select>
        </div>
    </div>

    <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm text-zinc-500" role="status">{{ $tasks->total() }} {{ Str::plural('task', $tasks->total()) }} found.</p>
        @if($this->hasActiveFilters)
            <button type="button" wire:click="clearFilters" class="text-sm font-semibold text-emerald-700 hover:text-emerald-600">Clear all filters</button>
        @endif
    </div>

    <div class="mt-6 space-y-4" wire:loading.class="opacity-50" aria-live="polite">
        @forelse($tasks as $task)
            <article class="rounded-xl border border-zinc-200 bg-white p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-semibold">
                        <a href="{{ route('projects.tasks.show', [$task->project, $task]) }}" class="hover:text-emerald-700">{{ $task->title }}</a>
                    </h2>
                    <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-semibold text-zinc-700">{{ $task->status->label() }}</span>
                </div>
                <p class="mt-1 text-xs text-zinc-500">
                    <a href="{{ route('projects.show', $task->project) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">{{ $task->project->title }}</a>
                    @if($task->milestone) · {{ $task->milestone->title }}@endif
                    · {{ $task->origin->label() }}
                </p>
                @if($task->description)
                    <p class="mt-2 text-sm text-zinc-600">{{ \Illuminate\Support\Str::limit($task->description, 200) }}</p>
                @endif
                <div class="mt-3 flex flex-wrap gap-1.5">
                    @foreach($task->skillTags() as $skill)
                        <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-[11px] font-semibold text-zinc-700">{{ $skill }}</span>
                    @endforeach
                    @if($task->requires_hardware)
                        <span class="rounded-full bg-amber-100 px-2.5 py-0.5 font-mono text-[11px] font-semibold text-amber-800">needs hardware</span>
                    @else
                        <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 font-mono text-[11px] font-semibold text-emerald-800">no hardware needed</span>
                    @endif
                </div>
            </article>
        @empty
            <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center text-sm text-zinc-600">No tasks match these filters. Try widening the search — or clear all filters to browse everything.</p>
        @endforelse
    </div>

    <div class="mt-6">{{ $tasks->links() }}</div>
</div>
