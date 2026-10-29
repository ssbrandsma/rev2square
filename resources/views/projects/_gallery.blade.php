@props(['project'])
@php($gallery = $project->gallery)
@if($gallery->isNotEmpty())
    <section aria-label="Project photos">
        <h2 class="text-sm font-semibold text-zinc-500">Photos ({{ $gallery->count() }})</h2>
        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3" data-gallery>
            @foreach($gallery as $item)
                <button type="button" data-gallery-open="{{ $item->id }}"
                    data-full="{{ route('projects.media.show', [$project, $item, 'variant' => 'card']) }}"
                    data-alt="{{ $item->altText() }}"
                    data-caption="{{ $item->caption ?? '' }}"
                    class="group overflow-hidden rounded-xl border border-zinc-200 bg-zinc-50 focus-visible:outline-2 focus-visible:outline-emerald-600"
                    aria-label="View photo: {{ $item->altText() }}">
                    @if($item->isReady())
                        <img src="{{ route('projects.media.show', [$project, $item, 'variant' => 'thumb']) }}"
                            alt="{{ $item->altText() }}" loading="lazy"
                            class="aspect-square w-full object-cover transition group-hover:opacity-95"
                            onerror="this.remove()" />
                    @else
                        <span class="flex aspect-square w-full items-center justify-center text-xs text-zinc-500">Processing…</span>
                    @endif
                </button>
            @endforeach
        </div>
        <div data-lightbox hidden class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 p-4" role="dialog" aria-modal="true" aria-label="Photo viewer">
            <button type="button" data-lightbox-close class="absolute right-4 top-4 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-zinc-900" aria-label="Close (Escape)">Close ✕</button>
            <button type="button" data-lightbox-prev class="absolute left-2 top-1/2 -translate-y-1/2 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-zinc-900 sm:left-4" aria-label="Previous photo">←</button>
            <figure class="max-h-full max-w-3xl">
                <img data-lightbox-image src="" alt="" class="max-h-[75vh] w-auto rounded-xl object-contain" />
                <figcaption data-lightbox-caption class="mt-2 text-center text-sm text-zinc-300"></figcaption>
            </figure>
            <button type="button" data-lightbox-next class="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-zinc-900 sm:right-4" aria-label="Next photo">→</button>
        </div>
    </section>
@endif
