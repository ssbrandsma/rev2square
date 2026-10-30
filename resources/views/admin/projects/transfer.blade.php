<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Administration · Project #{{ $project->id }}</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">Transfer ownership</h1>
        <p class="mt-1 text-sm text-zinc-600"><a href="{{ route('admin.projects.show', $project) }}" class="font-semibold text-emerald-700">← Back to project</a></p>
    </x-slot>
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        @include('admin._nav')
        @if($errors->has('transfer'))
            <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ $errors->first('transfer') }}</p>
        @endif

        <section class="rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
            <h2 class="font-semibold">{{ $project->title }}</h2>
            <p class="mt-1 text-sm text-zinc-600">Current owner: <span class="font-semibold text-zinc-900">{{ $project->owner->name }}</span>
                <span class="text-zinc-500">{{ $project->owner->email }}</span></p>
            <p class="mt-2 max-w-[70ch] text-sm text-zinc-600">Ownership moves project management to the recipient. Content, evidence, tasks, contributions, and history stay with the project; historical authorship is never rewritten. Personal messages, personal Agent conversations, and AI consent are never transferred.</p>

            <form method="POST" action="{{ route('admin.projects.transfer.preview', $project) }}" class="mt-4 max-w-xl space-y-4 text-sm">
                @csrf
                <div>
                    <label for="transfer-email" class="block font-semibold">Recipient account email</label>
                    <input id="transfer-email" name="email" type="email" required maxlength="255" value="{{ old('email') }}" placeholder="new-owner@example.com"
                        class="mt-1 block w-full rounded-lg border-zinc-300 px-3 py-2" />
                    <p class="mt-1 text-xs text-zinc-500">Any active account qualifies; global admin status is not required and is never granted by a transfer.</p>
                    @error('email')<p class="mt-1 text-red-700">{{ $message }}</p>@enderror
                </div>
                <fieldset>
                    <legend class="font-semibold">Previous owner access</legend>
                    <label class="mt-1 flex items-start gap-2">
                        <input type="radio" name="keep_previous_owner" value="keep" @checked(old('keep_previous_owner') === 'keep') class="mt-1 rounded border-zinc-300" />
                        <span>Keep {{ $project->owner->name }} as a project maintainer</span>
                    </label>
                    <label class="mt-1 flex items-start gap-2">
                        <input type="radio" name="keep_previous_owner" value="remove" @checked(old('keep_previous_owner') === 'remove') class="mt-1 rounded border-zinc-300" />
                        <span>Remove {{ $project->owner->name }}'s project access entirely</span>
                    </label>
                    @error('keep_previous_owner')<p class="mt-1 text-red-700">{{ $message }}</p>@enderror
                </fieldset>
                <div>
                    <label for="transfer-reason" class="block font-semibold">Internal reason (required, recorded in the audit log)</label>
                    <input id="transfer-reason" name="reason" required maxlength="2000" value="{{ old('reason') }}" placeholder="e.g. Previous owner suspended; handing to an active maintainer"
                        class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2" />
                    @error('reason')<p class="mt-1 text-red-700">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 font-semibold text-white hover:bg-emerald-500">Preview transfer →</button>
            </form>
        </section>
    </div>
</x-app-layout>
