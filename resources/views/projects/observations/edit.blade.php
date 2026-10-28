<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Edit observation · {{ $project->title }}</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">{{ $observation->title }}</h1>
    </x-slot>
    <div class="mx-auto max-w-2xl px-4 py-10 sm:px-6">
        <form method="POST" action="{{ route('projects.observations.update', [$project, $observation]) }}" class="rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
            @csrf
            @method('PATCH')
            <div class="space-y-5">
                <div>
                    <label for="title" class="block text-sm font-semibold">Title</label>
                    <input id="title" name="title" type="text" required maxlength="255" value="{{ old('title', $observation->title) }}"
                        class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    @error('title')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="body" class="block text-sm font-semibold">What did you find?</label>
                    <textarea id="body" name="body" rows="5" required maxlength="10000"
                        class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('body', $observation->body) }}</textarea>
                    @error('body')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="source_url" class="block text-sm font-semibold">Documentation link <span class="font-normal text-zinc-500">(optional reference)</span></label>
                    <input id="source_url" name="source_url" type="url" maxlength="2000" value="{{ old('source_url', $observation->source_url) }}"
                        class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    @error('source_url')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="contributor_task_id" class="block text-sm font-semibold">Related task <span class="font-normal text-zinc-500">(optional)</span></label>
                        <select id="contributor_task_id" name="contributor_task_id" class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                            <option value="">No specific task</option>
                            @foreach($tasks as $task)
                                <option value="{{ $task->id }}" @selected((int) old('contributor_task_id', $observation->contributor_task_id) === $task->id)>{{ $task->title }}</option>
                            @endforeach
                        </select>
                    </div>
                    @include('devices._variant_select', ['variants' => $variants, 'selected' => $observation->device_variant_id])
                </div>
            </div>
            @if($observation->isVerified())
                <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">Saving clears the “Verified hardware claim” badge; a maintainer must re-verify the new text.</p>
            @endif
            <div class="mt-6 flex items-center justify-end gap-3 border-t border-zinc-100 pt-6">
                <a href="{{ route('projects.show', $project) }}#observations" class="text-sm font-semibold text-zinc-600 hover:text-zinc-900">Cancel</a>
                <button type="submit" class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">Save observation</button>
            </div>
        </form>
    </div>
</x-app-layout>
