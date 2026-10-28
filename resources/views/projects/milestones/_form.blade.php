<div class="space-y-5">
    <div>
        <label for="title" class="block text-sm font-semibold">Title</label>
        <input id="title" name="title" type="text" required maxlength="255" value="{{ old('title', $milestone->title ?? '') }}"
            class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
        @error('title')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="description" class="block text-sm font-semibold">Description</label>
        <textarea id="description" name="description" rows="4" maxlength="10000"
            class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('description', $milestone->description ?? '') }}</textarea>
        @error('description')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="grid gap-5 sm:grid-cols-2">
        <div>
            <label for="status" class="block text-sm font-semibold">Status</label>
            <select id="status" name="status" class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                @foreach(\App\Enums\MilestoneStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(old('status', ($milestone->status ?? \App\Enums\MilestoneStatus::Planned)->value) === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
            @error('status')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="target_date" class="block text-sm font-semibold">Target date <span class="font-normal text-zinc-500">(optional)</span></label>
            <input id="target_date" name="target_date" type="date" value="{{ old('target_date', ($milestone->target_date ?? null)?->format('Y-m-d')) }}"
                class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
            @error('target_date')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
    </div>
    <div>
        <label for="sort_order" class="block text-sm font-semibold">Order <span class="font-normal text-zinc-500">(lower appears first)</span></label>
        <input id="sort_order" name="sort_order" type="number" min="0" max="9999" value="{{ old('sort_order', $milestone->sort_order ?? 0) }}"
            class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
        @error('sort_order')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
</div>
