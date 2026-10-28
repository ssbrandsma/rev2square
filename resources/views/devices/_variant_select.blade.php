@php($selected = $selected ?? null)
@php($fieldName = $fieldName ?? 'device_variant_id')
@php($fieldId = $fieldId ?? 'device_variant_id')
<div>
    <label for="{{ $fieldId }}" class="block text-sm font-semibold">Device variant <span class="font-normal text-zinc-500">(optional — which hardware this covers)</span></label>
    <select id="{{ $fieldId }}" name="{{ $fieldName }}" class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
        <option value="">Applies to the device in general</option>
        @foreach($variants as $variant)
            <option value="{{ $variant->id }}" @selected((int) old($fieldName, $selected) === $variant->id)>{{ $variant->displayLabel() }}</option>
        @endforeach
    </select>
    @if($variants->isEmpty())
        <p class="mt-1 text-xs text-zinc-500">No variants registered yet; maintainers can add them when editing the device.</p>
    @endif
    @error($fieldName)<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
</div>
