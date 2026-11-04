<x-app-layout>
    <x-slot name="title">Deleted page — Documentation — {{ $project->title }}</x-slot>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">
            <a href="{{ route('projects.show', $project) }}" class="hover:underline">{{ $project->title }}</a> · <a href="{{ route('projects.docs.index', $project) }}" class="hover:underline">Documentation</a>
        </p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">{{ $page->title }}</h1>
    </x-slot>

    @include('projects._nav', ['project' => $project, 'active' => 'docs'])

    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        <div class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center">
            <p class="text-sm font-semibold text-zinc-900">This page was deleted.</p>
            <p class="mt-1 text-sm text-zinc-600">Its revisions are kept, so existing citations show it as unavailable instead of breaking silently. Shared photos are untouched.</p>
            <form method="POST" action="{{ route('projects.docs.restore', [$project, $page]) }}" class="mt-4">
                @csrf
                <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Restore page</button>
            </form>
        </div>
    </div>
</x-app-layout>
