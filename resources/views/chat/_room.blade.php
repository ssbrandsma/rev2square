<div data-chat-room
    data-channel="chat.{{ $room }}"
    data-presence-channel="presence-roster.{{ $room }}"
    data-history-url="{{ $historyUrl }}"
    data-send-url="{{ $sendUrl }}"
    data-read-url="{{ $readUrl }}"
    data-can-post="{{ ($canPost ?? false) ? '1' : '' }}"
    data-user-id="{{ auth()->id() ?? '' }}"
    data-user-name="{{ auth()->user()->name ?? '' }}"
    class="grid gap-6 lg:grid-cols-3">

    <div class="lg:col-span-2">
        <div data-chat-status class="mb-3 hidden rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status"></div>

        <div data-chat-scroll class="h-[28rem] space-y-4 overflow-y-auto rounded-xl border border-zinc-200 bg-white p-5">
            @if($messages->isEmpty())
                <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-600" data-chat-empty>No messages yet. Say hello — and keep it kind.</p>
            @endif
            @foreach($messages as $message)
                <div data-chat-message data-message-id="{{ $message->id }}" id="message-{{ $message->id }}" class="flex items-start gap-3">
                    <a href="{{ $message->author->profileUrl() }}" class="shrink-0" aria-label="{{ $message->author->name }}’s profile"><x-avatar :user="$message->author" size="sm" /></a>
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-x-2 text-sm">
                            <a href="{{ $message->author->profileUrl() }}" class="font-bold hover:text-emerald-700">{{ $message->author->name }}</a>
                            @if($project !== null && $project->managedBy($message->author))
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-900">Maintainer</span>
                            @endif
                            <time datetime="{{ $message->created_at->toIso8601String() }}" title="{{ $message->created_at->format('Y-m-d H:i:s T') }}" class="text-xs text-zinc-500">{{ $message->created_at->diffForHumans() }}</time>
                        </p>
                        <div class="mt-0.5 text-[15px] leading-relaxed text-zinc-800 discussion-body">
                            {!! \App\Services\Discussion\Markup::render($message->body) !!}
                        </div>
                        @auth
                            <div class="mt-1 flex flex-wrap gap-3 text-xs">
                                @if($message->user_id === auth()->id())
                                    <form method="POST" action="{{ $project ? route('projects.chat.destroy', [$project, $message]) : route('lounge.destroy', $message) }}" class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="font-semibold text-zinc-400 hover:text-zinc-700">Retract</button>
                                    </form>
                                @elseif($project === null || ! $project->managedBy(auth()->user()))
                                    <details class="inline">
                                        <summary class="cursor-pointer font-semibold text-zinc-400 hover:text-zinc-700">Report</summary>
                                        <form method="POST" action="{{ $project ? route('projects.chat.report', [$project, $message]) : route('lounge.report', $message) }}" class="mt-1 flex flex-wrap items-center gap-2">
                                            @csrf
                                            <label for="chat-report-{{ $message->id }}" class="sr-only">Report reason</label>
                                            <input id="chat-report-{{ $message->id }}" name="reason" type="text" required maxlength="1000" placeholder="What is wrong?"
                                                class="rounded-lg border-zinc-300 text-xs shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                            <button type="submit" class="rounded-lg border border-zinc-300 px-2 py-1 text-xs font-semibold text-zinc-700 hover:bg-zinc-50">Send</button>
                                        </form>
                                    </details>
                                @endif
                                @if($project !== null && $project->managedBy(auth()->user()) && $message->user_id !== auth()->id())
                                    <form method="POST" action="{{ route('projects.chat.destroy', [$project, $message]) }}" class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="font-semibold text-zinc-400 hover:text-red-600">Remove</button>
                                    </form>
                                @endif
                            </div>
                        @endauth
                    </div>
                </div>
            @endforeach
        </div>

        <p data-chat-typing class="mt-2 hidden text-sm text-zinc-500" aria-live="polite"></p>

        @auth
            @if($canPost ?? false)
                <form data-chat-form class="mt-3 flex items-end gap-2" data-draft-key="chat-{{ $room }}">
                    <label for="chat-body" class="sr-only">Message</label>
                    <textarea id="chat-body" name="body" rows="2" required maxlength="2000" placeholder="Write a message… (Enter sends, Shift+Enter adds a line)"
                        class="block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500"></textarea>
                    <button type="submit" class="shrink-0 rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">Send</button>
                </form>
                <p class="mt-1 text-xs text-zinc-500">Links and <code class="rounded bg-zinc-100 px-1 font-mono">code</code> welcome. No DMs here: harassment or secrets go to a maintainer, not the room.</p>
                <div data-chat-outbox class="mt-2 space-y-2"></div>
            @else
                <p class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ $denial ?? 'You cannot post here right now.' }}</p>
            @endif
        @else
            <p class="mt-3 rounded-xl border border-zinc-200 bg-white p-4 text-sm text-zinc-600"><a href="{{ route('login') }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Sign in</a> to join the chat.</p>
        @endauth
    </div>

    <aside class="lg:col-span-1">
        <div class="rounded-xl border border-zinc-200 bg-white p-5" data-presence-roster data-channel="presence-roster.{{ $room }}">
            <h2 class="text-sm font-semibold text-zinc-500">Here now</h2>
            <div data-presence-list class="mt-3">
                <p class="text-sm text-zinc-500" data-presence-state>Signing you in to presence…</p>
            </div>
            <p class="mt-3 text-xs text-zinc-500">Only members who opted in to presence appear here. <a href="{{ route('profile.edit') }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Presence settings</a></p>
        </div>
    </aside>
</div>
