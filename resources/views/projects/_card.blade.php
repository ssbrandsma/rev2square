@props(['project'])
<article class="flex flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white transition-colors hover:border-zinc-300">
    <div class="relative h-40 overflow-hidden bg-zinc-950">
        @php($cover = $project->relationLoaded('cover') ? $project->cover : $project->cover()->first())
        @if($cover !== null && $cover->isReady())
            <img src="{{ route('projects.media.show', [$project, $cover, 'variant' => 'card']) }}"
                srcset="{{ route('projects.media.show', [$project, $cover, 'variant' => 'thumb']) }} 400w, {{ route('projects.media.show', [$project, $cover, 'variant' => 'card']) }} 800w"
                sizes="(max-width: 640px) 100vw, 400px"
                alt="{{ $cover->altText() }}"
                loading="lazy"
                style="object-position: {{ $cover->focalPosition() }};"
                class="absolute inset-0 h-full w-full object-cover"
                onerror="this.remove()" />
            <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-zinc-950/80 via-zinc-950/10 to-transparent" aria-hidden="true"></div>
        @else
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('{{ asset('images/card-pattern.svg') }}');" aria-hidden="true"></div>
        @endif
        <div class="absolute inset-x-0 bottom-0 px-5 pb-3">
            <p class="truncate text-sm font-semibold text-zinc-300">{{ $project->devices->first()?->displayName() ?? 'Device to be linked' }}</p>
            <div class="mt-1.5 flex flex-wrap gap-2">
                <span class="rounded-full bg-emerald-900/60 px-2.5 py-0.5 text-xs font-semibold text-emerald-300">{{ $project->phase->label() }}</span>
                <span class="rounded-full bg-zinc-800 px-2.5 py-0.5 text-xs font-semibold text-zinc-200">{{ $project->activity->label() }}</span>
            </div>
        </div>
    </div>
    <div class="flex flex-1 flex-col p-5">
        <h3 class="text-lg font-semibold tracking-tight">
            <a href="{{ route('projects.show', $project) }}" class="hover:text-emerald-700">{{ $project->title }}</a>
        </h3>
        @if($project->pitch)
            <p class="mt-2 line-clamp-2 text-sm text-zinc-600">{{ $project->pitch }}</p>
        @endif
        @php($openTasks = $project->tasks->where('status', \App\Enums\TaskStatus::Open))
        <div class="mt-4 flex flex-wrap gap-1.5">
            @foreach($openTasks->flatMap->skillTags()->unique()->take(5) as $skill)
                <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-[11px] font-semibold text-zinc-700">{{ $skill }}</span>
            @endforeach
        </div>
        <div class="mt-4 flex items-center justify-between border-t border-zinc-100 pt-4 text-sm">
            <span class="text-zinc-600">{{ $openTasks->count() }} open {{ Str::plural('task', $openTasks->count()) }}</span>
            <a href="{{ route('projects.show', $project) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">View project →</a>
        </div>
    </div>
</article>
