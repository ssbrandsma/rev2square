<div @if($repository?->isImporting()) wire:poll.3s @endif>
    <form wire:submit="import" class="rounded-xl border border-zinc-200 bg-white p-6">
        <label for="repo-url" class="block text-sm font-semibold">Public GitHub repository URL</label>
        <div class="mt-2 flex flex-col gap-3 sm:flex-row">
            <input id="repo-url" type="url" wire:model="url" placeholder="https://github.com/owner/repository" required maxlength="500"
                class="block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
            <button type="submit" class="shrink-0 rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">
                {{ $repository ? 'Re-import' : 'Import' }}
            </button>
        </div>
        @error('url')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
        <p class="mt-2 text-xs text-zinc-500">Public repositories only. README, docs, manifests, and small source files are imported within strict size budgets; nothing is executed.</p>
    </form>

    @if($repository)
        <div class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="font-mono text-xs uppercase tracking-widest text-zinc-500">Tracked repository</p>
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
                <p class="mt-4 text-sm text-zinc-600" wire:loading.remove>Import running in the background — this view refreshes automatically.</p>
            @endif

            @if($repository->import_error)
                <p class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $repository->import_error }}</p>
            @endif

            @if($repository->last_commit_sha)
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-4">
                    <div><dt class="font-mono text-xs uppercase tracking-wider text-zinc-500">Commit</dt><dd class="mt-0.5 font-mono text-xs">{{ substr($repository->last_commit_sha, 0, 12) }}</dd></div>
                    <div><dt class="font-mono text-xs uppercase tracking-wider text-zinc-500">Branch</dt><dd class="mt-0.5">{{ $repository->default_branch }}</dd></div>
                    <div><dt class="font-mono text-xs uppercase tracking-wider text-zinc-500">Files</dt><dd class="mt-0.5">{{ $evidence->count() }}</dd></div>
                    <div><dt class="font-mono text-xs uppercase tracking-wider text-zinc-500">Synced</dt><dd class="mt-0.5">{{ $repository->last_synced_at?->diffForHumans() }}</dd></div>
                </dl>
            @endif
        </div>

        @if($evidence->isNotEmpty())
            <div class="mt-6 overflow-hidden rounded-xl border border-zinc-200">
                <table class="min-w-full divide-y divide-zinc-200 text-sm">
                    <thead class="bg-zinc-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-700">Source</th>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-700">Path</th>
                            <th class="hidden px-4 py-3 text-left font-semibold text-zinc-700 sm:table-cell">Size</th>
                            <th class="hidden px-4 py-3 text-left font-semibold text-zinc-700 md:table-cell">SHA-256</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 bg-white">
                        @foreach($evidence as $source)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-2.5 font-mono text-xs font-bold">S{{ $source->id }}</td>
                                <td class="px-4 py-2.5"><a href="{{ $source->url }}" target="_blank" rel="noopener" class="font-mono text-xs hover:text-emerald-700">{{ $source->path }}</a></td>
                                <td class="hidden whitespace-nowrap px-4 py-2.5 text-zinc-600 sm:table-cell">{{ number_format($source->byteSize()) }} B</td>
                                <td class="hidden px-4 py-2.5 font-mono text-xs text-zinc-500 md:table-cell">{{ substr($source->content_hash, 0, 12) }}…</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if(!empty($repository->last_import_summary['skipped'] ?? []))
            <div class="mt-6 rounded-xl border border-zinc-200 bg-zinc-50 p-6">
                <h2 class="text-sm font-bold">Skipped files ({{ count($repository->last_import_summary['skipped']) }})</h2>
                <ul class="mt-3 space-y-1.5 text-sm">
                    @foreach($repository->last_import_summary['skipped'] as $skip)
                        <li class="flex flex-wrap gap-x-2"><span class="font-mono text-xs">{{ $skip['path'] }}</span><span class="text-xs text-zinc-500">— {{ $skip['reason'] }}</span></li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(!empty($repository->last_import_summary['warnings'] ?? []))
            <div class="mt-6 rounded-xl border border-amber-300 bg-amber-50 p-6">
                <h2 class="text-sm font-bold text-amber-900">Import warnings</h2>
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
</div>
