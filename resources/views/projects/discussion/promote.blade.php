<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">
            <a href="{{ route('projects.show', $project) }}" class="hover:underline">{{ $project->title }}</a> · Promote
        </p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">Make this evidence or a task</h1>
        <p class="mt-1 text-sm text-zinc-600">Review and edit the wording before creating the record. The new record is a snapshot: later edits to the post never change it, and it keeps a link back to its source.</p>
    </x-slot>
    <div class="mx-auto max-w-2xl px-4 py-10 sm:px-6">
        <div class="mb-6 rounded-xl border border-zinc-200 bg-zinc-50 p-5 text-sm">
            <p class="text-xs text-zinc-500">{{ $post->author->name }} wrote (<a href="{{ $permalink }}" class="font-semibold text-emerald-700 hover:text-emerald-600">source post</a>):</p>
            <p class="mt-1 whitespace-pre-line text-zinc-800">{{ \Illuminate\Support\Str::limit($post->body, 600) }}</p>
        </div>

        <form method="POST" action="{{ route('projects.discussion.promote.store', [$project, $post]) }}" class="rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
            @csrf
            <div class="space-y-5">
                <div>
                    <span class="block text-sm font-semibold">Create as</span>
                    <div class="mt-2 flex flex-wrap gap-4 text-sm">
                        <label class="flex items-center gap-2">
                            <input type="radio" name="target" value="observation" required @checked(old('target', 'observation') === 'observation')
                                class="border-zinc-300 text-emerald-600 shadow-sm focus:ring-emerald-500">
                            Field observation (starts unverified)
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="radio" name="target" value="task" required @checked(old('target') === 'task')
                                class="border-zinc-300 text-emerald-600 shadow-sm focus:ring-emerald-500">
                            Contributor task (starts Open)
                        </label>
                    </div>
                    @error('target')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="title" class="block text-sm font-semibold">Title</label>
                    <input id="title" name="title" type="text" required maxlength="255" value="{{ old('title', $post->title ?? \App\Services\Discussion\Markup::excerpt($post->body, 80)) }}"
                        class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    @error('title')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="body" class="block text-sm font-semibold">Text</label>
                    <textarea id="body" name="body" rows="6" required maxlength="10000"
                        class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('body', $post->body) }}</textarea>
                    @error('body')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="milestone_id" class="block text-sm font-semibold">Milestone <span class="font-normal text-zinc-500">(tasks only, optional)</span></label>
                    <select id="milestone_id" name="milestone_id" class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <option value="">No milestone</option>
                        @foreach($milestones as $milestone)
                            <option value="{{ $milestone->id }}" @selected((int) old('milestone_id') === $milestone->id)>{{ $milestone->title }}</option>
                        @endforeach
                    </select>
                    @error('milestone_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="mt-6 flex items-center justify-end gap-3 border-t border-zinc-100 pt-6">
                <a href="{{ $permalink }}" class="text-sm font-semibold text-zinc-600 hover:text-zinc-900">Cancel</a>
                <button type="submit" class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">Create record</button>
            </div>
        </form>
    </div>
</x-app-layout>
