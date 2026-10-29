<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">
            <a href="{{ route('projects.show', $project) }}" class="hover:underline">{{ $project->title }}</a> · Chat
        </p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">{{ $roomLabel }}</h1>
        <p class="mt-1 text-sm text-zinc-600">Quick coordination. Record decisions in discussions or tasks.</p>
    </x-slot>

    @include('projects._nav', ['project' => $project, 'active' => 'chat'])

    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        @if(session('status'))
            <p class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</p>
        @endif
        @if($denial)
            <p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ $denial }}</p>
        @endif

        @include('chat._room', [
            'room' => $room,
            'project' => $project,
            'messages' => $messages,
            'historyUrl' => route('projects.chat.history', $project),
            'sendUrl' => route('projects.chat.send', $project),
            'readUrl' => route('projects.chat.read', $project),
            'canPost' => auth()->check() && $denial === null,
        ])
    </div>
</x-app-layout>
