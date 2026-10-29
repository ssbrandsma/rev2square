<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Administration</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">AI usage</h1>
    </x-slot>
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        @include('admin._nav')

        <section class="mb-4 rounded-xl border border-zinc-200 bg-white p-4 text-sm sm:p-6">
            <p><span class="text-zinc-500">Matching attempts:</span> <span class="font-mono font-semibold">{{ $totals['requests'] }}</span>
            · <span class="text-zinc-500">tokens:</span> <span class="font-mono font-semibold">{{ number_format($totals['tokens']) }}</span>
            · <span class="text-zinc-500">cost:</span> <span class="font-mono font-semibold">{{ $totals['cost'] }}</span>@if($totals['partial']) <span class="text-zinc-500">(some rows lack pricing)</span>@endif</p>
            <p class="mt-1"><a href="{{ route('admin.usage.export', request()->query()) }}" class="font-semibold text-emerald-700">Download metadata CSV →</a></p>
        </section>

        <form method="GET" action="{{ route('admin.usage.index') }}" class="mb-4 flex flex-col gap-2 rounded-xl border border-zinc-200 bg-white p-4 text-sm sm:flex-row sm:flex-wrap sm:items-end">
            <label class="flex flex-col gap-1">User
                <input name="user" value="{{ $filters['user'] ?? '' }}" placeholder="Name or email" class="rounded-lg border border-zinc-300 px-3 py-2" />
            </label>
            <label class="flex flex-col gap-1">Feature
                <select name="feature" class="rounded-lg border border-zinc-300 px-3 py-2">
                    <option value="">All</option>
                    @foreach(['revival_investigator', 'architecture_investigator', 'repo_agent', 'upload_agent', 'connection_test'] as $feature)
                        <option value="{{ $feature }}" @selected(($filters['feature'] ?? '') === $feature)>{{ $feature }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1">Status
                <select name="status" class="rounded-lg border border-zinc-300 px-3 py-2">
                    <option value="">All</option>
                    @foreach(['queued', 'succeeded', 'failed', 'cancelled', 'unknown'] as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $status }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1">Project
                <input name="project" value="{{ $filters['project'] ?? '' }}" placeholder="Title" class="rounded-lg border border-zinc-300 px-3 py-2" />
            </label>
            <label class="flex flex-col gap-1">From
                <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="rounded-lg border border-zinc-300 px-3 py-2" />
            </label>
            <label class="flex flex-col gap-1">To
                <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="rounded-lg border border-zinc-300 px-3 py-2" />
            </label>
            <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-500">Filter</button>
            <a href="{{ route('admin.usage.index') }}" class="rounded-lg border border-zinc-300 px-4 py-2 font-semibold text-zinc-700 hover:bg-zinc-50">Clear</a>
        </form>

        <section class="overflow-x-auto rounded-xl border border-zinc-200 bg-white">
            <table class="w-full min-w-[900px] text-left text-sm">
                <thead class="border-b border-zinc-200 text-xs text-zinc-500">
                    <tr>
                        <th class="px-4 py-3">At (UTC)</th>
                        <th class="px-4 py-3">User</th>
                        <th class="px-4 py-3">Feature / operation</th>
                        <th class="px-4 py-3">Model</th>
                        <th class="px-4 py-3">Tokens</th>
                        <th class="px-4 py-3">Cost</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse($entries as $entry)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-xs">{{ $entry->created_at->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-3 text-xs">@if($entry->user)<a href="{{ route('admin.usage.user', $entry->user) }}" class="font-semibold text-emerald-700">{{ $entry->user->email }}</a>@else<span class="text-zinc-500">unattributed</span>@endif</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $entry->feature }}<br /><span class="text-zinc-500">{{ $entry->operation }} #{{ $entry->attempt_seq }}</span></td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $entry->provider }}/{{ $entry->model ?? '?' }}</td>
                            <td class="px-4 py-3 font-mono text-xs">@if($entry->tokenTotal() !== null){{ number_format($entry->tokenTotal()) }}@else<span class="text-zinc-500">unknown</span>@endif</td>
                            <td class="px-4 py-3 font-mono text-xs">@if($entry->estimated_cost_micros !== null)${{ number_format($entry->estimated_cost_micros / 1000000, 4) }}@else<span class="text-zinc-500">unknown</span>@endif</td>
                            <td class="px-4 py-3 text-xs">{{ $entry->status }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-6 text-center text-zinc-500">No usage rows match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
        <div class="mt-4">{{ $entries->links() }}</div>
        <p class="mt-2 text-xs text-zinc-500">Budgets reset daily at 00:00 UTC. Turn-level token totals shown on conversations are display aggregates; this per-attempt ledger is the auditable source of truth.</p>
    </div>
</x-app-layout>
