<x-app-layout>
    <x-slot name="title">Not allowed — Rev2Square</x-slot>
    <div class="mx-auto max-w-2xl px-4 py-20 text-center sm:px-6">
        <p class="font-mono text-sm uppercase tracking-widest text-emerald-700">403</p>
        <h1 class="mt-2 text-3xl font-extrabold tracking-tight">Maintainers only.</h1>
        <p class="mt-3 text-zinc-600">This area belongs to the project's maintainers. If this is your project, make sure you are signed in with the right account.</p>
        <p class="mt-6 flex flex-wrap justify-center gap-3">
            <a href="{{ route('projects.index') }}" class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">Explore projects</a>
            <a href="{{ route('home') }}" class="rounded-lg bg-zinc-100 px-5 py-2.5 text-sm font-semibold text-zinc-800 hover:bg-zinc-200">Back home</a>
        </p>
    </div>
</x-app-layout>
