<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">
            <a href="{{ route('projects.show', $project) }}" class="hover:underline">{{ $project->title }}</a> ·
            @if($thread->type === \App\Enums\DiscussionType::Update)
                <a href="{{ route('projects.updates.index', $project) }}" class="hover:underline">Updates</a>
            @else
                <a href="{{ route('projects.discussion.index', $project) }}" class="hover:underline">Discussion</a>
            @endif
        </p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">
            @if($thread->title)
                {{ $thread->title }}
            @else
                Discussion from {{ $thread->created_at->format('M j, Y') }}
            @endif
        </h1>
    </x-slot>

    @include('projects._nav', ['project' => $project, 'active' => $thread->type === \App\Enums\DiscussionType::Update ? 'updates' : 'discussion'])

    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        @if(session('status'))
            <p class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</p>
        @endif

        @if($denial)
            <p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ $denial }}</p>
        @endif

        @if($thread->trashed())
            <p class="mb-4 rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-600">The opening post was removed. Replies below stay readable for context.</p>
        @else
            @include('projects.discussion._post', ['project' => $project, 'post' => $thread])
        @endif

        <div class="mt-4 flex flex-wrap items-center gap-3 text-sm">
            @auth
                @if($following)
                    <form method="POST" action="{{ route('projects.discussion.unfollow', [$project, $thread]) }}" class="inline">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="font-semibold text-zinc-500 hover:text-zinc-800">Unfollow thread</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('projects.discussion.follow', [$project, $thread]) }}" class="inline">
                        @csrf
                        <button type="submit" class="font-semibold text-emerald-700 hover:text-emerald-600">Follow thread</button>
                    </form>
                @endif
                @if($muted)
                    <form method="POST" action="{{ route('projects.discussion.unmute', [$project, $thread]) }}" class="inline">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="font-semibold text-zinc-500 hover:text-zinc-800">Unmute thread</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('projects.discussion.mute', [$project, $thread]) }}" class="inline">
                        @csrf
                        <button type="submit" class="font-semibold text-zinc-500 hover:text-zinc-800">Mute thread</button>
                    </form>
                @endif
            @endauth
        </div>

        <h2 class="mb-1 mt-8 text-sm font-semibold text-zinc-500">Replies ({{ $replies->total() }})</h2>
        <div>
            @forelse($replies as $reply)
                @include('projects.discussion._post', ['project' => $project, 'post' => $reply, 'anchor' => 'reply-'.$reply->id])
            @empty
                <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-600">No replies yet.</p>
            @endforelse
        </div>

        <div class="mt-6">{{ $replies->links() }}</div>

        @auth
            @unless($denial)
                <form method="POST" action="{{ route('projects.discussion.replies.store', [$project, $thread]) }}" data-draft-key="reply-{{ $thread->id }}" class="mt-6 rounded-xl border border-zinc-200 bg-white p-5 sm:p-6">
                    @csrf
                    <input type="hidden" name="submission_key" value="{{ old('submission_key', (string) Str::uuid()) }}">
                    <label for="reply-body" class="block text-sm font-semibold">Reply</label>
                    <textarea id="reply-body" name="body" rows="4" required maxlength="10000" placeholder="Write a reply… Use @Name to mention someone."
                        class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('body') }}</textarea>
                    @error('body')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    <div class="mt-3 flex justify-end">
                        <button type="submit" data-disable-on-submit class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">Post reply</button>
                    </div>
                </form>
            @endunless
        @else
            <p class="mt-6 rounded-xl border border-zinc-200 bg-white p-5 text-sm text-zinc-600"><a href="{{ route('login') }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Sign in</a> to reply.</p>
        @endauth
    </div>
</x-app-layout>
