<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Administration</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">Audit log</h1>
    </x-slot>
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        @include('admin._nav')
        <form method="GET" action="{{ route('admin.audit.index') }}" class="mb-4 flex flex-col gap-2 rounded-xl border border-zinc-200 bg-white p-4 text-sm sm:flex-row sm:items-end">
            <label class="flex flex-col gap-1">Action prefix
                <input name="action" value="{{ $filters['action'] ?? '' }}" placeholder="e.g. account." class="rounded-lg border border-zinc-300 px-3 py-2" />
            </label>
            <label class="flex flex-col gap-1">Actor email
                <input name="actor" value="{{ $filters['actor'] ?? '' }}" class="rounded-lg border border-zinc-300 px-3 py-2" />
            </label>
            <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-500">Filter</button>
            <a href="{{ route('admin.audit.index') }}" class="rounded-lg border border-zinc-300 px-4 py-2 font-semibold text-zinc-700 hover:bg-zinc-50">Clear</a>
        </form>

        <section class="overflow-x-auto rounded-xl border border-zinc-200 bg-white">
            <table class="w-full min-w-[800px] text-left text-sm">
                <thead class="border-b border-zinc-200 text-xs text-zinc-500">
                    <tr>
                        <th class="px-4 py-3">At (UTC)</th>
                        <th class="px-4 py-3">Actor</th>
                        <th class="px-4 py-3">Action</th>
                        <th class="px-4 py-3">Target</th>
                        <th class="px-4 py-3">Reason</th>
                        <th class="px-4 py-3">Change</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse($events as $event)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-xs">{{ $event->created_at->format('Y-m-d H:i') }}<br /><span class="text-zinc-400">{{ $event->correlation_id }}</span></td>
                            <td class="px-4 py-3 text-xs">{{ $event->actor?->email ?? 'console' }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $event->action }} <span class="text-zinc-500">({{ $event->outcome }})</span></td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $event->target_type ? class_basename($event->target_type).' #'.$event->target_id : '—' }}</td>
                            <td class="max-w-64 px-4 py-3 text-xs">{{ $event->reason ?? '—' }}</td>
                            <td class="max-w-80 px-4 py-3 font-mono text-xs">
                                @if($event->before || $event->after)
                                    <details><summary class="cursor-pointer text-emerald-700">before → after</summary>
                                        <pre class="mt-1 overflow-x-auto whitespace-pre-wrap">{{ json_encode(['before' => $event->before, 'after' => $event->after], JSON_PRETTY_PRINT) }}</pre>
                                    </details>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-zinc-500">No audit records match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
        <div class="mt-4">{{ $events->links() }}</div>
        <p class="mt-2 text-xs text-zinc-500">Append-only: records cannot be edited or deleted through this interface. Secret values are never stored here — only the fact that a secret changed.</p>
    </div>
</x-app-layout>
