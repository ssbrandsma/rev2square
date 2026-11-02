<x-app-layout>
    <x-slot name="title">Source browser — run #{{ $analysis->id }} — {{ $project->title }}</x-slot>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Analysis run #{{ $analysis->id }} · [A{{ $artifact->id }}] · Source browser</p>
        <h1 class="mt-1 break-words text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">{{ $artifact->original_name }}</h1>
        @include('projects._back-link', ['url' => route('projects.artifacts.analyses.show', [$project, $artifact, $analysis]), 'label' => 'Back to run'])
    </x-slot>
    @include('projects._nav', ['project' => $project, 'active' => 'agent'])
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        @if(session('status'))
            <p class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</p>
        @endif
        @if(session('error'))
            <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ session('error') }}</p>
        @endif

        <p class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">Reconstructed code; not the original source. Names, types and control flow may be incomplete or incorrect.</p>
        @if($listingSource === 'evidence')
            <p class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">This run predates retained output archives: the listing below is the run's bounded evidence manifest, and only files with inline evidence content can be viewed. Regenerate the run from its page for full browsable sources.</p>
        @endif

        <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
            <form method="GET" action="{{ route('projects.artifacts.analyses.source', [$project, $artifact, $analysis]) }}" class="flex flex-wrap items-end gap-3">
                <label class="block text-sm">Kind
                    <select name="kind" class="mt-1 block rounded-lg border border-zinc-300 px-2 py-1.5 text-sm">
                        <option value="all" @selected($kind === 'all')>All files</option>
                        <option value="source" @selected($kind === 'source')>Sources (.java, .kt, .cs)</option>
                        <option value="resource" @selected($kind === 'resource')>Resources</option>
                    </select>
                </label>
                <label class="block grow text-sm">Filename / class search
                    <input name="q" value="{{ $query }}" maxlength="100" placeholder="e.g. MainActivity or AndroidManifest" class="mt-1 block w-full rounded-lg border border-zinc-300 px-2 py-1.5 font-mono text-sm" />
                </label>
                <div>
                    <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Filter</button>
                </div>
            </form>
            <p class="mt-3 text-xs text-zinc-500">{{ number_format($total) }} file{{ $total === 1 ? '' : 's' }} · page {{ $page }} of {{ $lastPage }} · sources sort before resources</p>
            <ul class="mt-2 max-h-96 space-y-1 overflow-y-auto font-mono text-xs">
                @forelse($entries as $entry)
                    <li>
                        <a href="{{ route('projects.artifacts.analyses.source', [$project, $artifact, $analysis, 'kind' => $kind, 'q' => $query !== '' ? $query : null, 'path' => $entry['name']]) }}" class="font-semibold text-emerald-700 hover:underline">{{ $entry['name'] }}</a>
                        <span class="text-zinc-500">· {{ number_format($entry['size']) }} bytes · {{ $entry['kind'] }}</span>
                    </li>
                @empty
                    <li class="font-sans text-sm text-zinc-500">No files match this filter.</li>
                @endforelse
            </ul>
            @if($lastPage > 1)
                <div class="mt-3 flex items-center gap-2 text-sm">
                    @if($page > 1)
                        <a href="{{ route('projects.artifacts.analyses.source', [$project, $artifact, $analysis, 'kind' => $kind, 'q' => $query !== '' ? $query : null, 'page' => $page - 1]) }}" class="rounded-lg border border-zinc-300 px-3 py-1.5 font-semibold text-zinc-700 hover:bg-zinc-50">← Previous</a>
                    @endif
                    @if($page < $lastPage)
                        <a href="{{ route('projects.artifacts.analyses.source', [$project, $artifact, $analysis, 'kind' => $kind, 'q' => $query !== '' ? $query : null, 'page' => $page + 1]) }}" class="rounded-lg border border-zinc-300 px-3 py-1.5 font-semibold text-zinc-700 hover:bg-zinc-50">Next →</a>
                    @endif
                </div>
            @endif
        </section>

        @if($viewed !== null)
            <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                <h2 class="break-all font-mono text-sm font-semibold">{{ $viewed['name'] }}</h2>
                <p class="mt-1 text-xs text-zinc-500">{{ number_format($viewed['size']) }} bytes @if($viewed['lineCount'] !== null)· {{ number_format($viewed['lineCount']) }} lines @endif @if($viewed['lineTruncated'])· <span class="font-semibold text-amber-700">display truncated at line 5,000 — download the archive for the rest</span>@endif</p>
                @if($viewed['notice'] !== null)
                    <p class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ $viewed['notice'] }}</p>
                @else
                    <div class="mt-2 max-h-[40rem] overflow-auto rounded-lg bg-zinc-950 p-3">
                        <table class="w-full border-collapse font-mono text-xs text-zinc-200">
                            <tbody>
                                @foreach($viewed['lines'] as $i => $line)
                                    <tr id="L{{ $i + 1 }}" class="scroll-mt-24 border-t border-zinc-800 first:border-t-0">
                                        <td class="w-12 select-none py-0 pr-3 text-right text-zinc-500"><a href="#L{{ $i + 1 }}" class="hover:text-zinc-300">{{ $i + 1 }}</a></td>
                                        <td class="whitespace-pre py-0">{{ $line === '' ? ' ' : $line }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif
    </div>
</x-app-layout>
