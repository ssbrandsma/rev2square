<x-app-layout>
    <x-slot name="title">Online now</x-slot>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Community</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">Online now</h1>
        <p class="mt-1 text-sm text-zinc-600">Members who opted in to presence and are around right now.</p>
    </x-slot>
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        @guest
            <p class="mb-4 rounded-xl border border-zinc-200 bg-white p-5 text-sm text-zinc-600"><a href="{{ route('login') }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Sign in</a> to see who is around.</p>
        @endguest
        <div class="rounded-xl border border-zinc-200 bg-white p-6 hidden" data-presence-roster data-channel="presence-{{ $channel }}" data-channels-url="{{ $channelsUrl }}">
            <div data-presence-list>
                <p class="text-sm text-zinc-500" data-presence-state></p>
            </div>
        </div>
    </div>
</x-app-layout>
