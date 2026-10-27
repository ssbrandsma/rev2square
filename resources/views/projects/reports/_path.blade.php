@props(['path'])
<div class="rounded-xl border border-zinc-200 bg-white p-5">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="font-bold">{{ $path['approach'] }}</p>
        <span class="rounded-full px-2.5 py-0.5 font-mono text-[11px] font-bold
            @if($path['feasibility'] === 'PROMISING') bg-emerald-100 text-emerald-800
            @elseif($path['feasibility'] === 'HIGH_RISK') bg-red-100 text-red-800
            @elseif($path['feasibility'] === 'INSUFFICIENT_EVIDENCE') bg-zinc-200 text-zinc-800
            @else bg-amber-100 text-amber-800 @endif">{{ $path['feasibility'] }}</span>
    </div>
    @if(!empty($path['assumptions']))<p class="mt-2 text-sm text-zinc-600"><span class="font-semibold text-zinc-900">Assumes:</span> {{ implode('; ', $path['assumptions']) }}</p>@endif
    @if(!empty($path['risks']))<p class="mt-1 text-sm text-zinc-600"><span class="font-semibold text-zinc-900">Risks:</span> {{ implode('; ', $path['risks']) }}</p>@endif
    @if(!empty($path['validation_needed']))<p class="mt-1 text-sm text-zinc-600"><span class="font-semibold text-zinc-900">Needed to validate:</span> {{ implode('; ', $path['validation_needed']) }}</p>@endif
</div>
