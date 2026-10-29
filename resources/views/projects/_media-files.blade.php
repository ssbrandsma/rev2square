@props(['project'])
@php($files = $project->media()->where('kind', \App\Models\ProjectMedia::KIND_FILE)->orderBy('category')->orderBy('id')->get())
@if($files->isNotEmpty())
    <section aria-label="Supporting files">
        <h2 class="text-sm font-semibold text-zinc-500">Files ({{ $files->count() }})</h2>
        <ul class="mt-3 space-y-2">
            @foreach($files as $file)
                <li class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border border-zinc-200 bg-white px-4 py-3 text-sm">
                    <a href="{{ route('projects.media.download', [$project, $file]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">{{ $file->displayTitle() }}</a>
                    <span class="text-xs text-zinc-500">{{ $file->category }} · {{ number_format($file->size / 1024, 1) }} KB · {{ $file->original_name }}</span>
                    @if($file->caption)
                        <span class="w-full text-xs text-zinc-600">{{ $file->caption }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
