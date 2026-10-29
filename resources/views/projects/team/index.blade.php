<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">
            <a href="{{ route('projects.show', $project) }}" class="hover:underline">{{ $project->title }}</a> · Team
        </p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">Project team</h1>
    </x-slot>
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        @if(session('status'))
            <p class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</p>
        @endif
        @if($errors->any())
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        @if($myInvitation)
            <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50/50 p-6">
                <h2 class="font-semibold">You are invited to maintain this project</h2>
                <p class="mt-1 text-sm text-zinc-600">Role: {{ $myInvitation->role->label() }} · expires {{ $myInvitation->expires_at->toDateString() }}. Nothing changes until you accept.</p>
                <div class="mt-3 flex gap-2">
                    <form method="POST" action="{{ route('projects.team.accept', [$project, $myInvitation]) }}" class="inline">
                        @csrf
                        <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Accept invitation</button>
                    </form>
                    <form method="POST" action="{{ route('projects.team.decline', [$project, $myInvitation]) }}" class="inline">
                        @csrf
                        <button type="submit" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50">Decline</button>
                    </form>
                </div>
            </div>
        @endif

        <div class="rounded-xl border border-zinc-200 bg-white p-6">
            <h2 class="text-sm font-semibold text-zinc-500">Maintainers</h2>
            <ul class="mt-3 divide-y divide-zinc-100">
                <li class="flex items-center justify-between gap-2 py-2 text-sm">
                    <span><a href="{{ $project->owner->profileUrl() }}" class="font-semibold hover:text-emerald-700">{{ $project->owner->name }}</a> <span class="text-zinc-500">· Owner</span></span>
                </li>
                @foreach($members as $member)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                        <span><a href="{{ $member->user->profileUrl() }}" class="font-semibold hover:text-emerald-700">{{ $member->user->name }}</a>
                            <span class="text-zinc-500">· {{ $member->role->label() }} · {{ $member->status }}</span></span>
                        @if($isOwner && $member->status === 'active')
                            <form method="POST" action="{{ route('projects.team.members.revoke', [$project, $member]) }}" class="inline">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="text-xs font-semibold text-zinc-500 hover:text-zinc-800" onclick="return confirm('Revoke this team member’s access?')">Revoke</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if($contributors->isNotEmpty())
                <h2 class="mt-6 text-sm font-semibold text-zinc-500">Contributors <span class="font-normal">(accepted offers — no management access)</span></h2>
                <ul class="mt-3 flex flex-wrap gap-2">
                    @foreach($contributors as $contributor)
                        <li class="rounded-full bg-zinc-100 px-3 py-1 text-xs font-semibold text-zinc-700"><a href="{{ $contributor->profileUrl() }}" class="hover:text-emerald-700">{{ $contributor->name }}</a></li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if($isOwner)
            <div class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                <h2 class="text-sm font-semibold text-zinc-500">Invite a maintainer</h2>
                <form method="POST" action="{{ route('projects.team.invite', $project) }}" class="mt-3 flex flex-wrap items-end gap-2">
                    @csrf
                    <div class="min-w-0 flex-1">
                        <label for="invite-email" class="block text-xs font-semibold text-zinc-500">Registered account email</label>
                        <input id="invite-email" name="email" type="email" required maxlength="255" placeholder="teammate@example.com"
                            class="mt-1 block w-full rounded-lg border-zinc-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    </div>
                    <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Send invitation</button>
                </form>
                <p class="mt-2 text-xs text-zinc-500">Invitations expire 14 days after sending. The Owner role is never granted by invitation; the owner always retains access.</p>
            </div>
        @endif

        @if($invitations->isNotEmpty())
            <div class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                <h2 class="text-sm font-semibold text-zinc-500">Invitations</h2>
                <ul class="mt-3 divide-y divide-zinc-100 text-sm">
                    @foreach($invitations as $invitation)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span><span class="font-semibold">{{ $invitation->user->name }}</span>
                                <span class="text-zinc-500">· {{ $invitation->role->label() }} · {{ $invitation->status->label() }} · expires {{ $invitation->expires_at->toDateString() }}</span></span>
                            @if($isOwner && $invitation->isPending())
                                <form method="POST" action="{{ route('projects.team.invitations.revoke', [$project, $invitation]) }}" class="inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="text-xs font-semibold text-zinc-500 hover:text-zinc-800">Revoke</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="mt-6"><a href="{{ route('projects.show', $project) }}" class="text-sm font-semibold text-emerald-700 hover:text-emerald-600">← Back to project</a></p>
    </div>
</x-app-layout>
