<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">
            <a href="{{ route('projects.show', $project) }}" class="hover:underline">{{ $project->title }}</a> · Edit post
        </p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">Edit your post</h1>
    </x-slot>
    <div class="mx-auto max-w-2xl px-4 py-10 sm:px-6">
        <form method="POST" action="{{ route('projects.discussion.update', [$project, $post]) }}" class="rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
            @csrf
            @method('PATCH')
            @if($post->parent_id === null && $post->type === \App\Enums\DiscussionType::Update)
                <div class="mb-5">
                    <label for="title" class="block text-sm font-semibold">Title</label>
                    <input id="title" name="title" type="text" required maxlength="255" value="{{ old('title', $post->title) }}"
                        class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    @error('title')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
            @endif
            <div>
                <label for="body" class="block text-sm font-semibold">Post</label>
                <textarea id="body" name="body" rows="6" required maxlength="10000"
                    class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('body', $post->body) }}</textarea>
                @error('body')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <p class="mt-3 text-xs text-zinc-500">Edited posts are marked Edited. To remove content instead, use Remove on the post.</p>
            <div class="mt-6 flex items-center justify-end gap-3 border-t border-zinc-100 pt-6">
                <a href="{{ route('projects.discussion.show', [$project, $post->thread()]) }}" class="text-sm font-semibold text-zinc-600 hover:text-zinc-900">Cancel</a>
                <button type="submit" class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">Save changes</button>
            </div>
        </form>
    </div>
</x-app-layout>
