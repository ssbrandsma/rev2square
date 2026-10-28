<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Contribute</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">Find a task</h1>
        <p class="mt-1 text-sm text-zinc-600">Open work across published revival projects. Open a task to see its full story, then offer help.</p>
    </x-slot>
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        <livewire:projects.task-directory />
    </div>
</x-app-layout>
