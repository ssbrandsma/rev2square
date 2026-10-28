@php($isMaintainer = $project->managedBy(auth()->user()))
@php($isAuthor = auth()->check() && $post->user_id === auth()->id())
<article class="border-b border-zinc-200 py-6 {{ $post->type === \App\Enums\DiscussionType::Announcement ? 'border-l-4 border-l-amber-400 pl-4' : '' }}" @if($anchor ?? false)id="{{ $anchor }}"@endif>
    <div class="flex items-start gap-3">
        <a href="{{ $post->author->profileUrl() }}" class="shrink-0" aria-label="{{ $post->author->name }}’s profile"><x-avatar :user="$post->author" /></a>
        <div class="min-w-0 flex-1">
            <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                <a href="{{ $post->author->profileUrl() }}" class="font-bold hover:text-emerald-700">{{ $post->author->name }}</a>
                @if($project->managedBy($post->author))
                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-900">Maintainer</span>
                @endif
                @if($post->type === \App\Enums\DiscussionType::Announcement)
                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-900">Announcement</span>
                @endif
                @if($post->type === \App\Enums\DiscussionType::Update)
                    <span class="rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-bold text-sky-900">Project update</span>
                @endif
                <span class="text-zinc-500">·</span>
                <time datetime="{{ $post->created_at->toIso8601String() }}" title="{{ $post->created_at->format('Y-m-d H:i:s T') }}" class="text-zinc-500">{{ $post->created_at->diffForHumans() }}</time>
                @if($post->edited_at)
                    <span class="text-zinc-500">· Edited</span>
                @endif
            </p>
            @if($post->title)
                <h3 class="mt-1 text-lg font-semibold">{{ $post->title }}</h3>
            @endif
            @if($post->task)
                <p class="mt-1 text-xs text-zinc-500">About task:
                    <a href="{{ route('projects.tasks.show', [$project, $post->task]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">{{ $post->task->title }}</a>
                </p>
            @endif
        </div>
    </div>

    <div class="mt-3 max-w-[70ch] space-y-3 leading-relaxed text-zinc-800 discussion-body">
        {!! \App\Services\Discussion\Markup::render($post->body) !!}
    </div>

    @if($showActions ?? true)
        <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-zinc-100 pt-3 text-sm">
            @if($isAuthor)
                <a href="{{ route('projects.discussion.edit', [$project, $post]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Edit</a>
                <form method="POST" action="{{ route('projects.discussion.destroy', [$project, $post]) }}" class="inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="font-semibold text-zinc-500 hover:text-zinc-800" onclick="return confirm('Remove this post? Threads with replies stay as a placeholder.')">Remove</button>
                </form>
            @endif
            @if($isMaintainer && ! $isAuthor)
                <details class="inline">
                    <summary class="cursor-pointer font-semibold text-zinc-500 hover:text-zinc-800">Moderate</summary>
                    <form method="POST" action="{{ route('projects.discussion.moderate', [$project, $post]) }}" class="mt-2 flex flex-wrap items-center gap-2">
                        @csrf
                        @method('PATCH')
                        <label for="mod-reason-{{ $post->id }}" class="sr-only">Moderation reason</label>
                        <input id="mod-reason-{{ $post->id }}" name="reason" type="text" required maxlength="500" placeholder="Reason (logged)"
                            class="min-w-0 flex-1 rounded-lg border-zinc-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <button type="submit" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-500">Remove post</button>
                    </form>
                </details>
                <a href="{{ route('projects.discussion.promote', [$project, $post]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Make evidence/task</a>
            @endif
            @if($isMaintainer && $isAuthor)
                <a href="{{ route('projects.discussion.promote', [$project, $post]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Make evidence/task</a>
            @endif
            @auth
                @unless($isAuthor || $isMaintainer)
                    <details class="inline">
                        <summary class="cursor-pointer font-semibold text-zinc-500 hover:text-zinc-800">Report</summary>
                        <form method="POST" action="{{ route('projects.discussion.report', [$project, $post]) }}" class="mt-2 flex flex-wrap items-center gap-2">
                            @csrf
                            <label for="report-reason-{{ $post->id }}" class="sr-only">Report reason</label>
                            <input id="report-reason-{{ $post->id }}" name="reason" type="text" required maxlength="1000" placeholder="What is wrong with this post?"
                                class="min-w-0 flex-1 rounded-lg border-zinc-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                            <button type="submit" class="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50">Send report</button>
                        </form>
                    </details>
                @endunless
            @endauth
            <a href="{{ route('projects.discussion.show', [$project, $post->thread()]) }}{{ $post->parent_id !== null ? '#reply-'.$post->id : '' }}" class="font-semibold text-zinc-400 hover:text-zinc-700">Permalink</a>
        </div>
    @endif
</article>
