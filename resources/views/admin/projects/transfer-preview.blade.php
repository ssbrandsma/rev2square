<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Administration · Project #{{ $project->id }}</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">Confirm transfer</h1>
        <p class="mt-1 text-sm text-zinc-600"><a href="{{ route('admin.projects.transfer', $project) }}" class="font-semibold text-emerald-700">← Change details</a></p>
    </x-slot>
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        @include('admin._nav')

        <section class="rounded-xl border border-amber-200 bg-amber-50 p-4 sm:p-6">
            <h2 class="font-semibold text-amber-900">{{ $project->title }} <span class="font-mono text-xs font-normal">/{{ $project->slug }}</span></h2>
            <dl class="mt-3 space-y-2 text-sm text-amber-900">
                <div><dt class="font-semibold">From</dt><dd>{{ $project->owner->name }} <span class="text-xs">({{ $project->owner->email }} · #{{ $project->owner->id }} · {{ $project->owner->global_role->label() }} · {{ $project->owner->account_status->label() }})</span></dd></div>
                <div><dt class="font-semibold">To</dt><dd>{{ $recipient->name }} <span class="text-xs">({{ $recipient->email }} · #{{ $recipient->id }} · {{ $recipient->global_role->label() }} · {{ $recipient->account_status->label() }})</span></dd></div>
            </dl>
        </section>

        <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
            <h2 class="font-semibold">Exact permission changes</h2>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-zinc-700">
                <li>Project owner: {{ $project->owner->name }} → {{ $recipient->name }}.</li>
                @if($keepPreviousOwner)
                    <li>{{ $project->owner->name }} stays on as an active project maintainer.</li>
                @else
                    <li>{{ $project->owner->name }}'s project access is removed entirely.</li>
                @endif
                @if($recipientMembership)
                    <li>{{ $recipient->name }}'s existing maintainer row is retired; access continues as owner.</li>
                @endif
                <li>All other maintainers, contributors, tasks, and content stay exactly as they are.</li>
                <li>{{ $recipient->name }} is <strong>not</strong> promoted globally; {{ $project->owner->name }} keeps their global role.</li>
            </ul>
            <p class="mt-3 text-sm text-zinc-600"><span class="font-semibold">Reason:</span> {{ $reason }}</p>
            <p class="mt-1 text-xs text-zinc-500">Ownership and recipient eligibility are re-verified at confirmation; stale previews are rejected. Both owners are notified in-app. Requires recent password confirmation.</p>

            <form method="POST" action="{{ route('admin.projects.transfer.confirm', $project) }}" class="mt-4" onsubmit="return confirm('Transfer ownership of this project now?');">
                @csrf
                <input type="hidden" name="recipient_id" value="{{ $recipient->id }}" />
                <input type="hidden" name="keep_previous_owner" value="{{ $keepPreviousOwner ? 'keep' : 'remove' }}" />
                <input type="hidden" name="reason" value="{{ $reason }}" />
                <input type="hidden" name="expected_owner_id" value="{{ $expectedOwnerId }}" />
                <div class="flex flex-wrap gap-2">
                    <button type="submit" data-disable-on-submit class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Confirm transfer</button>
                    <a href="{{ route('admin.projects.transfer', $project) }}" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50">Back</a>
                </div>
            </form>
        </section>
    </div>
</x-app-layout>
