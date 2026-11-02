{{-- Translated warning list with expandable diagnostic codes. Params: $warnings (list), $title (e.g. 'Extraction warnings'). --}}
<div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
    <p class="font-semibold">{{ $title }} ({{ count($warnings) }})</p>
    <ul class="mt-1 list-inside list-disc text-sm">
        @foreach($warnings as $warning)
            <li>{{ \App\Support\WarningLabels::label((string) $warning) }}
                <details class="inline text-xs">
                    <summary class="inline cursor-pointer font-mono text-amber-700 hover:underline">Diagnostic code</summary>
                    <code class="font-mono">{{ $warning }}</code>
                </details>
            </li>
        @endforeach
    </ul>
</div>
