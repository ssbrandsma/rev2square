<x-app-layout>
    <x-slot name="title">Audit log</x-slot>
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
                        <th class="px-4 py-3">Event</th>
                        <th class="px-4 py-3">Raw values</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse($events as $event)
                        @php($readableAction = Str::ucfirst(Str::lower(Str::headline(str_replace('.', ' ', $event->action)))))
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 text-xs">
                                <span class="font-mono">{{ $event->created_at->format('Y-m-d H:i') }}</span><br />
                                <span class="text-zinc-500">{{ $event->created_at->diffForHumans() }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <p class="text-sm font-semibold text-zinc-900">{{ $readableAction }}</p>
                                <p class="mt-0.5 text-zinc-600">{{ $event->actor?->email ?? 'console' }} · @if($event->targetAdminUrl())<a href="{{ $event->targetAdminUrl() }}" class="font-semibold text-emerald-700">{{ class_basename($event->target_type).' #'.$event->target_id }}</a>@else{{ $event->target_type ? class_basename($event->target_type).' #'.$event->target_id : 'No target' }}@endif</p>
                                <p class="mt-0.5 text-zinc-600">{{ $event->reason ?? 'No reason recorded.' }}</p>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">
                                <details><summary class="cursor-pointer font-sans font-semibold text-emerald-700">Raw values</summary>
                                    <p class="mt-1">{{ $event->action }} <span class="text-zinc-500">({{ $event->outcome }})</span></p>
                                    <p class="text-zinc-500">{{ $event->correlation_id }}</p>
                                    @if($event->before || $event->after)
                                        <p class="mt-1 font-sans text-zinc-500">before → after</p>
                                        <pre class="mt-1 overflow-x-auto whitespace-pre-wrap">{{ json_encode(['before' => $event->before, 'after' => $event->after], JSON_PRETTY_PRINT) }}</pre>
                                    @endif
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-6 text-center text-zinc-500">No audit records match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
        <div class="mt-4">{{ $events->links() }}</div>
        <p class="mt-2 text-xs text-zinc-500">Append-only: records cannot be edited or deleted through this interface. Secret values are never stored here — only the fact that a secret changed.</p>
    </div>
</x-app-layout>
