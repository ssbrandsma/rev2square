@props(['finding', 'evidenceById', 'sourcesById'])
<div class="rounded-xl border border-zinc-200 bg-white p-5">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="font-bold">{{ $finding['statement'] }}</p>
        <span class="rounded-full px-2.5 py-0.5 font-mono text-[11px] font-bold
            @if($finding['status'] === 'SUPPORTED') bg-emerald-100 text-emerald-800
            @elseif($finding['status'] === 'CONTRADICTED') bg-red-100 text-red-800
            @elseif($finding['status'] === 'INFERRED') bg-sky-100 text-sky-800
            @else bg-zinc-100 text-zinc-700 @endif">{{ $finding['status'] }}</span>
    </div>
    @if($finding['limitations'] ?? '')
        <p class="mt-2 text-sm text-zinc-600"><span class="font-semibold">Limits:</span> {{ $finding['limitations'] }}</p>
    @endif
    <ul class="mt-3 space-y-1.5 border-t border-zinc-100 pt-3">
        @foreach($finding['evidence_ids'] ?? [] as $ref)
            @php($item = $evidenceById->get($ref))
            <li class="text-sm">
                <span class="font-mono text-xs font-bold text-zinc-500">{{ $ref }}</span>
                @if($item)
                    <span class="text-zinc-700">“{{ $item['claim'] }}”</span>
                    @php($source = $sourcesById->get($item['source_id']))
                    <span class="text-zinc-500">—
                        @if($source)<a href="{{ $source->url }}" target="_blank" rel="noopener" class="font-mono text-xs hover:text-emerald-700">{{ $item['locator'] }}</a>
                        @else<span class="font-mono text-xs">{{ $item['locator'] }}</span>@endif
                    </span>
                @endif
            </li>
        @endforeach
    </ul>
</div>
