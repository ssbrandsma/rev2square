<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">
            <a href="{{ route('projects.show', $project) }}" class="hover:underline">{{ $project->title }}</a> · {{ $offer->type->label() }}
        </p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">Application from <a href="{{ $offer->user->profileUrl() }}" class="hover:text-emerald-700 hover:underline">{{ $offer->user->name }}</a></h1>
        <div class="mt-2">
            <span class="rounded-full px-3 py-1 text-xs font-semibold
                @if($offer->status->value === 'accepted') bg-emerald-100 text-emerald-800
                @elseif($offer->status->value === 'pending') bg-amber-100 text-amber-800
                @else bg-zinc-100 text-zinc-700 @endif">{{ $offer->status->label() }}</span>
        </div>
    </x-slot>
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        @if(session('status'))
            <p class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</p>
        @endif
        @if($errors->has('offer'))
            <p class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">{{ $errors->first('offer') }}</p>
        @endif

        <div class="rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
            <h2 class="text-sm font-semibold text-zinc-500">Request</h2>
            <p class="mt-1 whitespace-pre-line text-[15px] text-zinc-800">{{ $offer->message }}</p>
            @if($offer->task)
                <p class="mt-3 text-sm text-zinc-600">About task:
                    <a href="{{ route('projects.tasks.show', [$project, $offer->task]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">{{ $offer->task->title }}</a>
                    ({{ $offer->task->status->label() }})
                </p>
            @endif
            <p class="mt-3 rounded-xl bg-zinc-50 px-4 py-3 text-sm text-zinc-700"><span class="font-semibold">Next step:</span> {{ $offer->nextStep() }}</p>

            @if($offer->status->value !== 'pending')
                <div class="mt-4 border-t border-zinc-100 pt-4 text-sm">
                    <h2 class="font-semibold text-zinc-500">Decision</h2>
                    <p class="mt-1 text-zinc-700">
                        {{ ucfirst($offer->status->value) }}
                        @if($offer->decidedBy) by {{ $offer->decidedBy->name }}@endif
                        @if($offer->decided_at) · {{ $offer->decided_at->diffForHumans() }}@endif
                    </p>
                    @if($offer->decision_reason)
                        <p class="mt-1 text-zinc-700"><span class="font-semibold">Maintainer note:</span> {{ $offer->decision_reason }}</p>
                    @endif
                </div>
            @endif

            @if($isMaintainer && $offer->status->value === 'pending')
                <form method="POST" action="{{ route('projects.offers.accept', [$project, $offer]) }}" class="mt-4 border-t border-zinc-100 pt-4">
                    @csrf
                    <label for="decision_reason" class="block text-sm font-semibold">Decision note <span class="font-normal text-zinc-500">(optional, visible to the contributor)</span></label>
                    <input id="decision_reason" name="decision_reason" type="text" maxlength="2000" placeholder="Why this decision? What could help next time?"
                        class="mt-1 block w-full rounded-lg border-zinc-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <div class="mt-3 flex gap-2">
                        <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Accept</button>
                        <button type="submit" formaction="{{ route('projects.offers.decline', [$project, $offer]) }}" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:border-zinc-400">Decline</button>
                    </div>
                </form>
            @endif

            <div class="mt-4 flex flex-wrap gap-3 border-t border-zinc-100 pt-4 text-sm">
                @if(! $isMaintainer && $offer->status->value === 'pending')
                    <form method="POST" action="{{ route('projects.offers.withdraw', [$project, $offer]) }}" class="inline">
                        @csrf
                        <button type="submit" class="font-semibold text-zinc-500 hover:text-zinc-800">Withdraw this request</button>
                    </form>
                @endif
                @if(! $isMaintainer && $offer->status->value === 'accepted' && (int) $offer->user_id === auth()->id())
                    <form method="POST" action="{{ route('projects.offers.join', [$project, $offer]) }}" class="inline">
                        @csrf
                        <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Join project as contributor</button>
                    </form>
                @endif
                @if(in_array($offer->status->value, ['declined', 'withdrawn'], true))
                    <form method="POST" action="{{ route('projects.offers.reopen', [$project, $offer]) }}" class="inline">
                        @csrf
                        <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">
                            {{ $isMaintainer ? 'Reconsider (back to pending)' : 'Ask for reconsideration' }}
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <div class="mt-6 rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
            <h2 class="text-sm font-semibold text-zinc-500">Private discussion <span class="font-normal">(only you and the maintainers see this)</span></h2>
            @if($offer->messages->isEmpty())
                <p class="mt-2 text-sm text-zinc-500">No messages yet.</p>
            @else
                <ol class="mt-3 space-y-3">
                    @foreach($offer->messages as $message)
                        <li class="rounded-lg bg-zinc-50 p-3 text-sm">
                            <p class="text-xs text-zinc-500">
                                <a href="{{ $message->author->profileUrl() }}" class="font-semibold text-zinc-700 hover:text-emerald-700">{{ $message->author->name }}</a>
                                @if($project->managedBy($message->author)) · maintainer @endif
                                · {{ $message->created_at->diffForHumans() }}
                            </p>
                            <p class="mt-1 whitespace-pre-line text-zinc-800">{{ $message->body }}</p>
                        </li>
                    @endforeach
                </ol>
            @endif
            <form method="POST" action="{{ route('projects.offers.message', [$project, $offer]) }}" class="mt-4">
                @csrf
                <label for="message-body" class="sr-only">Message</label>
                <textarea id="message-body" name="body" rows="3" required maxlength="2000" placeholder="Write a follow-up…"
                    class="block w-full rounded-lg border-zinc-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500"></textarea>
                @error('body')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                <div class="mt-2 flex justify-end">
                    <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Send message</button>
                </div>
            </form>
        </div>

        <div class="mt-6 rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
            <h2 class="text-sm font-semibold text-zinc-500">History</h2>
            @if($history->isEmpty())
                <p class="mt-2 text-sm text-zinc-500">No recorded activity yet.</p>
            @else
                <ol class="mt-3 space-y-2">
                    @foreach($history as $event)
                        <li class="text-sm text-zinc-700">
                            <span class="font-semibold">{{ $event->user?->name ?? 'System' }}</span>
                            <span class="text-zinc-500">· {{ $event->created_at->diffForHumans() }} ·</span>
                            @switch($event->event)
                                @case('offer.submitted')
                                    submitted this request
                                    @break
                                @case('offer.accepted')
                                    accepted this request
                                    @break
                                @case('offer.declined')
                                    declined this request
                                    @break
                                @case('offer.withdrawn')
                                    withdrew this request
                                    @break
                                @case('offer.reopened')
                                    reopened this request (was {{ $event->metadata['from'] ?? 'closed' }})
                                    @break
                                @case('offer.message')
                                    sent a message
                                    @break
                                @default
                                    {{ $event->event }}
                            @endswitch
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>

        <p class="mt-6 flex flex-wrap gap-4">
            <a href="{{ route('projects.show', $project) }}" class="text-sm font-semibold text-emerald-700 hover:text-emerald-600">← Back to project</a>
            @if($isMaintainer)
                <a href="{{ route('projects.offers.index', $project) }}" class="text-sm font-semibold text-emerald-700 hover:text-emerald-600">All offers →</a>
            @else
                <a href="{{ route('dashboard') }}#applications" class="text-sm font-semibold text-emerald-700 hover:text-emerald-600">My applications →</a>
            @endif
        </p>
    </div>
</x-app-layout>
