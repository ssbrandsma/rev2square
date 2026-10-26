@props(['project'])
<article class="flex flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white transition-colors hover:border-zinc-300">
    <div class="bg-zinc-950 px-5 py-4">
        <p class="truncate font-mono text-xs uppercase tracking-widest text-zinc-400">{{ $project->devices->first()?->displayName() ?? 'Device to be linked' }}</p>
        <div class="mt-2 flex flex-wrap gap-2">
            <span class="rounded-full bg-emerald-900/60 px-2.5 py-0.5 text-xs font-semibold text-emerald-300">{{ $project->phase->label() }}</span>
            <span class="rounded-full bg-zinc-800 px-2.5 py-0.5 text-xs font-semibold text-zinc-200">{{ $project->activity->label() }}</span>
        </div>
    </div>
    <div class="flex flex-1 flex-col p-5">
        <h3 class="text-lg font-extrabold tracking-tight">
            <a href="{{ route('projects.show', $project) }}" class="hover:text-emerald-700">{{ $project->title }}</a>
        </h3>
        @if($project->pitch)
            <p class="mt-2 text-sm text-zinc-600">{{ $project->pitch }}</p>
        @endif
        @php($openTasks = $project->tasks->where('status', \App\Enums\TaskStatus::Open))
        <div class="mt-4 flex flex-wrap gap-1.5">
            @foreach($openTasks->flatMap->skillTags()->unique()->take(5) as $skill)
                <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 font-mono text-[11px] text-zinc-700">{{ $skill }}</span>
            @endforeach
        </div>
        <div class="mt-4 flex items-center justify-between border-t border-zinc-100 pt-4 text-sm">
            <span class="text-zinc-600">{{ $openTasks->count() }} open {{ Str::plural('task', $openTasks->count()) }}</span>
            <a href="{{ route('projects.show', $project) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">View project →</a>
        </div>
    </div>
</article>
