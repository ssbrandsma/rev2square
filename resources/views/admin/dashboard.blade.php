<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Administration</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">Overview</h1>
    </x-slot>
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        @include('admin._nav')
        @if(session('status'))
            <p class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</p>
        @endif
        @if(session('error'))
            <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ session('error') }}</p>
        @endif

        @if(! empty($warnings))
            <section aria-label="Warnings" class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 sm:p-6">
                <h2 class="font-semibold text-amber-900">Needs attention ({{ count($warnings) }})</h2>
                <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-amber-900">
                    @foreach($warnings as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
            <section class="rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
                <h2 class="font-semibold">Accounts</h2>
                <dl class="mt-2 grid grid-cols-2 gap-2 text-sm">
                    <div><dt class="text-zinc-500">Total</dt><dd class="font-mono font-semibold">{{ $userCounts['total'] }}</dd></div>
                    <div><dt class="text-zinc-500">Active admins</dt><dd class="font-mono font-semibold">{{ $userCounts['admins'] }}</dd></div>
                    <div><dt class="text-zinc-500">Active moderators</dt><dd class="font-mono font-semibold">{{ $userCounts['moderators'] }}</dd></div>
                    <div><dt class="text-zinc-500">Suspended</dt><dd class="font-mono font-semibold">{{ $userCounts['suspended'] }}</dd></div>
                    <div><dt class="text-zinc-500">AI-disabled</dt><dd class="font-mono font-semibold">{{ $userCounts['ai_disabled'] }}</dd></div>
                    <div><dt class="text-zinc-500">Pending reports</dt><dd class="font-mono font-semibold">{{ $pendingReports }}</dd></div>
                </dl>
                <p class="mt-3 text-sm"><a href="{{ route('admin.users.index') }}" class="font-semibold text-emerald-700">Manage users →</a></p>
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
                <h2 class="font-semibold">AI usage today (UTC {{ $today['day'] }})</h2>
                <dl class="mt-2 grid grid-cols-2 gap-2 text-sm">
                    <div><dt class="text-zinc-500">Provider requests</dt><dd class="font-mono font-semibold">{{ $today['requests'] }}</dd></div>
                    <div><dt class="text-zinc-500">Reconciled tokens</dt><dd class="font-mono font-semibold">{{ number_format($today['tokens']) }}</dd></div>
                    <div><dt class="text-zinc-500">Failed attempts</dt><dd class="font-mono font-semibold">{{ $today['errors'] }}</dd></div>
                    <div><dt class="text-zinc-500">Cost</dt><dd class="font-mono font-semibold">{{ $today['cost'] }}</dd></div>
                </dl>
                <p class="mt-1 text-xs text-zinc-500">Costs are estimates from operator-entered pricing, or unknown when pricing is missing — never zero by default.</p>
                <p class="mt-3 text-sm"><a href="{{ route('admin.usage.index') }}" class="font-semibold text-emerald-700">Usage details →</a></p>
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
                <h2 class="font-semibold">Meta API key</h2>
                <p class="mt-2 text-sm text-zinc-600">
                    Status: <span class="font-semibold">{{ $keySource === 'default' ? 'not configured' : 'configured ('.$keySource.')' }}</span>
                </p>
                <p class="mt-3 text-sm"><a href="{{ route('admin.settings.secrets') }}" class="font-semibold text-emerald-700">Manage key →</a></p>
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
                <h2 class="font-semibold">Review</h2>
                <ul class="mt-2 space-y-2 text-sm">
                    <li><a href="{{ route('admin.audit.index') }}" class="font-semibold text-emerald-700">Administrative audit log →</a></li>
                    <li><a href="{{ route('admin.moderation.index') }}" class="font-semibold text-emerald-700">Moderation queue ({{ $pendingReports }} pending) →</a></li>
                    <li><a href="{{ route('admin.settings.index') }}" class="font-semibold text-emerald-700">Application settings →</a></li>
                </ul>
            </section>
        </div>
    </div>
</x-app-layout>
