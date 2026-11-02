{{-- Status pill for artifact and analysis states. Params: $status (raw), $label (display text). --}}
@php($tone = match ($status) {
    'ready', 'complete', 'verified', 'confirmed' => 'bg-emerald-100 text-emerald-800',
    'failed' => 'bg-red-100 text-red-800',
    'cancelled' => 'bg-zinc-200 text-zinc-600',
    default => 'bg-amber-100 text-amber-900',
})
<span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $tone }}">{{ $label }}</span>
