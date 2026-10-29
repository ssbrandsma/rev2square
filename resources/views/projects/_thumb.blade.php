@props(['project', 'size' => 'w-16 h-16'])
@php($cover = $project->relationLoaded('cover') ? $project->cover : $project->cover()->first())
@if($cover !== null && $cover->isReady())
    <img src="{{ route('projects.media.show', [$project, $cover, 'variant' => 'thumb']) }}"
        alt="" loading="lazy" aria-hidden="true"
        style="object-position: {{ $cover->focalPosition() }};"
        class="{{ $size }} shrink-0 rounded-lg border border-zinc-200 object-cover"
        onerror="this.remove()" />
@else
    <span class="{{ $size }} flex shrink-0 items-center justify-center rounded-lg border border-zinc-200 bg-zinc-100 text-xs font-bold text-zinc-400" aria-hidden="true">{{ mb_substr($project->title, 0, 1) }}</span>
@endif
