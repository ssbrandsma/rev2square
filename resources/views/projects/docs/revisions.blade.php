<x-app-layout>
    <x-slot name="title">History — {{ $page->title }} — {{ $project->title }}</x-slot>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">
            <a href="{{ route('projects.show', $project) }}" class="hover:underline">{{ $project->title }}</a> · <a href="{{ route('projects.docs.index', $project) }}" class="hover:underline">Documentation</a> · <a href="{{ route('projects.docs.show', [$project, $page]) }}" class="hover:underline">{{ $page->title }}</a>
        </p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">Revision history</h1>
    </x-slot>

    @include('projects._nav', ['project' => $project, 'active' => 'docs'])

    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        @if(session('status'))
            <p class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</p>
        @endif

        <ul class="space-y-3">
            @foreach($revisions as $revision)
                <li class="flex items-start justify-between gap-3 rounded-xl border border-zinc-200 bg-white p-4">
                    <div class="min-w-0">
                        <p class="text-sm">
                            <a href="{{ route('projects.docs.show', [$project, $page, 'rev' => $revision->rev]) }}" class="font-semibold text-zinc-900 hover:text-emerald-700">Revision {{ $revision->rev }}</a>
                            @if($revision->rev === $page->current_rev)
                                <span class="ml-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-900">Current</span>
                            @endif
                        </p>
                        <p class="mt-1 truncate text-sm text-zinc-600">{{ $revision->title }}</p>
                        @if($revision->note)
                            <p class="mt-1 text-sm text-zinc-600">{{ $revision->note }}</p>
                        @endif
                        <p class="mt-1 text-xs text-zinc-500">{{ $revision->author?->name ?? 'System' }} · {{ $revision->created_at?->diffForHumans() }}</p>
                    </div>
                    @if($canEdit && $revision->rev !== $page->current_rev)
                        <form method="POST" action="{{ route('projects.docs.revisions.restore', [$project, $page, $revision->rev]) }}" class="shrink-0" onsubmit="return confirm('Restore revision {{ $revision->rev }} as a new revision?');">
                            @csrf
                            <button type="submit" class="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50">Restore as new</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
</x-app-layout>
