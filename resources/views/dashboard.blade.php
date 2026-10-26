<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="font-mono text-xs uppercase tracking-widest text-emerald-700">Dashboard</p>
                <h1 class="mt-1 text-2xl font-extrabold tracking-tight">My revival projects</h1>
            </div>
            <a href="{{ route('projects.create') }}" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Start a revival</a>
        </div>
    </x-slot>
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        @if(session('status'))
            <p class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</p>
        @endif
        @if($projects->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-10 text-center">
                <p class="font-semibold">No projects yet</p>
                <p class="mt-1 text-sm text-zinc-600">Start the first revival for a device you care about.</p>
                <a href="{{ route('projects.create') }}" class="mt-4 inline-block rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">Start a revival</a>
            </div>
        @else
            <div class="overflow-hidden rounded-xl border border-zinc-200">
                <table class="min-w-full divide-y divide-zinc-200 text-sm">
                    <thead class="bg-zinc-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-700">Project</th>
                            <th class="hidden px-4 py-3 text-left font-semibold text-zinc-700 sm:table-cell">Phase</th>
                            <th class="hidden px-4 py-3 text-left font-semibold text-zinc-700 md:table-cell">Tasks</th>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-700">Visibility</th>
                            <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 bg-white">
                        @foreach($projects as $project)
                            <tr>
                                <td class="px-4 py-3">
                                    <a href="{{ route('projects.show', $project) }}" class="font-bold hover:text-emerald-700">{{ $project->title }}</a>
                                    <p class="font-mono text-xs text-zinc-500">/{{ $project->slug }}</p>
                                </td>
                                <td class="hidden px-4 py-3 text-zinc-600 sm:table-cell">{{ $project->phase->label() }}</td>
                                <td class="hidden px-4 py-3 text-zinc-600 md:table-cell">{{ $project->tasks_count }}</td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $project->isPublished() ? 'bg-emerald-100 text-emerald-800' : 'bg-zinc-100 text-zinc-700' }}">{{ $project->publication_status->label() }}</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('projects.edit', $project) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Edit</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-app-layout>
