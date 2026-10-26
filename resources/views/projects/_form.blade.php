@props(['project' => null])
@php($editing = $project !== null)
<div class="space-y-5">
    <div>
        <label for="title" class="block text-sm font-semibold">Project title</label>
        <input id="title" name="title" type="text" required maxlength="255" value="{{ old('title', $project?->title) }}"
            class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
        @error('title')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="pitch" class="block text-sm font-semibold">Pitch <span class="font-normal text-zinc-500">(one or two sentences)</span></label>
        <input id="pitch" name="pitch" type="text" maxlength="500" value="{{ old('pitch', $project?->pitch) }}"
            class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
        @error('pitch')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="body" class="block text-sm font-semibold">Project story</label>
        <textarea id="body" name="body" rows="8" maxlength="20000"
            class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('body', $project?->body) }}</textarea>
        <p class="mt-1 text-xs text-zinc-500">Blank lines separate paragraphs. State only what you can source; mark the rest as planned.</p>
        @error('body')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="grid gap-5 sm:grid-cols-3">
        <div>
            <label for="phase" class="block text-sm font-semibold">Phase</label>
            <select id="phase" name="phase" required class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                @foreach(\App\Enums\ProjectPhase::cases() as $phase)
                    <option value="{{ $phase->value }}" @selected(old('phase', $project?->phase?->value) === $phase->value)>{{ $phase->label() }}</option>
                @endforeach
            </select>
            @error('phase')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="activity" class="block text-sm font-semibold">Activity</label>
            <select id="activity" name="activity" required class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                @foreach(\App\Enums\ProjectActivity::cases() as $activity)
                    <option value="{{ $activity->value }}" @selected(old('activity', $project?->activity?->value) === $activity->value)>{{ $activity->label() }}</option>
                @endforeach
            </select>
            @error('activity')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="publication_status" class="block text-sm font-semibold">Visibility</label>
            <select id="publication_status" name="publication_status" required class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                @foreach(\App\Enums\PublicationStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(old('publication_status', $project?->publication_status?->value) === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
            @error('publication_status')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
    </div>
</div>
