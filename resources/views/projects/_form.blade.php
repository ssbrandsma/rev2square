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
    @php($linkedDevice = $project?->devices->first())
    @php($linkedVariant = $linkedDevice?->pivot?->device_variant_id ? \App\Models\DeviceVariant::find($linkedDevice->pivot->device_variant_id) : null)
    <fieldset class="rounded-xl border border-zinc-200 p-4">
        <legend class="px-1 text-sm font-semibold">Device to revive</legend>
        <p class="text-xs text-zinc-500">Link the hardware this project revives. Clearing both fields unlinks the device.</p>
        <div class="mt-3 grid gap-5 sm:grid-cols-2">
            <div>
                <label for="device_manufacturer" class="block text-sm font-semibold">Manufacturer</label>
                <input id="device_manufacturer" name="device_manufacturer" type="text" maxlength="255" value="{{ old('device_manufacturer', $linkedDevice?->manufacturer) }}"
                    class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                @error('device_manufacturer')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="device_model" class="block text-sm font-semibold">Model</label>
                <input id="device_model" name="device_model" type="text" maxlength="255" value="{{ old('device_model', $linkedDevice?->model) }}"
                    class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                @error('device_model')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="device_hardware_revision" class="block text-sm font-semibold">Hardware revision <span class="font-normal text-zinc-500">(optional)</span></label>
                <input id="device_hardware_revision" name="device_hardware_revision" type="text" maxlength="255" value="{{ old('device_hardware_revision', $linkedVariant?->hardware_revision) }}"
                    class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                @error('device_hardware_revision')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="device_firmware_version" class="block text-sm font-semibold">Firmware version <span class="font-normal text-zinc-500">(optional)</span></label>
                <input id="device_firmware_version" name="device_firmware_version" type="text" maxlength="255" value="{{ old('device_firmware_version', $linkedVariant?->firmware_version) }}"
                    class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                @error('device_firmware_version')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
        </div>
    </fieldset>
</div>
