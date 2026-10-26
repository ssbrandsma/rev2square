<x-app-layout>
    <x-slot name="header">
        <p class="font-mono text-xs uppercase tracking-widest text-emerald-700">Edit project</p>
        <h1 class="mt-1 text-2xl font-extrabold tracking-tight">{{ $project->title }}</h1>
    </x-slot>
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        <form method="POST" action="{{ route('projects.update', $project) }}" class="rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
            @csrf
            @method('PUT')
            @include('projects._form', ['project' => $project])
            <div class="mt-6 flex items-center justify-end gap-3 border-t border-zinc-100 pt-6">
                <a href="{{ route('projects.show', $project) }}" class="text-sm font-semibold text-zinc-600 hover:text-zinc-900">Cancel</a>
                <button type="submit" class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">Save changes</button>
            </div>
        </form>
        <form method="POST" action="{{ route('projects.destroy', $project) }}" class="mt-4" onsubmit="return confirm('Delete this project and its milestones and tasks?');">
            @csrf
            @method('DELETE')
            <button type="submit" class="text-sm font-semibold text-red-600 hover:text-red-500">Delete project</button>
        </form>
    </div>
</x-app-layout>
