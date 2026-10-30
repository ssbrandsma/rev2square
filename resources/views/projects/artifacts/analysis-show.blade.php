<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Analysis run #{{ $analysis->id }} · [A{{ $artifact->id }}]</p>
        <h1 class="mt-1 break-words text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">{{ $artifact->original_name }}</h1>
        <p class="mt-1 text-sm text-zinc-600"><a href="{{ route('projects.artifacts.show', [$project, $artifact]) }}" class="font-semibold text-emerald-700">← Back to file</a></p>
    </x-slot>
    @include('projects._nav', ['project' => $project, 'active' => 'agent'])
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        @if(session('status'))
            <p class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</p>
        @endif
        @if(session('error'))
            <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ session('error') }}</p>
        @endif

        <section class="rounded-xl border border-zinc-200 bg-white p-6">
            <h2 class="font-semibold">Run provenance</h2>
            <dl class="mt-2 grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div><dt class="font-semibold text-zinc-500">Status</dt><dd>{{ $analysis->statusLabel() }}@if($analysis->progress_note) · {{ $analysis->progress_note }}@endif</dd></div>
                <div><dt class="font-semibold text-zinc-500">Backend</dt><dd class="font-mono">{{ $analysis->adapter }}/{{ $analysis->adapter_version ?? '?' }}</dd></div>
                <div><dt class="font-semibold text-zinc-500">Target</dt><dd class="font-mono">{{ $analysis->target ?? '—' }}</dd></div>
                <div><dt class="font-semibold text-zinc-500">Artifact SHA-256</dt><dd class="break-all font-mono text-xs">{{ $artifact->sha256 }}</dd></div>
                @if($analysis->config)<div class="sm:col-span-2"><dt class="font-semibold text-zinc-500">Resolved configuration</dt><dd class="font-mono text-xs">{{ json_encode($analysis->config) }}</dd></div>@endif
                @if(! empty($analysis->overrides))<div class="sm:col-span-2"><dt class="font-semibold text-zinc-500">Manual overrides</dt><dd class="font-mono text-xs">{{ json_encode($analysis->overrides) }}</dd></div>@endif
            </dl>
            @if($analysis->error_message)
                <p class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ $analysis->error_message }}</p>
            @endif
            <div class="mt-3 flex gap-2">
                @if(! $analysis->isTerminal())
                    <form method="POST" action="{{ route('projects.artifacts.analyses.cancel', [$project, $artifact, $analysis]) }}">
                        @csrf
                        <button type="submit" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50">Cancel run</button>
                    </form>
                @endif
                @if($analysis->isRetryable())
                    <form method="POST" action="{{ route('projects.artifacts.analyses.retry', [$project, $artifact, $analysis]) }}">
                        @csrf
                        <button type="submit" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50">Retry run</button>
                    </form>
                @endif
            </div>
        </section>

        @if($evidence !== null)
            <p class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">Reconstructed code; not the original source. Names, types and control flow may be incomplete or incorrect.</p>

            @if(! empty($evidence['warnings']))
                <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    <p class="font-semibold">Analysis warnings ({{ count($evidence['warnings']) }})</p>
                    <ul class="mt-1 list-inside list-disc font-mono text-xs">
                        @foreach($evidence['warnings'] as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                <h2 class="font-semibold">Memory map ({{ count($evidence['segments'] ?? []) }} segments)</h2>
                <p class="mt-1 text-xs text-zinc-500">File offsets locate bytes in the upload; base addresses locate them at runtime. Both are shown — never interchange them.</p>
                <ul class="mt-2 space-y-1 font-mono text-xs">
                    @foreach($evidence['segments'] ?? [] as $segment)
                        <li>{{ $segment['name'] }} ({{ $segment['kind'] }}) · base @if(($segment['base_address'] ?? null) !== null){{ sprintf('0x%x', $segment['base_address']) }}@else—@endif · {{ $segment['length'] }} bytes @if(($segment['file_offset'] ?? null) !== null)· file offset {{ sprintf('0x%x', $segment['file_offset']) }}@else· no file offset (rebuilt from records)@endif</li>
                    @endforeach
                </ul>
            </section>

            <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                <h2 class="font-semibold">Functions ({{ count($evidence['functions'] ?? []) }})</h2>
                <p class="mt-1 text-xs text-zinc-500">Sizes are tentative. Sources: entry, symbol, SYS target, call target.</p>
                <ul class="mt-2 max-h-96 space-y-1 overflow-y-auto font-mono text-xs">
                    @forelse($evidence['functions'] ?? [] as $function)
                        <li id="fn-{{ $function['id'] }}" class="scroll-mt-24">
                            <a href="#addr-{{ dechex($function['address']) }}" class="font-semibold text-emerald-700 hover:underline">{{ $function['id'] }}</a>
                            {{ sprintf('0x%x', $function['address']) }}
                            @if($function['name']){{ $function['name'] }}@endif
                            <span class="text-zinc-500">[{{ $function['source'] }}@if($function['size'] !== null), ~{{ $function['size'] }} bytes @endif]</span>
                        </li>
                    @empty
                        <li class="font-sans text-sm text-zinc-500">No functions discovered.</li>
                    @endforelse
                </ul>
            </section>

            @foreach($evidence['disassembly']['windows'] ?? [] as $window)
                <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                    <h2 class="font-semibold">Disassembly: {{ $window['segment'] ?? $window['name'] ?? 'output' }}</h2>
                    <p class="mt-1 text-xs text-zinc-500">Base @if(($window['base_address'] ?? null) !== null){{ sprintf('0x%x', $window['base_address']) }}@else—@endif @if(($window['file_offset'] ?? null) !== null)· file offset {{ sprintf('0x%x', $window['file_offset']) }}@endif @if($window['truncated'])· <span class="font-semibold text-amber-700">truncated by output budget</span>@endif</p>
                    <div class="mt-2 max-h-[32rem] overflow-auto rounded-lg bg-zinc-950 p-3">
                        <table class="w-full border-collapse font-mono text-xs text-zinc-200">
                            <thead><tr class="text-left text-zinc-500"><th class="pr-3 font-semibold">Address</th><th class="pr-3 font-semibold">File&nbsp;off</th><th class="pr-3 font-semibold">Bytes</th><th class="font-semibold">Instruction</th></tr></thead>
                            <tbody>
                                @foreach($window['lines'] as $line)
                                    <tr id="addr-{{ dechex($line['address']) }}" class="scroll-mt-24 border-t border-zinc-800">
                                        <td class="py-0.5 pr-3 text-emerald-400">{{ sprintf('0x%x', $line['address']) }}</td>
                                        <td class="py-0.5 pr-3 text-zinc-500">@if(($line['offset'] ?? null) !== null){{ sprintf('0x%x', $line['offset']) }}@else—@endif</td>
                                        <td class="py-0.5 pr-3 text-zinc-400">{{ $line['bytes'] }}</td>
                                        <td class="py-0.5">{{ $line['text'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach

            @if(! empty($evidence['decompilation']['functions'] ?? []))
                <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                    <h2 class="font-semibold">Decompilation ({{ count($evidence['decompilation']['functions']) }} functions)@if($evidence['decompilation']['truncated'] ?? false) · <span class="font-semibold text-amber-700">truncated by output budget</span>@endif</h2>
                    <p class="mt-1 text-xs text-zinc-500">Tool-generated pseudocode — tentative, possibly wrong. Never original source.</p>
                    @foreach(array_slice($evidence['decompilation']['functions'], 0, 20) as $fn)
                        <details class="mt-2 rounded-lg border border-zinc-200">
                            <summary class="cursor-pointer px-3 py-2 font-mono text-xs font-semibold">{{ $fn['name'] }} · {{ sprintf('0x%x', $fn['address']) }}</summary>
                            <pre class="overflow-auto border-t border-zinc-200 bg-zinc-950 p-3 font-mono text-xs text-zinc-200">@if(isset($fn['c'])){{ $fn['c'] }}@else<span class="text-amber-400">No pseudocode: {{ $fn['error'] ?? 'unknown' }}</span>@endif</pre>
                        </details>
                    @endforeach
                </section>
            @endif

            @if(! empty($evidence['reconstruction']['files'] ?? []))
                <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                    <h2 class="font-semibold">Reconstruction ({{ count($evidence['reconstruction']['files']) }} files)@if($evidence['reconstruction']['truncated'] ?? false) · <span class="font-semibold text-amber-700">truncated by output budget</span>@endif</h2>
                    <p class="mt-1 text-xs text-zinc-500">Java/C#-like reconstruction — never original source. Inline content only for small files.</p>
                    <ul class="mt-2 max-h-96 space-y-1 overflow-y-auto font-mono text-xs">
                        @foreach(array_slice($evidence['reconstruction']['files'], 0, 200) as $file)
                            <li>{{ $file['path'] }} <span class="text-zinc-500">· {{ number_format($file['size']) }} bytes</span></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if(! empty($evidence['extraction']['files'] ?? []))
                <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                    <h2 class="font-semibold">Extracted filesystem ({{ count($evidence['extraction']['files']) }} files, profile {{ $evidence['extraction']['profile'] ?? '?' }})@if($evidence['extraction']['truncated'] ?? false) · <span class="font-semibold text-amber-700">truncated by output budget</span>@endif</h2>
                    @if(! empty($evidence['extraction']['promoted'] ?? []))
                        <p class="mt-1 text-xs text-zinc-500">Promoted to child artifacts for CPU analysis:</p>
                        <ul class="mt-1 space-y-1 font-mono text-xs">
                            @foreach($evidence['extraction']['promoted'] as $promoted)
                                <li>{{ $promoted['path'] }} → <a href="{{ route('projects.artifacts.show', [$project, $promoted['artifact_id']]) }}" class="font-semibold text-emerald-700 hover:underline">artifact #{{ $promoted['artifact_id'] }}</a></li>
                            @endforeach
                        </ul>
                    @endif
                    <ul class="mt-2 max-h-96 space-y-1 overflow-y-auto font-mono text-xs">
                        @foreach(array_slice($evidence['extraction']['files'], 0, 200) as $file)
                            <li>{{ $file['path'] }} <span class="text-zinc-500">· {{ number_format($file['size']) }} bytes</span></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if(! empty($evidence['vectors']))
                <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                    <h2 class="font-semibold">Candidate vectors</h2>
                    <ul class="mt-2 space-y-1 font-mono text-xs">
                        @foreach($evidence['vectors'] as $vector)
                            <li>{{ sprintf('0x%x', $vector['address']) }} · {{ $vector['kind'] }}@if($vector['points_to'] !== null) → {{ sprintf('0x%x', $vector['points_to']) }}@endif <span class="text-zinc-500">({{ $vector['note'] }})</span></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if(! empty($evidence['io_notes']))
                <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                    <h2 class="font-semibold">Candidate I/O notes ({{ count($evidence['io_notes']) }})</h2>
                    <ul class="mt-2 max-h-64 space-y-1 overflow-y-auto font-mono text-xs">
                        @foreach($evidence['io_notes'] as $note)
                            <li><a href="#addr-{{ dechex($note['address']) }}" class="font-semibold text-emerald-700 hover:underline">{{ sprintf('0x%x', $note['address']) }}</a> {{ $note['line'] }} <span class="text-zinc-500">({{ $note['note'] }})</span></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <details class="mt-6 rounded-xl border border-zinc-200 bg-white p-6 text-sm">
                <summary class="cursor-pointer font-semibold">Cross-references ({{ count($evidence['xrefs'] ?? []) }}) and call graph</summary>
                <ul class="mt-2 max-h-64 space-y-1 overflow-y-auto font-mono text-xs">
                    @forelse($evidence['xrefs'] ?? [] as $xref)
                        <li>{{ sprintf('0x%x', $xref['from']) }} → {{ sprintf('0x%x', $xref['to']) }} [{{ $xref['kind'] }}]</li>
                    @empty
                        <li class="font-sans text-sm text-zinc-500">No references recorded.</li>
                    @endforelse
                </ul>
            </details>
        @elseif(! $analysis->isTerminal())
            <p class="mt-6 rounded-xl bg-zinc-100 px-4 py-3 text-sm text-zinc-600">This run is {{ $analysis->statusLabel() }}. Refresh shortly — evidence appears here when it finishes.</p>
        @endif
    </div>
</x-app-layout>
