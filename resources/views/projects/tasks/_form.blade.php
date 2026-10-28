<div class="space-y-5">
    <div>
        <label for="title" class="block text-sm font-semibold">Title</label>
        <input id="title" name="title" type="text" required maxlength="255" value="{{ old('title', $task->title ?? '') }}"
            class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
        @error('title')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="milestone_id" class="block text-sm font-semibold">Milestone <span class="font-normal text-zinc-500">(optional)</span></label>
        <select id="milestone_id" name="milestone_id" class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
            <option value="">No milestone</option>
            @foreach($milestones as $milestone)
                <option value="{{ $milestone->id }}" @selected((int) old('milestone_id', $task->milestone_id ?? '') === $milestone->id)>{{ $milestone->title }}</option>
            @endforeach
        </select>
        @error('milestone_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="description" class="block text-sm font-semibold">Description</label>
        <textarea id="description" name="description" rows="4" maxlength="10000"
            class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('description', $task->description ?? '') }}</textarea>
        @error('description')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="definition_of_done" class="block text-sm font-semibold">Definition of done</label>
        <textarea id="definition_of_done" name="definition_of_done" rows="3" maxlength="10000" placeholder="What must be true before this task can be verified?"
            class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('definition_of_done', $task->definition_of_done ?? '') }}</textarea>
        @error('definition_of_done')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="skills" class="block text-sm font-semibold">Skills <span class="font-normal text-zinc-500">(comma-separated, optional)</span></label>
        <input id="skills" name="skills" type="text" maxlength="500" value="{{ old('skills', isset($task) ? implode(', ', $task->skillTags()) : '') }}"
            placeholder="soldering, firmware, testing"
            class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
        @error('skills')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="flex items-center gap-2">
        <input id="requires_hardware" name="requires_hardware" type="checkbox" value="1" @checked((bool) old('requires_hardware', $task->requires_hardware ?? false))
            class="rounded border-zinc-300 text-emerald-600 shadow-sm focus:ring-emerald-500">
        <label for="requires_hardware" class="text-sm font-semibold">Requires physical hardware</label>
    </div>
</div>
