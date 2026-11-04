@props(['refs' => [], 'targets' => null])
<div data-finding-refs class="space-y-2">
    @foreach(array_values($refs ?? []) as $i => $ref)
        <fieldset data-ref-row class="grid gap-2 rounded-lg border border-zinc-200 p-2 sm:grid-cols-2">
            <label class="block text-xs text-zinc-500">Type
                <select name="refs[{{ $i }}][kind]" data-ref-kind class="mt-1 block w-full rounded-lg border-zinc-300 text-sm">
                    @foreach(['page' => 'Documentation page', 'artifact' => 'Artifact', 'evidence' => 'Repository evidence', 'image' => 'Photo', 'url' => 'Web link'] as $kind => $label)
                        <option value="{{ $kind }}" @selected(($ref['kind'] ?? '') === $kind)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-xs text-zinc-500" data-ref-for="page">Page
                <select name="refs[{{ $i }}][doc_id]" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm">
                    @foreach($targets['pages'] as $target)
                        <option value="{{ $target->id }}" @selected(($ref['doc_id'] ?? null) == $target->id)>{{ $target->title }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-xs text-zinc-500" data-ref-for="page">Section anchor (optional)
                <input type="text" name="refs[{{ $i }}][anchor]" value="{{ $ref['anchor'] ?? '' }}" maxlength="80" placeholder="step-2" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm" />
            </label>
            <label class="block text-xs text-zinc-500" data-ref-for="artifact">Artifact
                <select name="refs[{{ $i }}][artifact_id]" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm">
                    @foreach($targets['artifacts'] as $target)
                        <option value="{{ $target->id }}" @selected(($ref['artifact_id'] ?? null) == $target->id)>{{ $target->original_name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-xs text-zinc-500" data-ref-for="evidence">Evidence file
                <select name="refs[{{ $i }}][source_id]" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm">
                    @foreach($targets['evidence'] as $target)
                        <option value="{{ $target->id }}" @selected(($ref['source_id'] ?? null) == $target->id)>{{ $target->path }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-xs text-zinc-500" data-ref-for="image">Photo
                <select name="refs[{{ $i }}][media_id]" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm">
                    @foreach($targets['images'] as $target)
                        <option value="{{ $target->id }}" @selected(($ref['media_id'] ?? null) == $target->id)>{{ $target->title ?: $target->original_name }}</option>
                    @endforeach
                </select>
            </label>
            <span class="grid grid-cols-2 gap-2" data-ref-for="image">
                <label class="block text-xs text-zinc-500">Marker #
                    <input type="number" name="refs[{{ $i }}][marker]" value="{{ $ref['marker'] ?? '' }}" min="1" max="20" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm" />
                </label>
                <label class="block text-xs text-zinc-500">Placement #
                    <input type="number" name="refs[{{ $i }}][placement]" value="{{ $ref['placement'] ?? '' }}" min="1" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm" />
                </label>
            </span>
            <label class="block text-xs text-zinc-500 sm:col-span-2" data-ref-for="url">URL
                <input type="url" name="refs[{{ $i }}][url]" value="{{ $ref['url'] ?? '' }}" maxlength="2000" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm" />
            </label>
            <label class="flex items-center gap-2 text-xs font-semibold text-red-700">
                <input type="checkbox" name="refs[{{ $i }}][remove]" value="1" class="rounded border-zinc-300" /> Remove this reference
            </label>
        </fieldset>
    @endforeach
    @for($n = 0; $n < 2; $n++)
        @php($i = count($refs ?? []) + $n)
        <fieldset data-ref-row data-ref-blank class="grid gap-2 rounded-lg border border-dashed border-zinc-300 p-2 sm:grid-cols-2">
            <label class="block text-xs text-zinc-500">Add reference
                <select name="refs[{{ $i }}][kind]" data-ref-kind class="mt-1 block w-full rounded-lg border-zinc-300 text-sm">
                    <option value="">— None —</option>
                    <option value="page">Documentation page</option>
                    <option value="artifact">Artifact</option>
                    <option value="evidence">Repository evidence</option>
                    <option value="image">Photo</option>
                    <option value="url">Web link</option>
                </select>
            </label>
            <label class="block text-xs text-zinc-500" data-ref-for="page">Page
                <select name="refs[{{ $i }}][doc_id]" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm">
                    @foreach($targets['pages'] as $target)
                        <option value="{{ $target->id }}">{{ $target->title }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-xs text-zinc-500" data-ref-for="page">Section anchor (optional)
                <input type="text" name="refs[{{ $i }}][anchor]" maxlength="80" placeholder="step-2" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm" />
            </label>
            <label class="block text-xs text-zinc-500" data-ref-for="artifact">Artifact
                <select name="refs[{{ $i }}][artifact_id]" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm">
                    @foreach($targets['artifacts'] as $target)
                        <option value="{{ $target->id }}">{{ $target->original_name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-xs text-zinc-500" data-ref-for="evidence">Evidence file
                <select name="refs[{{ $i }}][source_id]" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm">
                    @foreach($targets['evidence'] as $target)
                        <option value="{{ $target->id }}">{{ $target->path }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-xs text-zinc-500" data-ref-for="image">Photo
                <select name="refs[{{ $i }}][media_id]" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm">
                    @foreach($targets['images'] as $target)
                        <option value="{{ $target->id }}">{{ $target->title ?: $target->original_name }}</option>
                    @endforeach
                </select>
            </label>
            <span class="grid grid-cols-2 gap-2" data-ref-for="image">
                <label class="block text-xs text-zinc-500">Marker #
                    <input type="number" name="refs[{{ $i }}][marker]" min="1" max="20" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm" />
                </label>
                <label class="block text-xs text-zinc-500">Placement #
                    <input type="number" name="refs[{{ $i }}][placement]" min="1" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm" />
                </label>
            </span>
            <label class="block text-xs text-zinc-500 sm:col-span-2" data-ref-for="url">URL
                <input type="url" name="refs[{{ $i }}][url]" maxlength="2000" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm" />
            </label>
        </fieldset>
    @endfor
</div>
