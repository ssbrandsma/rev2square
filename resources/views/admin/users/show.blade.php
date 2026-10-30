<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Administration · User #{{ $user->id }}</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">{{ $user->name }}</h1>
        <p class="mt-1 text-sm text-zinc-600"><a href="{{ route('admin.users.index') }}" class="font-semibold text-emerald-700">← All users</a></p>
    </x-slot>
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        @include('admin._nav')
        @if(session('status'))
            <p class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</p>
        @endif
        @if(session('error'))
            <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ session('error') }}</p>
        @endif

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <section class="rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
                <h2 class="font-semibold">Account</h2>
                <dl class="mt-2 space-y-2 text-sm">
                    <div><dt class="text-zinc-500">Email</dt><dd>{{ $user->email }}</dd></div>
                    <div><dt class="text-zinc-500">Global role</dt><dd class="font-semibold">{{ $user->global_role->label() }} <span class="font-normal text-zinc-500">(project roles are separate)</span></dd></div>
                    <div><dt class="text-zinc-500">Status</dt><dd class="font-semibold">{{ $user->account_status->label() }}@if($user->suspended_at) since {{ $user->suspended_at->format('Y-m-d H:i') }}@endif</dd></div>
                    <div><dt class="text-zinc-500">Registered</dt><dd>{{ $user->created_at->format('Y-m-d H:i') }}</dd></div>
                    <div><dt class="text-zinc-500">Last activity</dt><dd>{{ $lastActivity?->format('Y-m-d H:i') ?? 'not recorded' }}</dd></div>
                    <div><dt class="text-zinc-500">Projects</dt><dd class="font-mono text-xs">{{ $user->owned_projects_count }} owned · {{ $user->created_projects_count }} created · {{ $user->project_memberships_count }} memberships</dd></div>
                </dl>
                <p class="mt-3 text-sm"><a href="{{ route('admin.users.edit', $user) }}" class="font-semibold text-emerald-700">Change role or AI limits →</a></p>
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
                <h2 class="font-semibold">AI access and usage</h2>
                <dl class="mt-2 space-y-2 text-sm">
                    <div><dt class="text-zinc-500">AI access</dt><dd class="font-semibold">{{ $user->ai_enabled ? 'Enabled' : 'Disabled' }}@if($user->ai_unlimited) · unlimited (explicit exemption)@endif</dd></div>
                    <div><dt class="text-zinc-500">Daily limits</dt><dd class="font-mono text-xs">{{ $user->ai_daily_request_limit ?? 'default ('.$aiDefaults['requests'].')' }} requests · {{ $user->ai_daily_token_limit !== null ? number_format($user->ai_daily_token_limit) : 'default ('.number_format($aiDefaults['tokens']).')' }} tokens</dd></div>
                    <div><dt class="text-zinc-500">Today (UTC)</dt><dd class="font-mono text-xs">{{ $todayUsage['requests'] }} requests · {{ number_format($todayUsage['tokens']) }} tokens</dd></div>
                    <div><dt class="text-zinc-500">All time</dt><dd class="font-mono text-xs">{{ $totalUsage['requests'] }} requests · {{ number_format($totalUsage['tokens']) }} tokens</dd></div>
                </dl>
                <p class="mt-3 text-sm"><a href="{{ route('admin.usage.user', $user) }}" class="font-semibold text-emerald-700">Usage detail →</a></p>
            </section>
        </div>

        @if(! empty($projectsNeedingAttention))
            <section class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 sm:p-6">
                <h2 class="font-semibold text-amber-900">Projects needing attention</h2>
                <p class="mt-1 text-sm text-amber-900">This suspended account still owns {{ count($projectsNeedingAttention) }} project(s). Ownership is never transferred automatically — arrange new maintainers explicitly.</p>
                <ul class="mt-2 list-inside list-disc text-sm text-amber-900">
                    @foreach($projectsNeedingAttention as $id => $title)
                        <li><a href="{{ route('admin.projects.show', $id) }}" class="font-semibold underline">{{ $title }}</a></li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
            <h2 class="font-semibold">{{ $user->isSuspended() ? 'Reactivate account' : 'Suspend account' }}</h2>
            <p class="mt-1 max-w-[70ch] text-sm text-zinc-600">
                @if($user->isSuspended())
                    Reactivation restores access with the current role and AI limits unchanged. Past contributions stay attributed.
                @else
                    Suspension takes effect immediately: sessions are revoked, queued AI work is blocked, and new logins are refused. Projects, contributions, and audit history are preserved — nothing is deleted or transferred.
                @endif
                Requires recent password confirmation. There is deliberately no “log in as user” shortcut and no password viewing or setting here — use the normal password-reset flow.
            </p>
            @if(! $user->isSuspended() && ! empty($ownedProjects))
                <p class="mt-2 max-w-[70ch] text-sm font-semibold text-amber-900">This account owns {{ count($ownedProjects) }} project(s). Suspension preserves them — <a href="{{ route('admin.projects.index', ['q' => $user->email]) }}" class="underline">review them here</a> and transfer ownership explicitly if needed.</p>
            @endif
            @if($user->id === auth()->id() && ! $user->isSuspended())
                <p class="mt-2 max-w-[70ch] rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900">This is your own account. Suspending it revokes your sessions immediately.</p>
            @endif
            <form method="POST" action="{{ $user->isSuspended() ? route('admin.users.reactivate', $user) : route('admin.users.suspend', $user) }}" class="mt-3 flex flex-col gap-2 sm:flex-row" onsubmit="return confirm('Confirm this account change?');">
                @csrf
                <label for="suspend-reason" class="sr-only">Internal reason</label>
                <input id="suspend-reason" name="reason" required maxlength="2000" placeholder="Internal reason (recorded in the audit log)"
                    class="flex-1 rounded-lg border border-zinc-300 px-3 py-2 text-sm" />
                <button type="submit" class="rounded-lg {{ $user->isSuspended() ? 'bg-emerald-600 hover:bg-emerald-500' : 'bg-red-600 hover:bg-red-500' }} px-4 py-2 text-sm font-semibold text-white">
                    {{ $user->isSuspended() ? 'Reactivate' : 'Suspend' }}
                </button>
            </form>
            @error('reason')
                <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
            @enderror
        </section>

        <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
            <h2 class="font-semibold">Recent administrative history</h2>
            @if($recentAudits->isEmpty())
                <p class="mt-2 text-sm text-zinc-500">No recorded administrative actions for this account.</p>
            @else
                <ul class="mt-2 space-y-2 text-sm">
                    @foreach($recentAudits as $audit)
                        <li>
                            <span class="font-mono text-xs">{{ $audit->created_at->format('Y-m-d H:i') }}</span>
                            <span class="font-semibold">{{ $audit->action }}</span>
                            by {{ $audit->actor?->name ?? 'console' }}
                            @if($audit->reason)<span class="text-zinc-600">— {{ $audit->reason }}</span>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-app-layout>
