<div>
    <div class="grid gap-3 sm:grid-cols-4">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search projects or devices…"
            class="rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:col-span-2">
        <select wire:model.live="phase" class="rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
            <option value="">All phases</option>
            @foreach($phases as $phase)
                <option value="{{ $phase->value }}">{{ $phase->label() }}</option>
            @endforeach
        </select>
        <select wire:model.live="activity" class="rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
            <option value="">All activity states</option>
            @foreach($activities as $activity)
                <option value="{{ $activity->value }}">{{ $activity->label() }}</option>
            @endforeach
        </select>
    </div>

    <div class="mt-6 grid gap-6 md:grid-cols-2" wire:loading.class="opacity-50">
        @forelse($projects as $project)
            @include('projects._card', ['project' => $project])
        @empty
            <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center text-sm text-zinc-600 md:col-span-2">No projects match this search.</p>
        @endforelse
    </div>

    <div class="mt-6">{{ $projects->links() }}</div>
</div>
