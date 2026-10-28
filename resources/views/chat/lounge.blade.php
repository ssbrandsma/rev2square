<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Community</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">Community lounge</h1>
        <p class="mt-1 text-sm text-zinc-600">Say hello, ask around, find a project. Be kind; the code of conduct applies.</p>
    </x-slot>

    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        @if(session('status'))
            <p class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</p>
        @endif

        @include('chat._room', [
            'room' => $room,
            'project' => null,
            'messages' => $messages,
            'historyUrl' => route('lounge.history'),
            'sendUrl' => route('lounge.send'),
            'readUrl' => route('lounge.read'),
            'canPost' => auth()->check(),
        ])
    </div>
</x-app-layout>
