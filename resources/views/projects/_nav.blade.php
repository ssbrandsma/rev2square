@php($discussionCount ??= $project->discussionThreads()->whereIn('type', [\App\Enums\DiscussionType::Post, \App\Enums\DiscussionType::Announcement])->count())
@php($updatesCount ??= $project->discussionThreads()->where('type', \App\Enums\DiscussionType::Update)->count())
@php($active ??= 'overview')
<nav aria-label="Project sections" class="border-b border-zinc-200 bg-white">
    <div class="mx-auto flex max-w-6xl gap-1 overflow-x-auto px-4 sm:px-6">
        <a href="{{ route('projects.show', $project) }}"
            class="whitespace-nowrap border-b-2 px-3 py-3 text-sm font-semibold {{ $active === 'overview' ? 'border-emerald-600 text-zinc-900' : 'border-transparent text-zinc-500 hover:text-zinc-900' }}">Overview</a>
        <a href="{{ route('projects.discussion.index', $project) }}"
            class="whitespace-nowrap border-b-2 px-3 py-3 text-sm font-semibold {{ $active === 'discussion' ? 'border-emerald-600 text-zinc-900' : 'border-transparent text-zinc-500 hover:text-zinc-900' }}">Discussion @if($discussionCount > 0)<span class="ml-1 rounded-full bg-zinc-100 px-1.5 py-0.5 text-[11px] text-zinc-700">{{ $discussionCount }}</span>@endif</a>
        <a href="{{ route('projects.updates.index', $project) }}"
            class="whitespace-nowrap border-b-2 px-3 py-3 text-sm font-semibold {{ $active === 'updates' ? 'border-emerald-600 text-zinc-900' : 'border-transparent text-zinc-500 hover:text-zinc-900' }}">Updates @if($updatesCount > 0)<span class="ml-1 rounded-full bg-zinc-100 px-1.5 py-0.5 text-[11px] text-zinc-700">{{ $updatesCount }}</span>@endif</a>
        <a href="{{ route('projects.chat.show', $project) }}"
            class="whitespace-nowrap border-b-2 px-3 py-3 text-sm font-semibold {{ $active === 'chat' ? 'border-emerald-600 text-zinc-900' : 'border-transparent text-zinc-500 hover:text-zinc-900' }}">Chat</a>
        <a href="{{ route('projects.team.index', $project) }}"
            class="whitespace-nowrap border-b-2 px-3 py-3 text-sm font-semibold {{ $active === 'team' ? 'border-emerald-600 text-zinc-900' : 'border-transparent text-zinc-500 hover:text-zinc-900' }}">Team</a>
        @can('update', $project)
            <a href="{{ route('projects.evidence', $project) }}"
                class="whitespace-nowrap border-b-2 border-transparent px-3 py-3 text-sm font-semibold text-zinc-500 hover:text-zinc-900">Evidence</a>
        @endcan
        <a href="{{ route('projects.architecture.show', $project) }}"
            class="whitespace-nowrap border-b-2 px-3 py-3 text-sm font-semibold {{ $active === 'architecture' ? 'border-emerald-600 text-zinc-900' : 'border-transparent text-zinc-500 hover:text-zinc-900' }}">Architecture</a>
        @auth
            <a href="{{ route('projects.agent.index', $project) }}"
                class="whitespace-nowrap border-b-2 px-3 py-3 text-sm font-semibold {{ $active === 'agent' ? 'border-emerald-600 text-zinc-900' : 'border-transparent text-zinc-500 hover:text-zinc-900' }}">Agent</a>
        @endauth
        <a href="{{ route('projects.show', $project) }}#roadmap"
            class="whitespace-nowrap border-b-2 border-transparent px-3 py-3 text-sm font-semibold text-zinc-500 hover:text-zinc-900">Roadmap</a>
        <a href="{{ route('projects.show', $project) }}#tasks"
            class="whitespace-nowrap border-b-2 border-transparent px-3 py-3 text-sm font-semibold text-zinc-500 hover:text-zinc-900">Tasks</a>
    </div>
</nav>
