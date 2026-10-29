<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Uploaded file [A{{ $artifact->id }}]</p>
        <h1 class="mt-1 break-words text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">{{ $artifact->original_name }}</h1>
        <p class="mt-1 text-sm text-zinc-600">
            @if($backToConversation)
                <a href="{{ route('projects.agent.show', [$project, $backToConversation]) }}" class="font-semibold text-emerald-700">← Back to conversation</a>
                ·
            @endif
            <a href="{{ route('projects.artifacts.index', $project) }}" class="font-semibold text-emerald-700">All files</a>
        </p>
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
            <h2 class="font-semibold">File details</h2>
            <dl class="mt-2 grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div><dt class="font-semibold text-zinc-500">State</dt><dd>{{ $artifact->stateLabel() }}</dd></div>
                <div><dt class="font-semibold text-zinc-500">Format</dt><dd class="font-mono">{{ $artifact->format }}@if($artifact->arch), {{ $artifact->arch }}@endif</dd></div>
                <div><dt class="font-semibold text-zinc-500">Size</dt><dd>{{ number_format($artifact->byte_size) }} bytes</dd></div>
                <div><dt class="font-semibold text-zinc-500">SHA-256</dt><dd class="break-all font-mono text-xs">{{ $artifact->sha256 }}</dd></div>
                @if($artifact->extractor)
                    <div><dt class="font-semibold text-zinc-500">Extractor</dt><dd class="font-mono">{{ $artifact->extractor }} (schema {{ $artifact->extraction_schema }})</dd></div>
                @endif
                @if($artifact->extracted_at)
                    <div><dt class="font-semibold text-zinc-500">Extracted</dt><dd>{{ $artifact->extracted_at->format('Y-m-d H:i:s') }}</dd></div>
                @endif
            </dl>

            @if($artifact->state === 'failed')
                <p class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ $artifact->failure_message ?? 'Extraction failed.' }}</p>
                <form method="POST" action="{{ route('projects.artifacts.retry', [$project, $artifact]) }}" class="mt-3">
                    @csrf
                    <button type="submit" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50">Retry extraction</button>
                </form>
            @endif

            @if(in_array($artifact->state, ['uploaded', 'queued', 'extracting'], true))
                <p class="mt-3 rounded-lg bg-zinc-100 px-3 py-2 text-sm text-zinc-600">Extraction is still running. Refresh this page shortly.</p>
            @endif

            @if($artifact->state === 'needs_configuration')
                <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">This is a headerless raw image: no processor or addresses could be detected. Configure the target below to analyze it — nothing is assumed.</p>
            @endif

            @if(! empty($artifact->warnings))
                <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    <p class="font-semibold">Extraction warnings ({{ count($artifact->warnings) }})</p>
                    <ul class="mt-1 list-inside list-disc font-mono text-xs">
                        @foreach($artifact->warnings as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>

        @if($snapshot !== null)
            <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                <h2 class="font-semibold">Extraction summary</h2>
                @if($artifact->format === 'elf')
                    @php($tech = $artifact->tech_attributes ?? [])
                    <dl class="mt-2 grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                        <div><dt class="font-semibold text-zinc-500">Class / endian</dt><dd class="font-mono">{{ $tech['class'] ?? '?' }} / {{ $tech['endian'] ?? '?' }}</dd></div>
                        <div><dt class="font-semibold text-zinc-500">Machine / type</dt><dd class="font-mono">{{ $tech['machine'] ?? '?' }} / {{ $tech['object_type'] ?? '?' }}</dd></div>
                        <div><dt class="font-semibold text-zinc-500">Entry point (virtual address)</dt><dd class="font-mono">{{ $tech['entry_point'] ?? '?' }}</dd></div>
                        <div><dt class="font-semibold text-zinc-500">Sections / segments / symbols</dt><dd class="font-mono">{{ $tech['sections'] ?? 0 }} / {{ $tech['segments'] ?? 0 }} / {{ $tech['symbols'] ?? 0 }}</dd></div>
                        @if(! empty($tech['needed']))
                            <div class="sm:col-span-2"><dt class="font-semibold text-zinc-500">Needed libraries</dt><dd class="font-mono text-xs">{{ implode(', ', $tech['needed']) }}</dd></div>
                        @endif
                        <div><dt class="font-semibold text-zinc-500">Strings kept</dt><dd class="font-mono">{{ $tech['strings'] ?? 0 }}</dd></div>
                        <div><dt class="font-semibold text-zinc-500">Endpoint candidates</dt><dd class="font-mono">{{ $tech['endpoints'] ?? 0 }}</dd></div>
                    </dl>
                    <p class="mt-2 text-xs text-zinc-500">Inspection only — no decompilation. Endpoint strings are candidates, not proof the device contacts them. File offsets and virtual addresses are labeled separately in tool output.</p>
                @elseif(in_array($artifact->format, ['pcap', 'pcapng'], true))
                    @php($tech = $artifact->tech_attributes ?? [])
                    <dl class="mt-2 grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                        <div><dt class="font-semibold text-zinc-500">Packets</dt><dd class="font-mono">{{ $tech['packets'] ?? 0 }}</dd></div>
                        <div><dt class="font-semibold text-zinc-500">Interfaces / endpoints</dt><dd class="font-mono">{{ $tech['interfaces'] ?? 0 }} / {{ $tech['endpoints'] ?? 0 }}</dd></div>
                        <div><dt class="font-semibold text-zinc-500">Flows</dt><dd class="font-mono">{{ $tech['flows'] ?? 0 }}</dd></div>
                        <div><dt class="font-semibold text-zinc-500">DNS questions / resolutions</dt><dd class="font-mono">{{ $tech['dns_queries'] ?? 0 }} / {{ $tech['resolutions'] ?? 0 }}</dd></div>
                        @if(isset($tech['tcp']))
                            <div class="sm:col-span-2"><dt class="font-semibold text-zinc-500">TCP</dt><dd class="font-mono text-xs">attempts {{ $tech['tcp']['attempts'] ?? 0 }}, completed {{ $tech['tcp']['completed'] ?? 0 }}, refused {{ $tech['tcp']['refused'] ?? 0 }}, incomplete {{ $tech['tcp']['incomplete'] ?? 0 }}, resets {{ $tech['tcp']['resets'] ?? 0 }}</dd></div>
                        @endif
                        <div><dt class="font-semibold text-zinc-500">HTTP / TLS observations</dt><dd class="font-mono">{{ $tech['http'] ?? 0 }} / {{ $tech['tls'] ?? 0 }}</dd></div>
                    </dl>
                    <p class="mt-2 text-xs text-zinc-500">Metadata only — encrypted payloads are not readable. A DNS request does not prove a connection; repeated SYNs do not establish the failure cause.</p>
                @else
                    @php($tech = $artifact->tech_attributes ?? [])
                    <dl class="mt-2 grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                        @foreach($tech as $key => $value)
                            <div><dt class="font-semibold text-zinc-500">{{ ucfirst(str_replace('_', ' ', $key)) }}</dt><dd class="font-mono text-xs">@if(is_array($value)){{ implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), array_slice($value, 0, 10))) }}@elseif(is_bool($value)){{ $value ? 'yes' : 'no' }}@elseif(is_int($value) && str_contains($key, 'address')){{ sprintf('0x%x', $value) }}@else{{ $value ?? '—' }}@endif</dd></div>
                        @endforeach
                    </dl>
                    <p class="mt-2 text-xs text-zinc-500">Structural metadata only. Local disassembly (where supported) runs as a separate analysis below — never silently, never executed.</p>
                @endif

                @if(! empty($artifact->limits_applied))
                    <details class="mt-3 text-sm">
                        <summary class="cursor-pointer text-xs font-semibold text-zinc-500">Extraction limits applied</summary>
                        <pre class="mt-1 overflow-x-auto rounded-lg bg-zinc-50 p-3 font-mono text-xs text-zinc-700">{{ json_encode($artifact->limits_applied, JSON_PRETTY_PRINT) }}</pre>
                    </details>
                @endif
            </section>

            @if($artifact->format === 'elf')
                <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                    <h2 class="font-semibold">Endpoint candidates ({{ count($details['endpoints']) }})</h2>
                    <p class="mt-1 text-xs text-zinc-500">Candidates only — a string in firmware does not prove the device contacts it. Offsets below are file offsets.</p>
                    <ul class="mt-2 space-y-1 text-sm">
                        @forelse($details['endpoints'] as $endpoint)
                            <li id="offset-{{ $endpoint['offset'] }}" class="scroll-mt-24 break-all font-mono text-xs">
                                <span class="font-semibold">{{ $endpoint['offset'] }}</span>
                                <span class="text-zinc-500">[{{ $endpoint['kind'] }}]</span>
                                {{ $endpoint['value'] }}
                            </li>
                        @empty
                            <li class="text-sm text-zinc-500">No endpoint candidates extracted.</li>
                        @endforelse
                    </ul>
                </section>

                <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                    <h2 class="font-semibold">Strings (showing {{ count($details['strings']) }} of {{ $details['strings_total'] }})</h2>
                    <p class="mt-1 text-xs text-zinc-500">Bounded printable strings with file offsets. Secrets redacted where recognizable.</p>
                    <ul class="mt-2 max-h-96 space-y-1 overflow-y-auto text-sm">
                        @forelse($details['strings'] as $string)
                            <li id="offset-{{ $string['offset'] }}-str" class="scroll-mt-24 break-all font-mono text-xs">
                                <span class="font-semibold">{{ $string['offset'] }}</span> {{ $string['value'] }}
                            </li>
                        @empty
                            <li class="text-sm text-zinc-500">No strings extracted.</li>
                        @endforelse
                    </ul>
                </section>

                <details class="mt-6 rounded-xl border border-zinc-200 bg-white p-6 text-sm">
                    <summary class="cursor-pointer font-semibold">Sections ({{ $details['sections_total'] }}) and symbols ({{ $details['symbols_total'] }})</summary>
                    <h3 class="mt-3 text-xs font-semibold text-zinc-500">Sections</h3>
                    <ul class="mt-1 space-y-1 font-mono text-xs">
                        @forelse($details['sections'] as $section)
                            <li>{{ $section['name'] }} · {{ $section['type'] }} · size {{ $section['size'] }} · file offset {{ $section['file_offset'] }}@if($section['out_of_bounds']) · <span class="font-semibold text-red-700">out of bounds</span>@endif</li>
                        @empty
                            <li class="font-sans text-sm text-zinc-500">No sections recorded.</li>
                        @endforelse
                    </ul>
                    <h3 class="mt-3 text-xs font-semibold text-zinc-500">Symbols (values are virtual addresses)</h3>
                    <ul class="mt-1 space-y-1 font-mono text-xs">
                        @forelse($details['symbols'] as $symbol)
                            <li>{{ $symbol['name'] }} · {{ $symbol['value'] }} (virtual address) · {{ $symbol['binding'] }} · {{ $symbol['section'] }}</li>
                        @empty
                            <li class="font-sans text-sm text-zinc-500">No symbols recorded — normal for stripped binaries.</li>
                        @endforelse
                    </ul>
                </details>
            @elseif(in_array($artifact->format, ['pcap', 'pcapng'], true))
                <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                    <h2 class="font-semibold">HTTP observations ({{ count($details['http']) }})</h2>
                    <ul class="mt-2 space-y-2 text-sm">
                        @forelse($details['http'] as $entry)
                            <li id="packet-{{ $entry['packet'] }}-http" class="scroll-mt-24 rounded-lg bg-zinc-50 px-3 py-2 font-mono text-xs">
                                @if($entry['http_kind'] === 'request')
                                    <span class="font-semibold">{{ $entry['method'] }}</span> {{ $entry['target'] }}
                                    <span class="text-zinc-500">host {{ $entry['host'] ?? '(absent)' }}</span>
                                @else
                                    <span class="font-semibold">status {{ $entry['status'] }}</span>
                                @endif
                                <span class="text-zinc-500">· packet {{ $entry['packet'] }} · <a href="#packet-{{ $entry['packet'] }}" class="font-semibold text-emerald-700 hover:underline">packet {{ $entry['packet'] }}</a></span>
                            </li>
                        @empty
                            <li class="text-sm text-zinc-500">No visible HTTP metadata extracted.</li>
                        @endforelse
                    </ul>
                    @if(! empty($details['tls']))
                        <h3 class="mt-4 text-xs font-semibold text-zinc-500">TLS handshakes ({{ count($details['tls']) }})</h3>
                        <ul class="mt-1 space-y-1 font-mono text-xs">
                            @foreach($details['tls'] as $tls)
                                <li>SNI {{ $tls['sni'] ?? '(absent)' }}@if(! empty($tls['alpn'])) · ALPN {{ implode(',', $tls['alpn']) }}@endif · packet {{ $tls['packet'] ?? '?' }}</li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                    <h2 class="font-semibold">Packets (showing {{ count($details['packets']) }} of {{ $details['packets_total'] }})</h2>
                    <ul class="mt-2 max-h-96 space-y-1 overflow-y-auto font-mono text-xs">
                        @foreach($details['packets'] as $packet)
                            <li id="packet-{{ $packet['n'] }}" class="scroll-mt-24">
                                <span class="font-semibold">#{{ $packet['n'] }}</span>
                                {{ $packet['src'] ?? '?' }}:{{ $packet['sport'] ?? '?' }} → {{ $packet['dst'] ?? '?' }}:{{ $packet['dport'] ?? '?' }}
                                {{ $packet['proto'] ?? '' }}
                                @if($packet['http']) · HTTP {{ $packet['http'] }}@endif
                                @if($packet['tls']) · TLS {{ $packet['tls'] }}@endif
                                @if($packet['note']) · {{ $packet['note'] }}@endif
                            </li>
                        @endforeach
                    </ul>
                </section>

                <details class="mt-6 rounded-xl border border-zinc-200 bg-white p-6 text-sm">
                    <summary class="cursor-pointer font-semibold">Flows ({{ $details['flows_total'] }}), DNS ({{ $details['dns_queries_total'] }}), resolutions ({{ $details['resolutions_total'] }})</summary>
                    <ul class="mt-2 space-y-1 font-mono text-xs">
                        @foreach($details['flows'] as $flow)
                            <li>
                                {{ $flow['proto'] }} {{ $flow['endpoint_1'] }} + {{ $flow['endpoint_2'] }} (unordered)
                                · {{ $flow['outcome'] }} · packets {{ $flow['first_packet'] }}–{{ $flow['last_packet'] }}
                                @if($flow['initiator']) · initiator {{ $flow['initiator'] }}@endif
                            </li>
                        @endforeach
                    </ul>
                    <h3 class="mt-3 text-xs font-semibold text-zinc-500">DNS questions</h3>
                    <ul class="mt-1 space-y-1 font-mono text-xs">
                        @forelse($details['dns_queries'] as $dns)
                            <li>{{ $dns['name'] ?? '?' }} ({{ $dns['type'] ?? '?' }}) · packet {{ $dns['packet'] ?? '?' }}</li>
                        @empty
                            <li class="font-sans text-sm text-zinc-500">None recorded.</li>
                        @endforelse
                    </ul>
                    <h3 class="mt-3 text-xs font-semibold text-zinc-500">Resolutions</h3>
                    <ul class="mt-1 space-y-1 font-mono text-xs">
                        @forelse($details['resolutions'] as $resolution)
                            <li>{{ $resolution['name'] ?? '?' }} → {{ implode(',', array_slice($resolution['addresses'] ?? [], 0, 5)) }}</li>
                        @empty
                            <li class="font-sans text-sm text-zinc-500">None recorded.</li>
                        @endforelse
                    </ul>
                </details>
            @else
                @if(! empty($snapshot['detection']))
                    <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                        <h2 class="font-semibold">Detection evidence</h2>
                        <p class="mt-1 text-xs text-zinc-500">Why this file was classified as {{ $artifact->format }} — evidence, not a confidence percentage.</p>
                        <ul class="mt-2 space-y-1 text-sm">
                            @foreach($snapshot['detection'] as $item)
                                <li class="font-mono text-xs"><span class="font-semibold">{{ $item['claim'] ?? '?' }}:</span> {{ $item['detail'] ?? '' }}</li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @foreach($details['panels'] ?? [] as $panel)
                    <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                        <h2 class="font-semibold">{{ $panel['title'] }}</h2>
                        @if(! empty($panel['names']))
                            <ul class="mt-2 max-h-96 space-y-1 overflow-y-auto font-mono text-xs">
                                @foreach($panel['names'] as $name)
                                    <li class="break-all">{{ $name }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if(! empty($panel['rows']))
                            <ul class="mt-2 max-h-96 space-y-1 overflow-y-auto font-mono text-xs">
                                @foreach($panel['rows'] as $row)
                                    <li class="break-all">{{ implode(' · ', array_map(fn ($k, $v) => $k.' '.(is_scalar($v) ? $v : json_encode($v)), array_keys($row), $row)) }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if(! empty($panel['imports']))
                            <ul class="mt-2 max-h-96 space-y-2 overflow-y-auto text-sm">
                                @foreach($panel['imports'] as $module)
                                    <li>
                                        <p class="font-mono text-xs font-semibold">{{ $module['dll'] }} ({{ $module['function_count'] }})</p>
                                        <p class="break-all font-mono text-xs text-zinc-600">{{ implode(', ', $module['functions']) }}</p>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endforeach

                @if(! empty($details['strings']))
                    <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                        <h2 class="font-semibold">Strings (showing {{ count($details['strings']) }} of {{ $details['strings_total'] }})</h2>
                        <p class="mt-1 text-xs text-zinc-500">Bounded printable strings with file offsets. Secrets redacted where recognizable.</p>
                        <ul class="mt-2 max-h-96 space-y-1 overflow-y-auto text-sm">
                            @foreach($details['strings'] as $string)
                                <li id="offset-{{ $string['offset'] }}-str" class="scroll-mt-24 break-all font-mono text-xs">
                                    <span class="font-semibold">{{ $string['offset'] }}</span> {{ $string['value'] }}
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            @endif
        @endif

        @if(! in_array($artifact->format, ['pcap', 'pcapng'], true))
            <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                <h2 class="font-semibold">Analysis capabilities</h2>
                <p class="mt-1 text-xs text-zinc-500">Independent levels per backend and target — detection passing says nothing about disassembly.</p>
                <div class="mt-2 space-y-3">
                    @forelse($capabilities as $row)
                        <div class="rounded-lg border border-zinc-200 p-3 text-sm">
                            <p class="font-semibold">{{ $row['adapter_name'] }}@if($row['target_name']) · {{ $row['target_name'] }} <span class="font-mono text-xs">({{ $row['target'] }})</span>@endif
                                @if($row['unverified_backend_id'])<span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 font-mono text-xs text-amber-900">unverified {{ $row['unverified_backend_id'] }}</span>@endif
                            </p>
                            <p class="mt-0.5 font-mono text-xs text-zinc-500">{{ $row['target_source'] }}: {{ $row['note'] }}</p>
                            @if(! $row['available'])
                                <p class="mt-1 text-xs text-amber-800">{{ $row['availability_reason'] }}</p>
                            @endif
                            <dl class="mt-1 grid grid-cols-2 gap-x-4 gap-y-0.5 font-mono text-xs sm:grid-cols-3">
                                @foreach($row['capabilities'] as $cap => $level)
                                    <div><dt class="inline text-zinc-500">{{ $cap }}:</dt> <dd class="inline font-semibold @if($level === 'supported')text-emerald-700 @elseif($level === 'partial')text-sky-700 @elseif($level === 'unsupported')text-zinc-400 @else text-amber-700 @endif">{{ $level }}</dd></div>
                                @endforeach
                            </dl>
                        </div>
                    @empty
                        <p class="text-sm text-zinc-500">No backend covers this format.</p>
                    @endforelse
                </div>
            </section>

            <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                <h2 class="font-semibold">Request analysis</h2>
                @if(! empty($candidates))
                    <p class="mt-1 text-xs text-zinc-500">Structural candidates: @foreach($candidates as $candidate)<span class="font-mono">{{ $candidate['id'] }}</span> ({{ $candidate['source'] }})@if(!$loop->last), @endif @endforeach. Omit the target to use the structural one.</p>
                @else
                    <p class="mt-1 text-xs text-zinc-500">No structural target — select the exact processor. Raw images also need a base address.</p>
                @endif
                <form method="POST" action="{{ route('projects.artifacts.analyses.store', [$project, $artifact]) }}" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @csrf
                    <label class="block text-sm">Target
                        <select name="target" class="mt-1 block w-full rounded-lg border border-zinc-300 px-2 py-1.5 text-sm">
                            <option value="">— structural default —</option>
                            @foreach($targets as $id => $info)
                                <option value="{{ $id }}" @selected(old('target') === $id)>{{ $info['name'] }} ({{ $id }})</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm">Base address <span class="text-zinc-500">(raw images, e.g. 0x8000)</span>
                        <input name="base_address" value="{{ old('base_address') }}" placeholder="0x8000" class="mt-1 block w-full rounded-lg border border-zinc-300 px-2 py-1.5 font-mono text-sm" />
                    </label>
                    <label class="block text-sm">Entry <span class="text-zinc-500">(optional, e.g. 0x8000)</span>
                        <input name="entry" value="{{ old('entry') }}" placeholder="0x8000" class="mt-1 block w-full rounded-lg border border-zinc-300 px-2 py-1.5 font-mono text-sm" />
                    </label>
                    <label class="block text-sm">Platform context
                        <select name="platform" class="mt-1 block w-full rounded-lg border border-zinc-300 px-2 py-1.5 text-sm">
                            <option value="">— none —</option>
                            <option value="generic" @selected(old('platform') === 'generic')>generic</option>
                            <option value="c64" @selected(old('platform') === 'c64')>Commodore 64</option>
                        </select>
                    </label>
                    <div class="sm:col-span-2">
                        <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Queue analysis</button>
                    </div>
                </form>
            </section>

            <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                <h2 class="font-semibold">Analysis runs ({{ $analyses->count() }})</h2>
                @if($analyses->isEmpty())
                    <p class="mt-2 text-sm text-zinc-500">No runs yet.</p>
                @else
                    <ul class="mt-2 space-y-2 text-sm">
                        @foreach($analyses as $run)
                            <li class="flex flex-wrap items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2">
                                <a href="{{ route('projects.artifacts.analyses.show', [$project, $artifact, $run]) }}" class="font-mono font-semibold text-emerald-700 hover:underline">#{{ $run->id }} {{ $run->adapter }}/{{ $run->adapter_version }} {{ $run->target }}</a>
                                <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-semibold">{{ $run->statusLabel() }}</span>
                                @if($run->progress_note)<span class="text-xs text-zinc-500">{{ $run->progress_note }}</span>@endif
                                @if($run->error_message)<span class="text-xs text-red-700">{{ $run->error_message }}</span>@endif
                                <span class="ml-auto flex gap-2">
                                    @if(! $run->isTerminal())
                                        <form method="POST" action="{{ route('projects.artifacts.analyses.cancel', [$project, $artifact, $run]) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-xs font-semibold text-zinc-600 hover:text-zinc-900">Cancel</button>
                                        </form>
                                    @endif
                                    @if($run->isRetryable())
                                        <form method="POST" action="{{ route('projects.artifacts.analyses.retry', [$project, $artifact, $run]) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-xs font-semibold text-emerald-700 hover:text-emerald-600">Retry</button>
                                        </form>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
            <h2 class="font-semibold">What the AI provider receives</h2>
            <p class="mt-1 max-w-[70ch] text-sm text-zinc-600">{{ $disclosure }}</p>
            <p class="mt-1 text-xs text-zinc-500">Raw bytes never leave private project storage. Transmission happens only inside an investigation you confirm.</p>
        </section>

        @can('update', $project)
            <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                <h2 class="font-semibold">Danger zone</h2>
                <p class="mt-1 text-sm text-zinc-600">Deleting removes the stored file and its extraction. Historical citations keep their recorded metadata but no longer resolve. Retention guideline: {{ config('artifacts.retention_days') }} days; deletion is always explicit, never automatic.</p>
                <form method="POST" action="{{ route('projects.artifacts.destroy', [$project, $artifact]) }}" class="mt-3" onsubmit="return confirm('Delete this file and its extraction?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">Delete artifact</button>
                </form>
            </section>
        @endcan
    </div>
</x-app-layout>
