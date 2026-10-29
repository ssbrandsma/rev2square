<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Administration</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">Application settings</h1>
    </x-slot>
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        @include('admin._nav')
        @if(session('status'))
            <p class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</p>
        @endif
        @if(session('error'))
            <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ session('error') }}</p>
        @endif

        <form method="POST" action="{{ route('admin.settings.update') }}">
            @csrf
            @method('PATCH')
            <section class="rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
                <h2 class="font-semibold">Ordinary settings</h2>
                <p class="mt-1 max-w-[70ch] text-sm text-zinc-600">Typed allowlist only. Environment-managed values are locked and cannot be changed here. Changes apply to web requests immediately and to queue workers on their next job.</p>
                <div class="mt-4 space-y-4">
                    @foreach($settings as $key => $entry)
                        <div class="border-t border-zinc-100 pt-4">
                            <label class="block text-sm" for="setting-{{ $loop->index }}">
                                <span class="font-mono font-semibold">{{ $key }}</span>
                                <span class="ml-2 rounded px-1 font-mono text-xs {{ $entry['source'] === 'environment' ? 'bg-zinc-200 text-zinc-700' : ($entry['source'] === 'database' ? 'bg-emerald-100 text-emerald-900' : 'bg-zinc-100 text-zinc-500') }}">{{ $entry['source'] }}</span>
                                @if($entry['locked'])<span class="ml-1 font-mono text-xs text-zinc-500">locked by env</span>@endif
                            </label>
                            <p class="mt-0.5 max-w-[70ch] text-xs text-zinc-500">{{ $entry['spec']['help'] }}</p>
                            @if($entry['spec']['type'] === 'bool')
                                <select id="setting-{{ $loop->index }}" name="settings[{{ $key }}]" @disabled($entry['locked']) class="mt-1 rounded-lg border border-zinc-300 px-3 py-2 text-sm disabled:opacity-50">
                                    <option value="1" @selected((bool) $entry['effective'])>On</option>
                                    <option value="0" @selected(! (bool) $entry['effective'])>Off</option>
                                </select>
                            @elseif(isset($entry['spec']['options']))
                                <select id="setting-{{ $loop->index }}" name="settings[{{ $key }}]" @disabled($entry['locked']) class="mt-1 rounded-lg border border-zinc-300 px-3 py-2 font-mono text-sm disabled:opacity-50">
                                    @foreach($entry['spec']['options'] as $option)
                                        <option value="{{ $option }}" @selected($entry['effective'] === $option)>{{ $option }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input id="setting-{{ $loop->index }}" name="settings[{{ $key }}]" value="{{ $entry['effective'] ?? '' }}" placeholder="{{ $entry['spec']['default'] === null ? 'empty (unset)' : 'default: '.$entry['spec']['default'] }}" @disabled($entry['locked']) class="mt-1 w-full max-w-md rounded-lg border border-zinc-300 px-3 py-2 font-mono text-sm disabled:opacity-50" />
                            @endif
                            @if($entry['updated_at'])
                                <p class="mt-0.5 text-xs text-zinc-500">Database value set {{ $entry['updated_at']->format('Y-m-d H:i') }}@if($entry['updated_by']) by {{ $entry['updated_by'] }}@endif</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
                <label class="block max-w-xl text-sm">Internal reason (recorded in the audit log)
                    <input name="reason" maxlength="2000" value="{{ old('reason') }}" placeholder="Why are these settings changing?" class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2" />
                </label>
                <button type="submit" class="mt-3 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Save settings</button>
            </section>
        </form>

        <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
            <h2 class="font-semibold">Secrets</h2>
            <p class="mt-1 text-sm text-zinc-600">The Meta API key is managed on a separate write-only page. <a href="{{ route('admin.settings.secrets') }}" class="font-semibold text-emerald-700">Manage API key →</a></p>
        </section>
    </div>
</x-app-layout>
