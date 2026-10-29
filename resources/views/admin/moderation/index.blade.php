<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Moderation</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">Report queue</h1>
    </x-slot>
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        @include('admin._nav')
        @if(session('status'))
            <p class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</p>
        @endif
        @if(session('error'))
            <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ session('error') }}</p>
        @endif

        <p class="mb-4 max-w-[70ch] text-sm text-zinc-600">Global moderators review community reports here. Moderation grants no access to API keys, settings, private Agent conversations, artifacts, or private messages. Project reports link to their project queue, where moderators may also review.</p>

        <section class="space-y-4">
            @forelse($reports as $report)
                <article class="rounded-xl border border-zinc-200 bg-white p-4 text-sm sm:p-6">
                    <p>
                        <span class="font-semibold">Report #{{ $report->id }}</span>
                        <span class="ml-2 rounded px-1 font-mono text-xs {{ $report->status->value === 'pending' ? 'bg-amber-100 text-amber-900' : 'bg-zinc-100 text-zinc-500' }}">{{ $report->status->value }}</span>
                        <span class="ml-2 text-xs text-zinc-500">by {{ $report->reporter?->name ?? 'someone' }} · {{ $report->created_at->diffForHumans() }}</span>
                    </p>
                    <p class="mt-1"><span class="text-zinc-500">Reason:</span> {{ $report->reason }}</p>
                    @if($report->reportable)
                        <p class="mt-1 max-w-[70ch] text-zinc-600">{{ \Illuminate\Support\Str::limit((string) ($report->reportable->body ?? $report->reportable->content ?? $report->reportable->message ?? ''), 300) }}</p>
                    @endif
                    <p class="mt-2 text-xs text-zinc-500">
                        @if($report->project())
                            Project: <a href="{{ route('projects.moderation.index', $report->project()) }}" class="font-semibold text-emerald-700">{{ $report->project()->title }} queue →</a>
                        @else
                            Community lounge message (no project)
                        @endif
                    </p>
                    @if($report->status->value === 'pending' && $report->project() === null)
                        <form method="POST" action="{{ route('admin.moderation.review', $report) }}" class="mt-3 flex flex-col gap-2 sm:flex-row">
                            @csrf
                            <input name="reason" maxlength="500" placeholder="Decision reason (optional)" class="flex-1 rounded-lg border border-zinc-300 px-3 py-2" />
                            <button name="decision" value="dismiss" type="submit" class="rounded-lg border border-zinc-300 px-4 py-2 font-semibold text-zinc-700 hover:bg-zinc-50">Dismiss</button>
                            <button name="decision" value="remove" type="submit" class="rounded-lg bg-red-600 px-4 py-2 font-semibold text-white hover:bg-red-500" onclick="return confirm('Remove this content?');">Remove</button>
                        </form>
                    @endif
                </article>
            @empty
                <p class="rounded-xl border border-zinc-200 bg-white p-6 text-center text-sm text-zinc-500">No reports.</p>
            @endforelse
        </section>
        <div class="mt-4">{{ $reports->links() }}</div>
    </div>
</x-app-layout>
