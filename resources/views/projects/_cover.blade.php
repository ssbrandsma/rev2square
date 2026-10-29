@props(['project'])
@php($cover = $project->cover)
@if($cover !== null && $cover->isReady())
    <figure class="mt-6 overflow-hidden rounded-xl border border-zinc-800 sm:float-right sm:ml-6 sm:mt-0 sm:w-72">
        <img src="{{ route('projects.media.show', [$project, $cover, 'variant' => 'card']) }}"
            srcset="{{ route('projects.media.show', [$project, $cover, 'variant' => 'thumb']) }} 400w, {{ route('projects.media.show', [$project, $cover, 'variant' => 'card']) }} 800w"
            sizes="300px"
            alt="{{ $cover->altText() }}"
            loading="lazy"
            style="object-position: {{ $cover->focalPosition() }};"
            class="aspect-[4/3] w-full object-cover"
            onerror="this.closest('figure').remove()" />
        @if($cover->caption || $cover->credit)
            <figcaption class="bg-zinc-900 px-3 py-2 text-xs text-zinc-400">
                @if($cover->caption){{ $cover->caption }}@endif
                @if($cover->credit)<span class="text-zinc-500"> · Credit: {{ $cover->credit }}@if($cover->license) ({{ $cover->license }})@endif</span>@endif
            </figcaption>
        @endif
    </figure>
@endif
