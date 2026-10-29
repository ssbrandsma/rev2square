<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Account</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">My AI usage</h1>
    </x-slot>
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        <section class="rounded-xl border border-zinc-200 bg-white p-4 text-sm sm:p-6">
            <h2 class="font-semibold">Today (UTC, resets 00:00)</h2>
            @if($unlimited)
                <p class="mt-1">Explicit unlimited exemption: requests are metered but not budget-limited.</p>
            @else
                <p class="mt-1 font-mono text-xs">Requests: {{ $today['requests'] }} / {{ $requestLimit }} · Tokens: {{ number_format($today['tokens']) }} / {{ number_format($tokenLimit) }}</p>
            @endif
            <p class="mt-1"><span class="text-zinc-500">Estimated total cost:</span> <span class="font-mono font-semibold">{{ $cost }}</span>@if($costPartial) <span class="text-zinc-500">(some rows lack pricing)</span>@endif</p>
        </section>

        <section class="mt-6 overflow-x-auto rounded-xl border border-zinc-200 bg-white">
            <table class="w-full min-w-[720px] text-left text-sm">
                <thead class="border-b border-zinc-200 text-xs text-zinc-500">
                    <tr>
                        <th class="px-4 py-3">At (UTC)</th>
                        <th class="px-4 py-3">Feature / operation</th>
                        <th class="px-4 py-3">Model</th>
                        <th class="px-4 py-3">Tokens</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse($entries as $entry)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-xs">{{ $entry->created_at->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $entry->feature }}<br /><span class="text-zinc-500">{{ $entry->operation }}</span></td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $entry->provider }}/{{ $entry->model ?? '?' }}</td>
                            <td class="px-4 py-3 font-mono text-xs">@if($entry->tokenTotal() !== null){{ number_format($entry->tokenTotal()) }}@else<span class="text-zinc-500">unknown</span>@endif</td>
                            <td class="px-4 py-3 text-xs">{{ $entry->status }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-zinc-500">No recorded AI usage yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
        <div class="mt-4">{{ $entries->links() }}</div>
    </div>
</x-app-layout>
