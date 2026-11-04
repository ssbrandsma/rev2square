<x-app-layout>
    <x-slot name="title">Documentation — {{ $project->title }}</x-slot>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">
            <a href="{{ route('projects.show', $project) }}" class="hover:underline">{{ $project->title }}</a> · Documentation
        </p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">Documentation</h1>
    </x-slot>

    @include('projects._nav', ['project' => $project, 'active' => 'docs'])

    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        @if(session('status'))
            <p class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</p>
        @endif

        <form method="GET" action="{{ route('projects.docs.index', $project) }}" class="mb-6 flex flex-col gap-2 sm:flex-row">
            <input type="search" name="q" value="{{ $search }}" maxlength="100" placeholder="Search guides…"
                class="block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500" />
            <select name="category" class="block w-full rounded-lg border-zinc-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:w-56">
                <option value="">All categories</option>
                @foreach(\App\Enums\DocCategory::cases() as $case)
                    <option value="{{ $case->value }}" @selected($category === $case->value)>{{ $case->label() }}</option>
                @endforeach
            </select>
            <button type="submit" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50">Search</button>
        </form>

        @can('update', $project)
            <p class="mb-6"><a href="{{ route('projects.docs.create', $project) }}" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">New documentation page</a></p>
        @endcan

        @if($pages->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center">
                <p class="text-sm font-semibold text-zinc-900">No documentation yet{{ $search !== '' || $category ? ' matching this filter' : '' }}.</p>
                <p class="mt-1 text-sm text-zinc-600">Teardown guides, board photos, pinouts, measurements, and repair notes live here.</p>
                @can('update', $project)
                    <p class="mt-4"><a href="{{ route('projects.docs.create', $project) }}" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Write the first guide</a></p>
                @endcan
            </div>
        @else
            <div class="space-y-8">
                @foreach($pages->groupBy(fn ($page) => $page->category->value) as $group)
                    <section aria-label="{{ $group->first()->category->label() }}">
                        <h2 class="text-sm font-semibold text-zinc-500">{{ $group->first()->category->label() }}</h2>
                        <ul class="mt-2 divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white">
                            @foreach($group as $page)
                                <li class="flex items-start justify-between gap-3 p-4">
                                    <div class="min-w-0">
                                        <p>
                                            <a href="{{ route('projects.docs.show', [$project, $page]) }}" class="font-semibold text-zinc-900 hover:text-emerald-700">{{ $page->title }}</a>
                                            @unless($page->isPublished())
                                                <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-900">Draft</span>
                                            @endunless
                                        </p>
                                        @if($page->summary)
                                            <p class="mt-1 text-sm text-zinc-600">{{ $page->summary }}</p>
                                        @endif
                                        <p class="mt-1 text-xs text-zinc-500">Updated <time datetime="{{ $page->updated_at->toIso8601String() }}">{{ $page->updated_at->diffForHumans() }}</time> · Revision {{ $page->current_rev }}</p>
                                    </div>
                                    @can('update', $project)
                                        <div class="flex shrink-0 gap-1">
                                            <form method="POST" action="{{ route('projects.docs.move', [$project, $page]) }}">
                                                @csrf
                                                <input type="hidden" name="direction" value="up" />
                                                <button type="submit" class="rounded-lg border border-zinc-300 px-2 py-1 text-xs font-semibold text-zinc-700 hover:bg-zinc-50" title="Move up" aria-label="Move {{ $page->title }} up">↑</button>
                                            </form>
                                            <form method="POST" action="{{ route('projects.docs.move', [$project, $page]) }}">
                                                @csrf
                                                <input type="hidden" name="direction" value="down" />
                                                <button type="submit" class="rounded-lg border border-zinc-300 px-2 py-1 text-xs font-semibold text-zinc-700 hover:bg-zinc-50" title="Move down" aria-label="Move {{ $page->title }} down">↓</button>
                                            </form>
                                        </div>
                                    @endcan
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>

            <div class="mt-6">{{ $pages->links() }}</div>
        @endif
    </div>
</x-app-layout>
