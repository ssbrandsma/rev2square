<div>
    <div class="grid gap-3 sm:grid-cols-5">
        <label for="explorer-search" class="sr-only">Search projects</label>
        <input id="explorer-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search projects or devices…"
            class="rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:col-span-2">
        <label for="explorer-phase" class="sr-only">Phase</label>
        <select id="explorer-phase" wire:model.live="phase" class="rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
            <option value="">All phases</option>
            @foreach($phases as $phase)
                <option value="{{ $phase->value }}">{{ $phase->label() }}</option>
            @endforeach
        </select>
        <label for="explorer-activity" class="sr-only">Activity</label>
        <select id="explorer-activity" wire:model.live="activity" class="rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
            <option value="">All activity states</option>
            @foreach($activities as $activity)
                <option value="{{ $activity->value }}">{{ $activity->label() }}</option>
            @endforeach
        </select>
        <label for="explorer-sort" class="sr-only">Sort</label>
        <select id="explorer-sort" wire:model.live="sort" class="rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
            <option value="newest">Newest first</option>
            <option value="oldest">Oldest first</option>
            <option value="title">Title A–Z</option>
        </select>
    </div>

    <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm text-zinc-500" role="status">{{ $projects->total() }} {{ Str::plural('project', $projects->total()) }} found.</p>
        @if($this->hasActiveFilters)
            <button type="button" wire:click="clearFilters" class="text-sm font-semibold text-emerald-700 hover:text-emerald-600">Clear all filters</button>
        @endif
    </div>

    <div class="mt-6 grid gap-6 md:grid-cols-2" wire:loading.class="opacity-50" aria-live="polite">
        @forelse($projects as $project)
            @include('projects._card', ['project' => $project])
        @empty
            <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center text-sm text-zinc-600 md:col-span-2">No projects match this search.</p>
        @endforelse
    </div>

    <div class="mt-6">{{ $projects->links() }}</div>
</div>
