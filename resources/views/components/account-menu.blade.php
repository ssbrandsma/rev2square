<div class="relative" x-data="{ accountOpen: false }" @keydown.escape.window="if (accountOpen) { accountOpen = false; $refs.accountButton.focus(); }">
    <button type="button" x-ref="accountButton" @click="accountOpen = !accountOpen" :aria-expanded="accountOpen" aria-haspopup="menu" aria-label="Account menu for {{ auth()->user()->name }}"
        class="flex max-w-48 items-center gap-2 rounded-lg p-1 hover:bg-zinc-800">
        <x-avatar :user="auth()->user()" size="sm" />
        <span class="hidden truncate text-sm font-medium text-zinc-200 lg:inline">{{ auth()->user()->name }}</span>
        <svg class="h-4 w-4 shrink-0 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
    </button>
    <div x-show="accountOpen" x-cloak @click.outside="accountOpen = false" role="menu" aria-label="Account"
        class="absolute right-0 z-50 mt-2 w-80 rounded-xl border border-zinc-200 bg-white p-2 text-zinc-900 shadow-xl">
        <div class="flex items-center gap-3 px-3 pb-2 pt-2">
            <x-avatar :user="auth()->user()" />
            <div class="min-w-0">
                <p class="truncate text-sm font-bold">{{ auth()->user()->name }}</p>
                <p class="truncate text-xs text-zinc-500">@if(auth()->user()->handle){{ '@'.auth()->user()->handle }}@else{{ auth()->user()->email }}@endif</p>
            </div>
        </div>
        @php($menuProjects = $recentProjects())
        @if($menuProjects->isNotEmpty())
            <p class="px-3 pb-1 pt-2 text-xs font-semibold text-zinc-500">Recent projects</p>
            <ul class="max-h-64 overflow-y-auto">
                @foreach($menuProjects as $menuProject)
                    <li>
                        <a href="{{ route('projects.show', $menuProject) }}" role="menuitem" class="flex items-center justify-between gap-2 rounded-lg px-3 py-2 hover:bg-zinc-50">
                            <span class="min-w-0 truncate text-sm font-semibold">{{ $menuProject->title }}</span>
                            <span class="shrink-0 text-xs text-zinc-500">{{ (int) $menuProject->owner_id === auth()->id() ? 'Owner' : 'Member' }} · {{ $menuProject->publication_status->label() }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
        <div class="mt-1 border-t border-zinc-100 pt-1">
            <a href="{{ auth()->user()->profileUrl() }}" role="menuitem" class="block rounded-lg px-3 py-2 text-sm hover:bg-zinc-50">View my public profile</a>
            <a href="{{ route('dashboard') }}" role="menuitem" class="block rounded-lg px-3 py-2 text-sm hover:bg-zinc-50">My workspace</a>
            <a href="{{ route('dashboard') }}#saved" role="menuitem" class="block rounded-lg px-3 py-2 text-sm hover:bg-zinc-50">Saved projects</a>
            <a href="{{ route('inbox.index') }}" role="menuitem" class="block rounded-lg px-3 py-2 text-sm hover:bg-zinc-50">Inbox @if($unreadCount() > 0)({{ $unreadCount() }} unread)@endif</a>
            <a href="{{ route('dashboard') }}#applications" role="menuitem" class="block rounded-lg px-3 py-2 text-sm hover:bg-zinc-50">My applications</a>
            <a href="{{ route('profile.edit') }}" role="menuitem" class="block rounded-lg px-3 py-2 text-sm hover:bg-zinc-50">Edit profile &amp; account settings</a>
        </div>
        <div class="mt-1 border-t border-zinc-100 pt-1">
            <form method="POST" action="{{ route('logout') }}" data-logout>
                @csrf
                <button type="submit" role="menuitem" class="block w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-zinc-50">Log out</button>
            </form>
        </div>
    </div>
</div>
