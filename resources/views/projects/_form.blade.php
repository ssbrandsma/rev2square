@props(['project' => null, 'hideBody' => false])
@php($editing = $project !== null)
<div class="space-y-5">
    @include('projects._form-identity', ['project' => $project])
    @unless($hideBody)
    <div>
        <label for="body" class="block text-sm font-semibold">Project story</label>
        <textarea id="body" name="body" rows="8" maxlength="20000"
            class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('body', $project?->body) }}</textarea>
        <p class="mt-1 text-xs text-zinc-500">Blank lines separate paragraphs. State only what you can source; mark the rest as planned.</p>
        @error('body')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    @endunless
    @include('projects._form-details', ['project' => $project])
</div>
