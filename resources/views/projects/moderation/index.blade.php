<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">
            <a href="{{ route('projects.show', $project) }}" class="hover:underline">{{ $project->title }}</a> · Moderation
        </p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">Member reports</h1>
    </x-slot>
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        @if(session('status'))
            <p class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</p>
        @endif

        <div class="space-y-4">
            @forelse($reports as $report)
                <article class="rounded-xl border border-zinc-200 bg-white p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm"><span class="font-bold">{{ $report->reporter->name }}</span>
                            <span class="text-zinc-500">reported {{ $report->reportable_type === \App\Models\DiscussionPost::class ? 'a discussion post' : 'a chat message' }} · {{ $report->created_at->diffForHumans() }}</span></p>
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold
                            @if($report->status === \App\Enums\ReportStatus::Pending) bg-amber-100 text-amber-800
                            @elseif($report->status === \App\Enums\ReportStatus::Actioned) bg-emerald-100 text-emerald-800
                            @else bg-zinc-100 text-zinc-700 @endif">{{ $report->status->label() }}</span>
                    </div>
                    <p class="mt-2 text-sm text-zinc-700"><span class="font-semibold">Reason:</span> {{ $report->reason }}</p>
                    @if($report->reportable)
                        <blockquote class="mt-2 rounded-lg bg-zinc-50 p-3 text-sm text-zinc-700">
                            <span class="text-xs text-zinc-500">{{ $report->reportable->author->name }} wrote:</span>
                            <span class="block whitespace-pre-line">{{ \Illuminate\Support\Str::limit($report->reportable->body, 500) }}</span>
                        </blockquote>
                    @else
                        <p class="mt-2 text-sm text-zinc-500">The reported content is gone.</p>
                    @endif
                    @if($report->status === \App\Enums\ReportStatus::Pending)
                        <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-zinc-100 pt-3">
                            <form method="POST" action="{{ route('projects.moderation.review', [$project, $report]) }}" class="inline">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="decision" value="dismiss">
                                <button type="submit" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50">Dismiss</button>
                            </form>
                            @if($report->reportable)
                                <form method="POST" action="{{ route('projects.moderation.review', [$project, $report]) }}" class="flex flex-wrap items-center gap-2">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="decision" value="remove">
                                    <label for="mod-reason-{{ $report->id }}" class="sr-only">Removal reason</label>
                                    <input id="mod-reason-{{ $report->id }}" name="reason" type="text" maxlength="500" placeholder="Removal reason (logged)"
                                        class="rounded-lg border-zinc-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                    <button type="submit" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500">Remove content</button>
                                </form>
                            @endif
                        </div>
                    @elseif($report->reviewedBy)
                        <p class="mt-2 text-xs text-zinc-500">Reviewed by {{ $report->reviewedBy->name }} · {{ $report->reviewed_at->diffForHumans() }}</p>
                    @endif
                </article>
            @empty
                <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center text-sm text-zinc-600">No reports. Members can report discussion posts and chat messages; they land here.</p>
            @endforelse
        </div>

        <div class="mt-6">{{ $reports->links() }}</div>
    </div>
</x-app-layout>
