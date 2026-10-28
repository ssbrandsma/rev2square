<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">
            <a href="{{ route('projects.show', $project) }}" class="hover:underline">{{ $project->title }}</a> · Test report
        </p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">{{ $report->title }}</h1>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <span class="rounded-full px-3 py-1 text-xs font-semibold
                @if($report->outcome === \App\Enums\TestOutcome::Worked) bg-emerald-100 text-emerald-900
                @elseif($report->outcome === \App\Enums\TestOutcome::Partially) bg-amber-100 text-amber-900
                @else bg-red-100 text-red-900 @endif">{{ $report->outcome->label() }}</span>
            @if($report->isConfirmed())
                <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-900">Confirmed by {{ $report->confirmedBy->name }}</span>
            @else
                <span class="rounded-full bg-zinc-100 px-3 py-1 text-xs font-semibold text-zinc-700">Awaiting confirmation</span>
            @endif
        </div>
    </x-slot>
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        @if(session('status'))
            <p class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</p>
        @endif

        <div class="rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
            <p class="text-sm text-zinc-500">
                <a href="{{ $report->author->profileUrl() }}" class="font-semibold hover:text-emerald-700">{{ $report->author->name }}</a> · {{ $report->created_at->diffForHumans() }}
                @if($report->task) · task: {{ $report->task->title }}@endif
                @if($report->variantLabel()) · {{ $report->variantLabel() }}@endif
            </p>
            <p class="mt-4 whitespace-pre-line text-[15px] leading-relaxed text-zinc-800">{{ $report->body }}</p>

            @if($report->environment)
                <h2 class="mt-6 text-sm font-semibold text-zinc-500">Environment</h2>
                <p class="mt-1 whitespace-pre-line text-sm text-zinc-800">{{ $report->environment }}</p>
            @endif

            @include('projects._attachments', ['project' => $project, 'attachments' => $report->attachments, 'uploadRoute' => route('projects.test-reports.attachments.store', [$project, $report]), 'parentId' => 'rep-'.$report->id])

            <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-zinc-100 pt-6 text-sm">
                @if($canEdit)
                    <a href="{{ route('projects.test-reports.edit', [$project, $report]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Edit report</a>
                @endif
                @auth
                    @if(! $report->isConfirmed() && $report->user_id !== auth()->id())
                        <form method="POST" action="{{ route('projects.test-reports.confirm', [$project, $report]) }}" class="flex w-full flex-wrap items-center gap-2">
                            @csrf
                            <label for="confirm-note" class="sr-only">Confirmation note</label>
                            <input id="confirm-note" name="note" type="text" maxlength="2000" placeholder="Optional note (your setup, what matched)…"
                                class="min-w-0 flex-1 rounded-lg border-zinc-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                            <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">I reproduced this — confirm</button>
                        </form>
                    @endif
                @endauth
            </div>
        </div>

        <p class="mt-6"><a href="{{ route('projects.show', $project) }}#test-reports" class="text-sm font-semibold text-emerald-700 hover:text-emerald-600">← Back to project</a></p>
    </div>
</x-app-layout>
