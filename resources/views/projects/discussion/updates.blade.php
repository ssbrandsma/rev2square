<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">
            <a href="{{ route('projects.show', $project) }}" class="hover:underline">{{ $project->title }}</a> · Updates
        </p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">Project updates</h1>
        <p class="mt-1 text-sm text-zinc-600">Updates from the maintainers.</p>
    </x-slot>

    @include('projects._nav', ['project' => $project, 'active' => 'updates'])

    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        @if(session('status'))
            <p class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</p>
        @endif

        @if($denial)
            <p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ $denial }}</p>
        @endif

        @can('update', $project)
            @unless($denial)
                <form method="POST" action="{{ route('projects.discussion.store', $project) }}" data-draft-key="update-{{ $project->id }}" class="mb-8 rounded-xl border border-zinc-200 bg-white p-5 sm:p-6">
                    @csrf
                    <input type="hidden" name="type" value="update">
                    <input type="hidden" name="submission_key" value="{{ old('submission_key', (string) Str::uuid()) }}">
                    <label for="update-title" class="block text-sm font-semibold">Publish an update</label>
                    <input id="update-title" name="title" type="text" required maxlength="255" value="{{ old('title') }}" placeholder="What changed?"
                        class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    @error('title')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    <label for="update-body" class="sr-only">Update text</label>
                    <textarea id="update-body" name="body" rows="4" required maxlength="10000" placeholder="Tell participants what happened and what is next…"
                        class="mt-3 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('body') }}</textarea>
                    @error('body')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    <div class="mt-3 flex justify-end">
                        <button type="submit" data-disable-on-submit class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">Publish update</button>
                    </div>
                </form>
            @endunless
        @endcan

        <div class="border-t border-zinc-200">
            @forelse($updates as $update)
                <article class="border-b border-zinc-200 py-5">
                    <p class="text-sm text-zinc-500">
                        <a href="{{ $update->author->profileUrl() }}" class="font-bold text-zinc-900 hover:text-emerald-700">{{ $update->author->name }}</a>
                        · <time datetime="{{ $update->created_at->toIso8601String() }}" title="{{ $update->created_at->format('Y-m-d H:i:s T') }}">{{ $update->created_at->diffForHumans() }}</time>
                    </p>
                    <h2 class="mt-1 text-lg font-semibold">
                        <a href="{{ route('projects.updates.show', [$project, $update]) }}" class="hover:text-emerald-700">{{ $update->title }}</a>
                    </h2>
                    <p class="mt-1 max-w-[70ch] text-zinc-700">{{ \App\Services\Discussion\Markup::excerpt($update->body, 240) }}</p>
                    <p class="mt-2 text-sm text-zinc-500">
                        {{ $update->replies_count }} {{ Str::plural('reply', $update->replies_count) }}
                        · <a href="{{ route('projects.updates.show', [$project, $update]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Read and discuss →</a>
                    </p>
                </article>
            @empty
                <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center text-sm text-zinc-600">No updates yet.</p>
            @endforelse
        </div>

        <div class="mt-6">{{ $updates->links() }}</div>
    </div>
</x-app-layout>
