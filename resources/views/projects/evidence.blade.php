<x-app-layout>
    <x-slot name="header">
        <p class="font-mono text-xs uppercase tracking-widest text-emerald-700">Evidence</p>
        <h1 class="mt-1 text-2xl font-extrabold tracking-tight">{{ $project->title }}</h1>
        <p class="mt-1 text-sm text-zinc-600"><a href="{{ route('projects.show', $project) }}" class="font-semibold text-emerald-700">← Back to project</a></p>
    </x-slot>
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        <livewire:projects.evidence-manager :project="$project" />
    </div>
</x-app-layout>
