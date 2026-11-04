<x-app-layout>
    <x-slot name="title">{{ $displayTitle }} — Documentation — {{ $project->title }}</x-slot>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">
            <a href="{{ route('projects.show', $project) }}" class="hover:underline">{{ $project->title }}</a> · <a href="{{ route('projects.docs.index', $project) }}" class="hover:underline">Documentation</a>
        </p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">{{ $displayTitle }}</h1>
    </x-slot>

    @include('projects._nav', ['project' => $project, 'active' => 'docs'])

    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        @if(session('status'))
            <p class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</p>
        @endif
        @if(session('error'))
            <p class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">{{ session('error') }}</p>
        @endif

        @unless($page->isPublished())
            <p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">Draft — only project managers can see this page.</p>
        @endunless

        @if($revision !== null)
            <p class="mb-4 rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-700">
                Viewing revision {{ $revision->rev }}@if($stale) — <a href="{{ route('projects.docs.show', [$project, $page]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">a newer revision ({{ $page->current_rev }}) is available</a>@endif.
                <a href="{{ route('projects.docs.revisions', [$project, $page]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">History →</a>
            </p>
        @endif

        <p class="text-sm text-zinc-500">
            {{ $displayCategory->label() }}
            · {{ $revision !== null ? 'Revision from' : 'Updated' }} <time datetime="{{ $displayDate->toIso8601String() }}">{{ $displayDate->diffForHumans() }}</time>
            · Revision {{ $revision?->rev ?? $page->current_rev }}
        </p>
        @if($displaySummary)
            <p class="mt-2 text-zinc-700">{{ $displaySummary }}</p>
        @endif

        @php
            $sections = array_values(array_filter($anchors, fn ($a) => $a['kind'] === 'section'));
            // Each finding form owns its error bag and reopens only
            // for its own failed submission; old input never leaks
            // across forms. Keep a single block per template: Blade
            // pairs the first opener with the first closer.
            $findingEditOpen = (int) session('editFinding');
            $findingStatusOpen = (int) session('statusFinding');
            $findingCreateOpen = session('findingForm') === 'create';
            $findingOldRefs = function () {
                $refs = old('refs');
                if (! is_array($refs)) {
                    return null;
                }

                return array_values(array_filter($refs, fn ($ref) => is_array($ref)));
            };
        @endphp
        @if(count($sections) > 1)
            <details class="mt-4 rounded-xl border border-zinc-200 bg-white p-4 text-sm">
                <summary class="cursor-pointer font-semibold text-zinc-900">Contents</summary>
                <ul class="mt-2 space-y-1">
                    @foreach($sections as $section)
                        <li><a href="#{{ $section['id'] }}" class="text-emerald-700 hover:text-emerald-600">{{ $section['label'] }}</a></li>
                    @endforeach
                </ul>
            </details>
        @endif

        <div class="mt-6">
            <div data-gallery class="story-body max-w-[70ch] space-y-4 leading-relaxed">
                {!! $html !!}
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
        </div>

        <section aria-label="Findings" id="findings" class="mt-10 border-t border-zinc-200 pt-6">
            <h2 class="text-lg font-semibold">Findings ({{ count($findings) }})</h2>
            @if($revision !== null)
                <p class="mt-1 text-sm text-zinc-500">Findings as they were at revision {{ $revision->rev }}; later changes, including deletions, do not appear here.</p>
            @endif
            <div class="mt-4 space-y-4">
                @forelse($findings as $finding)
                    <article id="finding-{{ $finding['id'] }}" class="rounded-xl border border-zinc-200 bg-white p-4">
                        <p>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-bold {{ $finding['status'] === \App\Enums\DocFindingStatus::Verified ? 'bg-emerald-100 text-emerald-900' : ($finding['status'] === \App\Enums\DocFindingStatus::Hypothesis ? 'bg-amber-100 text-amber-900' : 'bg-zinc-100 text-zinc-700') }}">{{ $finding['status']->label() }}</span>
                        </p>
                        <p class="mt-2 text-zinc-800">{{ $finding['statement'] }}</p>
                        @if($finding['measurement_notes'])
                            <p class="mt-1 text-sm text-zinc-600">{{ $finding['measurement_notes'] }}</p>
                        @endif
                        @if(! empty($finding['refs']))
                            <ul class="mt-2 space-y-1 text-sm">
                                @foreach($finding['refs'] as $ref)
                                    <li class="text-zinc-600">{!! \App\Services\Docs\DocFindingRefs::render($ref, $project, auth()->user(), $page, $revision?->body ?? (is_array($page->body) ? $page->body : null), $revision?->rev) !!}</li>
                                @endforeach
                            </ul>
                        @endif
                        <p class="mt-2 text-xs text-zinc-500">
                            {{ $finding['by'] ?? 'System' }}@if($finding['at']) · {{ $finding['at']->diffForHumans() }}@endif
                            @if($finding['reason']) · {{ $finding['reason'] }}@endif
                        </p>
                        @if($canEdit && $revision === null)
                            @php($editBag = $errors->getBag('finding_'.$finding['id'].'_edit'))
                            @php($editActive = $findingEditOpen === $finding['id'] || $editBag->any())
                            @php($statusBag = $errors->getBag('finding_'.$finding['id'].'_status'))
                            @php($statusActive = $findingStatusOpen === $finding['id'] || $statusBag->any())
                            <details class="mt-2 text-sm"{{ $editActive ? ' open' : '' }}>
                                <summary class="cursor-pointer font-semibold text-zinc-700">Edit finding</summary>
                                <form method="POST" action="{{ route('projects.docs.findings.update', [$project, $page, $finding['id']]) }}" class="mt-2 space-y-2">
                                    @csrf
                                    @method('PUT')
                                    @foreach($editBag->all() as $message)
                                        <p class="text-sm text-red-600">{{ $message }}</p>
                                    @endforeach
                                    <label class="block text-xs font-semibold text-zinc-500">Statement
                                        <textarea name="statement" rows="2" required maxlength="2000" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm">{{ $editActive ? old('statement', $finding['statement']) : $finding['statement'] }}</textarea>
                                    </label>
                                    <label class="block text-xs font-semibold text-zinc-500">Measurement / verification notes
                                        <input type="text" name="measurement_notes" maxlength="2000" value="{{ $editActive ? old('measurement_notes', $finding['measurement_notes']) : $finding['measurement_notes'] }}" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm" />
                                    </label>
                                    @include('projects.docs._finding-refs', ['refs' => $editActive ? ($findingOldRefs() ?? $finding['refs'] ?? []) : ($finding['refs'] ?? []), 'targets' => $refTargets])
                                    <button type="submit" class="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50">Save finding</button>
                                </form>
                            </details>
                            <details class="mt-2 text-sm"{{ $statusActive ? ' open' : '' }}>
                                <summary class="cursor-pointer font-semibold text-zinc-700">Change status</summary>
                                <form method="POST" action="{{ route('projects.docs.findings.status', [$project, $page, $finding['id']]) }}" class="mt-2 space-y-2">
                                    @csrf
                                    @method('PATCH')
                                    @foreach($statusBag->all() as $message)
                                        <p class="text-sm text-red-600">{{ $message }}</p>
                                    @endforeach
                                    <div class="grid gap-2 sm:grid-cols-2">
                                        <label class="block text-xs font-semibold text-zinc-500">Status
                                            <select name="status" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm">
                                                @foreach(\App\Enums\DocFindingStatus::cases() as $case)
                                                    <option value="{{ $case->value }}" @selected(($statusActive ? old('status', $finding['status']->value) : $finding['status']->value) === $case->value)>{{ $case->label() }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block text-xs font-semibold text-zinc-500">Reason (required)
                                            <input type="text" name="reason" maxlength="500" required placeholder="Why is this status correct?" value="{{ $statusActive ? old('reason') : '' }}" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm" />
                                        </label>
                                    </div>
                                    <label class="block text-xs font-semibold text-zinc-500">Measurement / verification notes
                                        <input type="text" name="measurement_notes" maxlength="2000" value="{{ $statusActive ? old('measurement_notes', $finding['measurement_notes']) : $finding['measurement_notes'] }}" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm" />
                                    </label>
                                    <p class="text-xs text-zinc-500">Verified needs measurement notes or corroborating references.</p>
                                    <button type="submit" class="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50">Update status</button>
                                </form>
                                <form method="POST" action="{{ route('projects.docs.findings.destroy', [$project, $page, $finding['id']]) }}" class="mt-2 space-y-2" onsubmit="return confirm('Remove this finding? Pinned citations keep it.');">
                                    @csrf
                                    @method('DELETE')
                                    <label class="block text-xs font-semibold text-zinc-500">Reason (optional)
                                        <input type="text" name="reason" maxlength="500" placeholder="Why remove it?" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm" />
                                    </label>
                                    <button type="submit" class="text-xs font-semibold text-red-700 hover:text-red-600">Remove finding</button>
                                </form>
                            </details>
                        @endif
                    </article>
                @empty
                    <p class="text-sm text-zinc-500">No findings recorded yet.</p>
                @endforelse
            </div>

            @if($canEdit && $revision === null)
                @php($createBag = $errors->getBag('finding_create'))
                @php($createActive = $findingCreateOpen || $createBag->any())
                <details class="mt-4 rounded-xl border border-zinc-200 bg-white p-4"{{ $createActive ? ' open' : '' }}>
                    <summary class="cursor-pointer text-sm font-semibold">Add a finding</summary>
                    <form method="POST" action="{{ route('projects.docs.findings.store', [$project, $page]) }}" class="mt-3 space-y-3">
                        @csrf
                        @foreach($createBag->all() as $message)
                            <p class="text-sm text-red-600">{{ $message }}</p>
                        @endforeach
                        <label class="block text-sm font-semibold">Statement
                            <textarea name="statement" rows="2" required maxlength="2000" class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">{{ $createActive ? old('statement') : '' }}</textarea>
                        </label>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block text-xs font-semibold text-zinc-500">Status
                                <select name="status" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm">
                                    @foreach(\App\Enums\DocFindingStatus::cases() as $case)
                                        <option value="{{ $case->value }}" @selected($createActive && old('status') === $case->value)>{{ $case->label() }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block text-xs font-semibold text-zinc-500">Measurement / verification notes
                                <input type="text" name="measurement_notes" maxlength="2000" value="{{ $createActive ? old('measurement_notes') : '' }}" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm" />
                            </label>
                        </div>
                        <p class="text-xs text-zinc-500">Observed is directly visible or measured; Hypothesis needs confirmation; Verified needs documented verification. References to pages, artifacts, evidence, and photos can be attached via Edit after adding.</p>
                        <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Add finding</button>
                    </form>
                </details>
            @endif
        </section>

        <p class="mt-8 text-xs text-zinc-500">The Revival Agent reads page text, captions, and annotations. It cannot inspect image pixels.</p>

        @if($canEdit)
            <div class="mt-4 flex flex-wrap gap-2 border-t border-zinc-200 pt-4 text-sm">
                <a href="{{ route('projects.docs.edit', [$project, $page]) }}" class="rounded-lg border border-zinc-300 px-3 py-1.5 font-semibold text-zinc-700 hover:bg-zinc-50">Edit</a>
                <a href="{{ route('projects.docs.revisions', [$project, $page]) }}" class="rounded-lg border border-zinc-300 px-3 py-1.5 font-semibold text-zinc-700 hover:bg-zinc-50">History ({{ $page->current_rev }})</a>
                <a href="{{ route('projects.docs.export', [$project, $page, 'md']) }}" class="rounded-lg border border-zinc-300 px-3 py-1.5 font-semibold text-zinc-700 hover:bg-zinc-50">Export .md</a>
                <a href="{{ route('projects.docs.export', [$project, $page, 'json']) }}" class="rounded-lg border border-zinc-300 px-3 py-1.5 font-semibold text-zinc-700 hover:bg-zinc-50">Export .json</a>
                @if($page->isPublished())
                    <form method="POST" action="{{ route('projects.docs.unpublish', [$project, $page]) }}" class="inline">@csrf<button type="submit" class="rounded-lg border border-zinc-300 px-3 py-1.5 font-semibold text-zinc-700 hover:bg-zinc-50">Unpublish</button></form>
                @else
                    <form method="POST" action="{{ route('projects.docs.publish', [$project, $page]) }}" class="inline">@csrf<button type="submit" class="rounded-lg bg-emerald-600 px-3 py-1.5 font-semibold text-white hover:bg-emerald-500">Publish</button></form>
                @endif
                <form method="POST" action="{{ route('projects.docs.destroy', [$project, $page]) }}" class="inline" onsubmit="return confirm('Delete this page? Citations will show it as unavailable.');">@csrf @method('DELETE')<button type="submit" class="rounded-lg border border-red-300 px-3 py-1.5 font-semibold text-red-700 hover:bg-red-50">Delete</button></form>
            </div>
        @endif
    </div>
</x-app-layout>
