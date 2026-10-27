@props(['experiment'])
<div class="rounded-xl border border-zinc-200 bg-white p-5">
    <p class="font-bold">{{ $experiment['question'] }}</p>
    <p class="mt-1 text-sm italic text-zinc-600">Hypothesis: {{ $experiment['hypothesis'] }}</p>
    <p class="mt-2 font-mono text-[11px] uppercase tracking-wider text-zinc-500">Proposed test — not yet performed</p>
    <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-zinc-700">
        @foreach($experiment['procedure'] ?? [] as $step)<li>{{ $step }}</li>@endforeach
    </ol>
    <div class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
        <p class="rounded-lg bg-emerald-50 p-3"><span class="font-semibold text-emerald-900">Pass:</span> {{ implode('; ', $experiment['pass_criteria'] ?? []) }}</p>
        <p class="rounded-lg bg-red-50 p-3"><span class="font-semibold text-red-900">Fail:</span> {{ implode('; ', $experiment['fail_criteria'] ?? []) }}</p>
    </div>
    @if(!empty($experiment['safety_notes']))
        <p class="mt-2 text-sm text-amber-800"><span class="font-semibold">Safety:</span> {{ implode('; ', $experiment['safety_notes']) }}</p>
    @endif
</div>
