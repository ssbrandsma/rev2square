<x-app-layout>
    <x-slot name="header">
        <p class="font-mono text-xs uppercase tracking-widest text-emerald-700">Contribute</p>
        <h1 class="mt-1 text-2xl font-extrabold tracking-tight">{{ $project->title }}</h1>
    </x-slot>
    <div class="mx-auto max-w-2xl px-4 py-10 sm:px-6">
        <form method="POST" action="{{ route('projects.offers.store', $project) }}" class="rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
            @csrf
            <div class="space-y-5">
                <div>
                    <label for="type" class="block text-sm font-semibold">I want to</label>
                    <select id="type" name="type" required class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        @foreach(\App\Enums\OfferType::cases() as $type)
                            <option value="{{ $type->value }}" @selected(old('type') === $type->value)>{{ $type->label() }}</option>
                        @endforeach
                    </select>
                    @error('type')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="contributor_task_id" class="block text-sm font-semibold">Related task <span class="font-normal text-zinc-500">(optional)</span></label>
                    <select id="contributor_task_id" name="contributor_task_id" class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <option value="">General help — no specific task</option>
                        @foreach($tasks as $task)
                            <option value="{{ $task->id }}" @selected((int) old('contributor_task_id', $selectedTaskId) === $task->id)>{{ $task->title }}</option>
                        @endforeach
                    </select>
                    @error('contributor_task_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="message" class="block text-sm font-semibold">Message to the maintainers</label>
                    <textarea id="message" name="message" rows="4" required maxlength="2000" placeholder="Your skills, available hardware, and what you would like to do."
                        class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('message') }}</textarea>
                    <p class="mt-1 text-xs text-zinc-500">Visible to project maintainers only. Do not include private contact details you are unwilling to share.</p>
                    @error('message')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="mt-6 flex items-center justify-end gap-3 border-t border-zinc-100 pt-6">
                <a href="{{ route('projects.show', $project) }}" class="text-sm font-semibold text-zinc-600 hover:text-zinc-900">Cancel</a>
                <button type="submit" class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">Send offer</button>
            </div>
        </form>
    </div>
</x-app-layout>
