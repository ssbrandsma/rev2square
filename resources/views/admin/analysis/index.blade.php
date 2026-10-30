<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Administration</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">Analysis backends</h1>
    </x-slot>
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        @include('admin._nav')

        <section class="mb-6 rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
            <h2 class="font-semibold">Execution readiness</h2>
            <p class="mt-1 text-xs text-zinc-500">Worker-verified snapshots from the isolated runners. A backend queues work only when ready; refresh with <code class="font-mono">php artisan binary:worker-capabilities --refresh</code> after deployments.</p>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 text-xs uppercase tracking-wide text-zinc-500">
                            <th class="py-2 pr-4">Backend</th>
                            <th class="py-2 pr-4">Ready</th>
                            <th class="py-2 pr-4">Version</th>
                            <th class="py-2 pr-4">Runner</th>
                            <th class="py-2">Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($backends as $backend)
                            <tr class="border-b border-zinc-100 last:border-0">
                                <td class="py-2 pr-4 font-medium">{{ $backend['name'] }}</td>
                                <td class="py-2 pr-4">
                                    @if($backend['verified'])
                                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">ready</span>
                                    @else
                                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-800">not ready</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 font-mono text-xs">{{ $backend['version'] ?? '—' }}</td>
                                <td class="py-2 pr-4 font-mono text-xs">{{ $backend['runner'] ? ($backend['runner']['arch'] ?? '?').' / '.($backend['runner']['digest'] ?? '?') : 'in-process' }}</td>
                                <td class="py-2 text-xs text-zinc-600">{{ $backend['note'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
            <section class="rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
                <h2 class="font-semibold">Queue</h2>
                <dl class="mt-2 grid grid-cols-3 gap-2 text-sm">
                    <div><dt class="text-zinc-500">Queue</dt><dd class="font-mono font-semibold">{{ $queue }}</dd></div>
                    <div><dt class="text-zinc-500">Waiting</dt><dd class="font-mono font-semibold">{{ $waiting ?? '—' }}</dd></div>
                    <div><dt class="text-zinc-500">Reserved</dt><dd class="font-mono font-semibold">{{ $reserved ?? '—' }}</dd></div>
                </dl>
                <h3 class="mt-4 font-semibold">Currently running</h3>
                @forelse($running as $run)
                    <p class="mt-1 text-sm font-mono">#{{ $run->id }} {{ $run->adapter }} / {{ $run->target }} <span class="text-zinc-500">{{ $run->started_at?->diffForHumans() }}</span></p>
                @empty
                    <p class="mt-1 text-sm text-zinc-500">No analysis currently running.</p>
                @endforelse
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
                <h2 class="font-semibold">Failures (24h)</h2>
                @forelse($failures as $failure)
                    <p class="mt-1 text-sm"><span class="font-mono">{{ $failure->error_code ?? 'unknown' }}</span> <span class="text-zinc-500">× {{ $failure->n }}</span></p>
                @empty
                    <p class="mt-1 text-sm text-zinc-500">No failed runs in the last 24h.</p>
                @endforelse
                <p class="mt-3 text-xs text-zinc-500">Resource-limit failures (timeouts, caps) appear here by category. Spool claims and stale-claim warnings are visible on the coordinator via <code class="font-mono">php artisan analysis:diagnostics</code>.</p>
            </section>
        </div>
    </div>
</x-app-layout>
