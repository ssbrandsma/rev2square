<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Administration · AI usage</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">{{ $user->name }}</h1>
        <p class="mt-1 text-sm text-zinc-600"><a href="{{ route('admin.usage.index') }}" class="font-semibold text-emerald-700">← All usage</a> · <a href="{{ route('admin.users.show', $user) }}" class="font-semibold text-emerald-700">Account →</a></p>
    </x-slot>
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        @include('admin._nav')
        <section class="mb-4 rounded-xl border border-zinc-200 bg-white p-4 text-sm sm:p-6">
            <p>{{ $user->email }} · {{ $user->global_role->label() }} · {{ $user->account_status->label() }} · AI {{ $user->ai_enabled ? 'enabled' : 'disabled' }}{{ $user->ai_unlimited ? ' · unlimited exemption' : '' }}</p>
            <p class="mt-1"><span class="text-zinc-500">Attempts:</span> <span class="font-mono font-semibold">{{ $totals['requests'] }}</span>
            · <span class="text-zinc-500">tokens:</span> <span class="font-mono font-semibold">{{ number_format($totals['tokens']) }}</span>
            · <span class="text-zinc-500">cost:</span> <span class="font-mono font-semibold">{{ $totals['cost'] }}</span> <span class="text-zinc-500">(estimate; unknown when pricing is missing)</span></p>
        </section>
        <section class="overflow-x-auto rounded-xl border border-zinc-200 bg-white">
            <table class="w-full min-w-[800px] text-left text-sm">
                <thead class="border-b border-zinc-200 text-xs text-zinc-500">
                    <tr>
                        <th class="px-4 py-3">At (UTC)</th>
                        <th class="px-4 py-3">Feature / operation</th>
                        <th class="px-4 py-3">Model</th>
                        <th class="px-4 py-3">Tokens in/out</th>
                        <th class="px-4 py-3">Duration</th>
                        <th class="px-4 py-3">Correlation</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse($entries as $entry)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-xs">{{ $entry->created_at->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $entry->feature }}<br /><span class="text-zinc-500">{{ $entry->operation }} #{{ $entry->attempt_seq }}</span></td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $entry->provider }}/{{ $entry->model ?? '?' }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $entry->input_tokens ?? '?' }} / {{ $entry->output_tokens ?? '?' }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $entry->duration_ms !== null ? $entry->duration_ms.' ms' : '—' }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $entry->correlation_id ?? '—' }}</td>
                            <td class="px-4 py-3 text-xs">{{ $entry->status }}@if($entry->error_code) <span class="font-mono text-zinc-500">{{ $entry->error_code }}</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-6 text-center text-zinc-500">No recorded AI usage for this account.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
        <div class="mt-4">{{ $entries->links() }}</div>
    </div>
</x-app-layout>
