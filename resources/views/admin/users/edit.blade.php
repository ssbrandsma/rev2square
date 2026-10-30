<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Administration · User #{{ $user->id }}</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">Change role or AI limits</h1>
        <p class="mt-1 text-sm text-zinc-600"><a href="{{ route('admin.users.show', $user) }}" class="font-semibold text-emerald-700">← Back to account</a></p>
    </x-slot>
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        @include('admin._nav')
        @if(session('error'))
            <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ session('error') }}</p>
        @endif

        <section class="rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
            <h2 class="font-semibold">{{ $user->name }} <span class="font-normal text-zinc-500">{{ $user->email }}</span></h2>
            <p class="mt-1 max-w-[70ch] text-sm text-zinc-600">
                Current: {{ $user->global_role->label() }} · {{ $user->account_status->label() }} ·
                AI {{ $user->ai_enabled ? 'enabled' : 'disabled' }}{{ $user->ai_unlimited ? ' · unlimited exemption' : '' }}.
                @if($user->id === auth()->id()) Changing your own account takes effect on your next request.@endif
                Role changes require recent password confirmation. This application has no MFA facility; password confirmation is the available reauthentication step.
            </p>
            <form method="POST" action="{{ route('admin.users.update', $user) }}" class="mt-4 max-w-xl space-y-4 text-sm" onsubmit="return confirm('Apply these account changes?');">
                @csrf
                @method('PATCH')
                <label class="block">Global role
                    <select name="global_role" class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2">
                        @foreach(['user' => 'User', 'moderator' => 'Moderator', 'admin' => 'Admin'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('global_role', $user->global_role->value) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('global_role')<span class="text-red-700">{{ $message }}</span>@enderror
                </label>
                <label class="flex items-center gap-2">
                    <input type="hidden" name="ai_enabled" value="0" />
                    <input type="checkbox" name="ai_enabled" value="1" @checked(old('ai_enabled', $user->ai_enabled)) class="rounded border-zinc-300" />
                    AI access enabled (independent of account access)
                </label>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <label class="block">Daily request limit (blank = default)
                        <input name="ai_daily_request_limit" type="number" min="0" max="100000" value="{{ old('ai_daily_request_limit', $user->ai_daily_request_limit) }}" class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2" />
                        @error('ai_daily_request_limit')<span class="text-red-700">{{ $message }}</span>@enderror
                    </label>
                    <label class="block">Daily token limit (blank = default)
                        <input name="ai_daily_token_limit" type="number" min="0" max="100000000" value="{{ old('ai_daily_token_limit', $user->ai_daily_token_limit) }}" class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2" />
                        @error('ai_daily_token_limit')<span class="text-red-700">{{ $message }}</span>@enderror
                    </label>
                </div>
                <label class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
                    <input type="hidden" name="ai_unlimited" value="0" />
                    <input type="checkbox" name="ai_unlimited" value="1" @checked(old('ai_unlimited', $user->ai_unlimited)) class="mt-1 rounded border-zinc-300" />
                    <span>Explicit unlimited-AI exemption (bypasses daily budgets; still metered and audited — use sparingly and justify below)</span>
                </label>
                <label class="block">Internal reason (required, recorded in the audit log)
                    <input name="reason" required maxlength="2000" value="{{ old('reason') }}" placeholder="e.g. Promoted to moderator for community review" class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2" />
                    @error('reason')<span class="text-red-700">{{ $message }}</span>@enderror
                </label>
                <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Apply changes</button>
            </form>
        </section>
    </div>
</x-app-layout>
