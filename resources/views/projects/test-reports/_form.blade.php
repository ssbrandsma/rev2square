<div class="space-y-5">
    <div>
        <label for="title" class="block text-sm font-semibold">Title</label>
        <input id="title" name="title" type="text" required maxlength="255" value="{{ old('title', $report->title ?? '') }}"
            placeholder="e.g. Serial console on the main board"
            class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
        @error('title')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <span class="block text-sm font-semibold">Outcome</span>
        <div class="mt-2 flex flex-wrap gap-4">
            @foreach(\App\Enums\TestOutcome::cases() as $outcome)
                <label class="flex items-center gap-2 text-sm">
                    <input type="radio" name="outcome" value="{{ $outcome->value }}" required
                        @checked(old('outcome', ($report->outcome ?? null)?->value) === $outcome->value)
                        class="border-zinc-300 text-emerald-600 shadow-sm focus:ring-emerald-500">
                    {{ $outcome->label() }}
                </label>
            @endforeach
        </div>
        @error('outcome')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="body" class="block text-sm font-semibold">What did you test, step by step?</label>
        <textarea id="body" name="body" rows="6" required maxlength="10000" placeholder="Setup, steps, and what you observed. Write so someone else can reproduce it."
            class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('body', $report->body ?? '') }}</textarea>
        @error('body')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="environment" class="block text-sm font-semibold">Environment <span class="font-normal text-zinc-500">(optional — OS, tools, wiring, conditions)</span></label>
        <textarea id="environment" name="environment" rows="2" maxlength="2000" placeholder="e.g. Ubuntu 24.04, CH340 adapter at 115200, room temperature"
            class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('environment', $report->environment ?? '') }}</textarea>
        @error('environment')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label for="contributor_task_id" class="block text-sm font-semibold">Related task <span class="font-normal text-zinc-500">(optional)</span></label>
            <select id="contributor_task_id" name="contributor_task_id" class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">No specific task</option>
                @foreach($tasks as $task)
                    <option value="{{ $task->id }}" @selected((int) old('contributor_task_id', $report->contributor_task_id ?? '') === $task->id)>{{ $task->title }}</option>
                @endforeach
            </select>
        </div>
        @include('devices._variant_select', ['variants' => $variants, 'selected' => $report->device_variant_id ?? null])
    </div>
</div>
