<x-app-layout>
    <x-slot name="header">
        <p class="font-mono text-xs uppercase tracking-widest text-emerald-700">New project</p>
        <h1 class="mt-1 text-2xl font-extrabold tracking-tight">Start a revival</h1>
    </x-slot>
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        <form method="POST" action="{{ route('projects.store') }}" class="rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
            @csrf
            @include('projects._form')
            <div class="mt-6 flex items-center justify-end gap-3 border-t border-zinc-100 pt-6">
                <a href="{{ route('dashboard') }}" class="text-sm font-semibold text-zinc-600 hover:text-zinc-900">Cancel</a>
                <button type="submit" class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">Create project</button>
            </div>
        </form>
    </div>
</x-app-layout>
