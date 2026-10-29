<div @if($repository?->isImporting() || ($runsActive ?? false)) wire:poll.3s @endif>
    <form wire:submit="import" class="rounded-xl border border-zinc-200 bg-white p-6">
        <label for="repo-url" class="block text-sm font-semibold">Public GitHub repository URL</label>
        <div class="mt-2 flex flex-col gap-3 sm:flex-row">
            <input id="repo-url" type="url" wire:model="url" placeholder="https://github.com/owner/repository" required maxlength="500"
                class="block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
            <button type="submit" wire:loading.attr="disabled" @disabled($repository?->isImporting()) class="shrink-0 rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50">
                <span wire:loading.remove>{{ $repository ? 'Re-import' : 'Import' }}</span>
                <span wire:loading>Starting…</span>
            </button>
        </div>
        @if($repository?->isImporting())
            <p class="mt-2 text-xs text-amber-800">An import for {{ $repository->canonical_url }} is already running (started {{ $repository->import_started_at?->diffForHumans() ?? 'unknown' }}). Re-import unlocks when it finishes.</p>
        @endif
        @error('url')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
        <p class="mt-2 text-xs text-zinc-500">Public repositories only. Every file is inventoried; the most relevant source, docs, and configs become AI evidence within strict budgets. Oversized files contribute marked excerpts; nothing is executed.</p>
    </form>

    @if($repository)
        <div class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-zinc-500">Tracked repository</p>
                    <p class="mt-1 font-bold"><a href="{{ $repository->canonical_url }}" target="_blank" rel="noopener" class="hover:text-emerald-700">{{ $repository->canonical_url }}</a></p>
                </div>
                <span class="rounded-full px-3 py-1 text-xs font-semibold
                    @if($repository->isReady()) bg-emerald-100 text-emerald-800
                    @elseif($repository->isImporting()) bg-amber-100 text-amber-800
                    @elseif($repository->import_status === 'failed') bg-red-100 text-red-800
                    @else bg-zinc-100 text-zinc-700 @endif">
                    {{ ucfirst($repository->import_status) }}
                </span>
            </div>

            @if($repository->isImporting())
                <p class="mt-4 text-sm text-zinc-600" wire:loading.remove role="status">Import running in the background — this view refreshes automatically.</p>
                <p class="mt-2 text-xs text-zinc-500">Stage: {{ $repository->import_stage ? ucfirst($repository->import_stage) : 'starting' }} · Started {{ $repository->import_started_at?->diffForHumans() ?? 'unknown' }} · Last activity {{ $repository->updated_at->diffForHumans() }} · Next retry {{ $repository->next_retry_at?->diffForHumans() ?? 'unknown' }}</p>
            @endif

            @if($repository->import_error)
                <p class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="status">{{ $repository->import_error }}</p>
            @endif

            @if($repository->last_commit_sha)
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-4">
                    <div><dt class="text-xs font-semibold text-zinc-500">Commit</dt><dd class="mt-0.5 font-mono text-xs">{{ substr($repository->last_commit_sha, 0, 12) }}</dd></div>
                    <div><dt class="text-xs font-semibold text-zinc-500">Branch</dt><dd class="mt-0.5">{{ $repository->default_branch }}</dd></div>
                    <div><dt class="text-xs font-semibold text-zinc-500">Files</dt><dd class="mt-0.5">{{ $evidence->count() }}</dd></div>
                    <div><dt class="text-xs font-semibold text-zinc-500">Synced</dt><dd class="mt-0.5">{{ $repository->last_synced_at?->diffForHumans() }}</dd></div>
                </dl>
                @if(!empty($repository->last_import_summary['inventory'] ?? []))
                    @php($inv = $repository->last_import_summary['inventory'])
                    <dl class="mt-3 grid gap-3 border-t border-zinc-100 pt-3 text-sm sm:grid-cols-5">
                        <div><dt class="text-xs font-semibold text-zinc-500">Discovered</dt><dd class="mt-0.5">{{ number_format($inv['blobs'] ?? 0) }}</dd></div>
                        <div><dt class="text-xs font-semibold text-zinc-500">Eligible</dt><dd class="mt-0.5">{{ number_format($inv['eligible'] ?? 0) }}</dd></div>
                        <div><dt class="text-xs font-semibold text-zinc-500">Downloaded</dt><dd class="mt-0.5">{{ number_format($inv['downloaded'] ?? 0) }}</dd></div>
                        <div><dt class="text-xs font-semibold text-zinc-500">Stored</dt><dd class="mt-0.5">{{ number_format($inv['stored'] ?? 0) }}</dd></div>
                        <div><dt class="text-xs font-semibold text-zinc-500">Partial</dt><dd class="mt-0.5">{{ number_format($inv['partial'] ?? 0) }}</dd></div>
                    </dl>
                    @if(!empty($repository->last_import_summary['coverage'] ?? []))
                        <p class="mt-2 text-xs text-zinc-600">Subsystem coverage:
                            @foreach($repository->last_import_summary['coverage'] as $subsystem => $count)<span class="mr-2 whitespace-nowrap font-mono">{{ $subsystem }} ({{ $count }})</span>@endforeach
                        </p>
                    @endif
                @endif
            @endif
        </div>

        @if($evidence->isNotEmpty())
            <div class="mt-6 overflow-x-auto rounded-xl border border-zinc-200">
                <table class="min-w-full divide-y divide-zinc-200 text-sm">
                    <thead class="bg-zinc-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-700">Source</th>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-700">Path</th>
                            <th class="hidden px-4 py-3 text-left font-semibold text-zinc-700 sm:table-cell">Size</th>
                            <th class="hidden px-4 py-3 text-left font-semibold text-zinc-700 lg:table-cell">Coverage</th>
                            <th class="hidden px-4 py-3 text-left font-semibold text-zinc-700 md:table-cell">SHA-256</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 bg-white">
                        @foreach($evidence as $source)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-2.5 font-mono text-xs font-bold"><a href="{{ route('projects.evidence.show', [$project, $source->id]) }}" class="hover:text-emerald-700" title="Open stored content">S{{ $source->id }}</a></td>
                                <td class="px-4 py-2.5"><a href="{{ $source->url }}" target="_blank" rel="noopener" class="font-mono text-xs hover:text-emerald-700">{{ $source->path }}</a></td>
                                <td class="hidden whitespace-nowrap px-4 py-2.5 text-zinc-600 sm:table-cell">{{ number_format($source->byteSize()) }} B</td>
                                <td class="hidden whitespace-nowrap px-4 py-2.5 lg:table-cell">
                                    @if($source->isPartial())
                                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Partial</span>
                                        <span class="ml-1 font-mono text-xs text-zinc-500">@foreach($source->line_ranges ?? [] as $range)L{{ $range['start'] }}–L{{ $range['end'] }}@if(!$loop->last), @endif@endforeach of {{ number_format($source->original_lines ?? 0) }}</span>
                                    @else
                                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">Complete</span>
                                    @endif
                                </td>
                                <td class="hidden px-4 py-2.5 font-mono text-xs text-zinc-500 md:table-cell">{{ substr($source->content_hash, 0, 12) }}…</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if(!empty($repository->last_import_summary['missing_relevant'] ?? []))
            <div class="mt-6 rounded-xl border border-amber-300 bg-amber-50 p-6">
                <h2 class="text-sm font-semibold text-amber-900">Relevant files not imported</h2>
                <p class="mt-1 text-xs text-amber-800">High-scoring files left out by budgets or fetch failures. Fetch them individually into this import.</p>
                <ul class="mt-3 space-y-1.5 text-sm">
                    @foreach($repository->last_import_summary['missing_relevant'] as $missing)
                        <li class="flex flex-wrap items-center gap-x-2">
                            <span class="font-mono text-xs">{{ $missing['path'] }}</span>
                            <span class="text-xs text-amber-800">— {{ $missing['reason'] }}</span>
                            <button type="button" wire:click="fetchMissing({{ Illuminate\Support\Js::from($missing['path']) }})" wire:loading.attr="disabled" class="rounded bg-amber-200 px-2 py-0.5 text-xs font-semibold text-amber-900 hover:bg-amber-300 disabled:opacity-50">Fetch</button>
                        </li>
                    @endforeach
                </ul>
                @error('fetchMissing')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
        @endif

        @if(!empty($repository->last_import_summary['skipped'] ?? []))
            <div class="mt-6 rounded-xl border border-zinc-200 bg-zinc-50 p-6">
                <h2 class="text-sm font-semibold">Excluded files ({{ count($repository->last_import_summary['skipped']) }})</h2>
                @php($excludedTallies = collect($repository->last_import_summary['skipped'])->groupBy('reason')->map->count()->sortKeys())
                @if($excludedTallies->isNotEmpty())
                    <ul class="mt-3 flex flex-wrap gap-2 text-xs">
                        @foreach($excludedTallies as $reason => $count)
                            <li class="rounded-full bg-zinc-200 px-3 py-1 font-mono">{{ $reason }} · {{ $count }}</li>
                        @endforeach
                    </ul>
                @endif
                <details class="mt-3">
                    <summary class="cursor-pointer text-sm font-semibold text-zinc-700 hover:text-emerald-700">Show all excluded paths</summary>
                    <ul class="mt-3 max-h-96 space-y-1.5 overflow-y-auto text-sm">
                        @foreach($repository->last_import_summary['skipped'] as $skip)
                            <li class="flex flex-wrap gap-x-2"><span class="font-mono text-xs">{{ $skip['path'] }}</span><span class="text-xs text-zinc-500">— {{ $skip['reason'] }}</span></li>
                        @endforeach
                    </ul>
                </details>
            </div>
        @endif

        @if(!empty($repository->last_import_summary['warnings'] ?? []))
            <div class="mt-6 rounded-xl border border-amber-300 bg-amber-50 p-6">
                <h2 class="text-sm font-semibold text-amber-900">Import warnings</h2>
                <ul class="mt-2 list-disc pl-5 text-sm text-amber-900">
                    @foreach($repository->last_import_summary['warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    @else
        <p class="mt-6 rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center text-sm text-zinc-600">No repository linked yet. Import one above to build the evidence manifest.</p>
    @endif

    <div class="mt-8 rounded-xl border border-zinc-200 bg-white p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-semibold">AI Revival Investigator</h2>
                <p class="mt-1 text-sm text-zinc-600">Runs a structured feasibility analysis over the imported evidence. Findings stay drafts until you approve them.</p>
            </div>
            <button type="button" wire:click="analyze" wire:loading.attr="disabled" @disabled(! ($repository?->isReady() && ! ($runsActive ?? false) && $evidence->isNotEmpty()))
                class="rounded-lg bg-zinc-950 px-5 py-2.5 text-sm font-semibold text-white hover:bg-zinc-800 disabled:opacity-50">
                <span wire:loading.remove>Analyze with AI</span>
                <span wire:loading>Starting…</span>
            </button>
        </div>
        @if(!$repository)
            <p class="mt-2 text-sm text-zinc-600">Import a repository above before running an analysis.</p>
        @elseif($repository->isImporting())
            <p class="mt-2 text-sm text-zinc-600">Analysis unlocks when the import finishes.</p>
        @elseif($repository->import_status === 'failed')
            <p class="mt-2 text-sm text-zinc-600">The last import failed — re-import evidence before running an analysis.</p>
        @elseif($runsActive ?? false)
            <p class="mt-2 text-sm text-zinc-600">An analysis is already running for this project.</p>
        @elseif($evidence->isEmpty())
            <p class="mt-2 text-sm text-zinc-600">The import stored no usable files — fetch files or adjust budgets and re-import first.</p>
        @else
            <p class="mt-2 text-sm text-zinc-600">Will analyze {{ $evidence->count() }} evidence files at commit {{ substr($repository->last_commit_sha, 0, 12) }}.</p>
        @endif
        @error('analyze')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror

        @if($runs->isNotEmpty())
            <ul class="mt-4 divide-y divide-zinc-100 border-t border-zinc-100">
                @foreach($runs as $run)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
                        <span class="text-zinc-600">
                            Run #{{ $run->id }} · {{ $run->created_at->diffForHumans() }}
                            @if($run->report) · <span class="font-semibold text-zinc-900">report v{{ $run->report->version_number }}</span>@endif
                            @if(($run->token_usage['total'] ?? 0) > 0) · {{ number_format((int) $run->token_usage['total']) }} tokens @endif
                        </span>
                        <span class="flex items-center gap-3">
                            @if($run->report)
                                <a href="{{ route('projects.reports.show', [$project, $run->report]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Review report →</a>
                            @endif
                            @if($run->isEvidenceStale())
                                <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800">Evidence changed</span>
                            @endif
                            <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold
                                @if($run->status->isFailure()) bg-red-100 text-red-800
                                @elseif($run->status->isActive()) bg-amber-100 text-amber-800
                                @else bg-emerald-100 text-emerald-800 @endif">
                                {{ $run->status->label() }}
                            </span>
                        </span>
                    </li>
                    @if($run->error_message)
                        <li class="-mt-1 pb-2 text-xs text-red-700">{{ $run->error_message }}</li>
                    @endif
                    @if($run->error_code === 'SCHEMA_VIOLATION')
                        <li class="-mt-1 pb-2 text-xs text-zinc-600">No draft was created and your evidence is unchanged. The model returned a report that failed evidence checks — running the analysis again often succeeds.</li>
                    @endif
                @endforeach
            </ul>
        @else
            <p class="mt-4 text-sm text-zinc-600">No analyses yet. Import evidence above, then run the first AI analysis.</p>
        @endif
    </div>
</div>
