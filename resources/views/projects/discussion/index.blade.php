<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">
            <a href="{{ route('projects.show', $project) }}" class="hover:underline">{{ $project->title }}</a> · Discussion
        </p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">Project discussion</h1>
    </x-slot>

    @include('projects._nav', ['project' => $project, 'active' => 'discussion'])

    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        @if(session('status'))
            <p class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</p>
        @endif

        @if($denial)
            <p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ $denial }}</p>
        @endif

        @can('update', $project)
            <div class="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-zinc-200 bg-white p-4 text-sm">
                <span class="text-zinc-600">Conversation is <strong>{{ $project->discussion_open ? 'open' : 'closed' }}</strong>.</span>
                <form method="POST" action="{{ route('projects.discussion.toggle', $project) }}" class="inline">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="open" value="{{ $project->discussion_open ? '0' : '1' }}">
                    <button type="submit" class="font-semibold text-emerald-700 hover:text-emerald-600">{{ $project->discussion_open ? 'Close conversation' : 'Reopen conversation' }}</button>
                </form>
                <a href="{{ route('projects.moderation.index', $project) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Member reports →</a>
            </div>
        @endcan

        <div class="mb-6 rounded-xl border border-zinc-200 bg-white p-5 text-sm text-zinc-600">
            <p class="font-semibold text-zinc-900">How discussion works here</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                <li>One level of replies; threads sort by latest activity, replies read oldest first.</li>
                <li>Links and <code class="rounded bg-zinc-100 px-1 font-mono text-[13px]">code</code> are welcome; everything else is plain safe text.</li>
                <li>Authors can edit (marked Edited) or remove their posts; threads with replies stay as placeholders.</li>
                <li>Unverified posts are conversation, not verified hardware claims. Never share credentials or personal data.</li>
            </ul>
        </div>

        @auth
            @unless($denial)
                <form method="POST" action="{{ route('projects.discussion.store', $project) }}" data-draft-key="thread-{{ $project->id }}" class="mb-8 rounded-xl border border-zinc-200 bg-white p-5 sm:p-6">
                    @csrf
                    <input type="hidden" name="submission_key" value="{{ old('submission_key', (string) Str::uuid()) }}">
                    <label for="thread-body" class="block text-sm font-semibold">Start a discussion</label>
                    <textarea id="thread-body" name="body" rows="4" required maxlength="10000" placeholder="Ask a question, share progress, or propose next steps…"
                        class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('body') }}</textarea>
                    @error('body')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="thread-task" class="block text-xs font-semibold text-zinc-500">Linked task (optional)</label>
                            <select id="thread-task" name="contributor_task_id" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                <option value="">No specific task</option>
                                @foreach($project->tasks()->active()->orderBy('title')->get() as $task)
                                    <option value="{{ $task->id }}" @selected((int) old('contributor_task_id') === $task->id)>{{ $task->title }}</option>
                                @endforeach
                            </select>
                        </div>
                        @can('update', $project)
                            <div>
                                <label for="thread-type" class="block text-xs font-semibold text-zinc-500">Post as</label>
                                <select id="thread-type" name="type" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                    <option value="post">Discussion</option>
                                    <option value="announcement" @selected(old('type') === 'announcement')>Announcement</option>
                                </select>
                            </div>
                        @endcan
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button type="submit" data-disable-on-submit class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">Post discussion</button>
                    </div>
                </form>
            @endunless
        @else
            <p class="mb-8 rounded-xl border border-zinc-200 bg-white p-5 text-sm text-zinc-600"><a href="{{ route('login') }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Sign in</a> to join the discussion.</p>
        @endauth

        <div class="border-t border-zinc-200">
            @forelse($threads as $thread)
                <article class="border-b border-zinc-200 py-5">
                    <div class="flex items-start gap-3">
                        <a href="{{ $thread->author->profileUrl() }}" class="shrink-0" aria-label="{{ $thread->author->name }}’s profile"><x-avatar :user="$thread->author" /></a>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm">
                                <a href="{{ $thread->author->profileUrl() }}" class="font-bold hover:text-emerald-700">{{ $thread->author->name }}</a>
                                @if($project->managedBy($thread->author))
                                    <span class="ml-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-900">Maintainer</span>
                                @endif
                                @if($thread->type === \App\Enums\DiscussionType::Announcement)
                                    <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-900">Announcement</span>
                                @endif
                                <span class="text-zinc-500">· <time datetime="{{ $thread->created_at->toIso8601String() }}" title="{{ $thread->created_at->format('Y-m-d H:i:s T') }}">{{ $thread->created_at->diffForHumans() }}</time></span>
                            </p>
                            <p class="mt-1 max-w-[70ch] text-zinc-800">{{ \App\Services\Discussion\Markup::excerpt($thread->body, 220) }}</p>
                            <p class="mt-2 text-sm text-zinc-500">
                                {{ $thread->replies_count }} {{ Str::plural('reply', $thread->replies_count) }}
                                · last activity {{ $thread->activityAt()->diffForHumans() }}
                                @if($thread->task) · task: {{ $thread->task->title }}@endif
                                · <a href="{{ route('projects.discussion.show', [$project, $thread]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Open thread →</a>
                            </p>
                        </div>
                    </div>
                </article>
            @empty
                <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center text-sm text-zinc-600">No discussions yet. Start the first one above.</p>
            @endforelse
        </div>

        <div class="mt-6">{{ $threads->links() }}</div>
    </div>
</x-app-layout>
