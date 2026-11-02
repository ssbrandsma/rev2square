@props(['project' => null])
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
