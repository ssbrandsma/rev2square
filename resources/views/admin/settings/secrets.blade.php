<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Administration</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">Meta API key</h1>
    </x-slot>
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        @include('admin._nav')
        @if(session('status'))
            <p class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</p>
        @endif
        @if(session('error'))
            <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ session('error') }}</p>
        @endif

        <section class="rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
            <h2 class="font-semibold">Status</h2>
            <dl class="mt-2 space-y-2 text-sm">
                <div><dt class="text-zinc-500">Configured</dt><dd class="font-semibold">{{ $configured ? 'Yes' : 'No' }}</dd></div>
                <div><dt class="text-zinc-500">Source</dt><dd class="font-mono">{{ $source }}</dd></div>
                @if($updatedAt)
                    <div><dt class="text-zinc-500">Last updated</dt><dd>{{ $updatedAt->format('Y-m-d H:i') }}@if($updatedBy) by {{ $updatedBy }}@endif</dd></div>
                @endif
            </dl>
            <p class="mt-2 max-w-[70ch] text-xs text-zinc-500">The key value is never shown here, never sent back to the browser, and never written to logs or audit records. Stored keys are encrypted at rest.</p>
        </section>

        @if($locked)
            <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
                <p class="text-sm text-zinc-600">The key is managed by the <span class="font-mono">MODEL_API_KEY</span> environment variable. It cannot be changed or removed from this page; environment settings win and are never silently overridden.</p>
            </section>
        @else
            <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
                <h2 class="font-semibold">Replace key</h2>
                <p class="mt-1 max-w-[70ch] text-sm text-zinc-600">Paste the new key. Submitting a blank field keeps the existing key unchanged. Requires recent password confirmation.</p>
                <form method="POST" action="{{ route('admin.settings.secrets.update') }}" class="mt-3 max-w-xl space-y-3 text-sm">
                    @csrf
                    @method('PATCH')
                    <label class="block">New Meta API key
                        <input type="password" name="api_key" autocomplete="off" value="" placeholder="Leave blank to keep the current key" class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 font-mono" />
                        @error('api_key')<span class="text-red-700">{{ $message }}</span>@enderror
                    </label>
                    <label class="block">Internal reason (required)
                        <input name="reason" required maxlength="2000" value="{{ old('reason') }}" class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2" />
                        @error('reason')<span class="text-red-700">{{ $message }}</span>@enderror
                    </label>
                    <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Replace key</button>
                </form>
            </section>

            <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
                <h2 class="font-semibold">Connection check</h2>
                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <form method="POST" action="{{ route('admin.settings.test') }}" class="rounded-lg border border-zinc-200 p-4 text-sm">
                        @csrf
                        <input type="hidden" name="mode" value="format" />
                        <p class="font-semibold">Format check (free)</p>
                        <p class="mt-1 text-xs text-zinc-500">Local length/charset sanity only. Proves nothing about validity. Never billable.</p>
                        <button type="submit" class="mt-2 rounded-lg border border-zinc-300 px-4 py-2 font-semibold text-zinc-700 hover:bg-zinc-50">Run format check</button>
                    </form>
                    <form method="POST" action="{{ route('admin.settings.test') }}" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm" onsubmit="return confirm('Send one minimal inference request? This is billable.');">
                        @csrf
                        <input type="hidden" name="mode" value="live" />
                        <p class="font-semibold text-amber-900">Live test (billable)</p>
                        <p class="mt-1 text-xs text-amber-900">Sends one minimal 5-token inference to prove the key works. No non-billable provider validation endpoint is known.</p>
                        <button type="submit" class="mt-2 rounded-lg bg-amber-600 px-4 py-2 font-semibold text-white hover:bg-amber-500">Send live test</button>
                    </form>
                </div>
            </section>

            <section class="mt-6 rounded-xl border border-red-200 bg-white p-4 sm:p-6">
                <h2 class="font-semibold text-red-800">Remove stored key</h2>
                <p class="mt-1 max-w-[70ch] text-sm text-zinc-600">Separate explicit action. Model requests will fail until a key is configured again.</p>
                <form method="POST" action="{{ route('admin.settings.secrets.destroy') }}" class="mt-3 flex max-w-xl flex-col gap-2 sm:flex-row" onsubmit="return confirm('Remove the stored API key?');">
                    @csrf
                    @method('DELETE')
                    <label for="remove-reason" class="sr-only">Internal reason</label>
                    <input id="remove-reason" name="reason" required maxlength="2000" placeholder="Internal reason (required)" class="flex-1 rounded-lg border border-zinc-300 px-3 py-2 text-sm" />
                    <button type="submit" class="rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">Remove key</button>
                </form>
            </section>
        @endif
    </div>
</x-app-layout>
