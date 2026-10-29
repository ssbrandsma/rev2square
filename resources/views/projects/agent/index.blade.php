<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Revival Agent</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">{{ $project->title }}</h1>
        <p class="mt-1 text-sm text-zinc-600"><a href="{{ route('projects.show', $project) }}" class="font-semibold text-emerald-700">← Back to project</a></p>
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
            <h2 class="font-semibold">Ask about this project</h2>
            <p class="mt-1 max-w-[70ch] text-sm text-zinc-600">The agent investigates with the imported evidence and the architecture snapshot — up to six evidence lookups per turn. Answers cite the sources they used; conversations stay private to you and the maintainers.</p>
            @if($repository === null || ! $repository->isReady())
                <p class="mt-4 rounded-lg bg-zinc-100 px-4 py-3 text-sm text-zinc-600">Import repository evidence before chatting with the Revival Agent — or investigate uploaded files below.</p>
            @else
                <form method="POST" action="{{ route('projects.agent.store', $project) }}" class="mt-4">
                    @csrf
                    <label for="agent-question" class="sr-only">Your question</label>
                    <textarea id="agent-question" name="question" rows="3" required minlength="2" maxlength="2000"
                        placeholder="e.g. How does this project handle Bluetooth pairing?"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">{{ old('question') }}</textarea>
                    @error('question')
                        <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                    @enderror
                    <button type="submit" class="mt-3 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Ask</button>
                </form>
            @endif
        </section>

        @if($artifactsEnabled)
        <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
            <h2 class="font-semibold">Investigate uploaded files</h2>
            <p class="mt-1 max-w-[70ch] text-sm text-zinc-600">
                Upload executables (ELF, Windows PE, Mach-O), Android apps (APK/DEX), Java classes, firmware
                (Intel HEX, S-record, C64 PRG), archives, or network captures (PCAP/PCAPNG); describe the device
                and your question, then start investigating. Formats are detected from file contents, not extensions.
                Limit {{ number_format($maxBytes / 1024 / 1024, 0) }} MB per file, up to 5 files per upload.
            </p>
            <p class="mt-2 max-w-[70ch] rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                Captures and firmware may contain credentials, tokens, cookies, private keys, and personal data.
                Raw files stay in private project storage; only bounded, redacted summaries are ever sent to the
                AI provider — and only after your explicit confirmation below.
            </p>

            <form method="POST" action="{{ route('projects.artifacts.store', $project) }}" enctype="multipart/form-data" class="mt-4 border-t border-zinc-100 pt-4">
                @csrf
                <label for="artifact-files" class="text-sm font-semibold">1. Upload files</label>
                <input id="artifact-files" name="files[]" type="file" multiple required
                    accept=".elf,.bin,.fw,.pcap,.pcapng,.cap,.exe,.dll,.so,.dylib,.apk,.dex,.class,.jar,.hex,.srec,.s19,.prg,.zip"
                    class="mt-1 block w-full text-sm text-zinc-700 file:mr-3 file:rounded-lg file:border file:border-zinc-300 file:bg-zinc-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-zinc-700 hover:file:bg-zinc-100" />
                @error('files')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
                <label class="mt-2 flex items-start gap-2 text-sm text-zinc-700">
                    <input type="checkbox" name="as_raw" value="1" class="mt-0.5 rounded border-zinc-300" />
                    <span>Treat unrecognized files as raw binary images (headerless ROM/flash dumps I will configure with a processor and base address on the file page).</span>
                </label>
                <button type="submit" class="mt-3 rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50">Upload</button>
            </form>

            <form method="POST" action="{{ route('projects.agent.investigate', $project) }}" class="mt-4 border-t border-zinc-100 pt-4">
                @csrf
                <p class="text-sm font-semibold">2. Select processed files</p>
                @if($artifacts->isEmpty())
                    <p class="mt-1 text-sm text-zinc-500">No uploads yet. Files appear here after upload with their extraction state.</p>
                @else
                    <ul class="mt-2 space-y-1">
                        @foreach($artifacts as $artifact)
                            <li>
                                <label class="flex flex-col gap-0.5 rounded-lg border border-zinc-200 px-3 py-2 text-sm sm:flex-row sm:items-center sm:gap-2">
                                    <span class="flex items-center gap-2">
                                        @if($artifact->isUsable())
                                            <input type="checkbox" name="artifact_ids[]" value="{{ $artifact->id }}"
                                                {{ in_array($artifact->id, old('artifact_ids', [])) ? 'checked' : '' }}
                                                class="rounded border-zinc-300" />
                                        @else
                                            <input type="checkbox" disabled class="rounded border-zinc-300 opacity-40" />
                                        @endif
                                        <span class="font-mono font-semibold">[A{{ $artifact->id }}]</span>
                                        <span class="font-medium">{{ $artifact->original_name }}</span>
                                    </span>
                                    <span class="text-xs text-zinc-500 sm:ml-auto">
                                        {{ $artifact->format }}@if($artifact->arch), {{ $artifact->arch }}@endif · {{ $artifact->stateLabel() }}
                                        @if($artifact->state === 'failed' && $artifact->failure_message)
                                            — {{ $artifact->failure_message }}
                                        @endif
                                        · <a href="{{ route('projects.artifacts.show', [$project, $artifact]) }}" class="font-semibold text-emerald-700 hover:underline">details</a>
                                    </span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @error('artifact_ids')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror

                <label for="investigate-question" class="mt-4 block text-sm font-semibold">3. Your research question</label>
                <textarea id="investigate-question" name="investigation_question" rows="3" required minlength="2" maxlength="2000"
                    placeholder="e.g. Which external services does this device attempt to contact?"
                    class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">{{ old('investigation_question') }}</textarea>
                @error('investigation_question')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror

                <label for="device-context" class="mt-3 block text-sm font-semibold">4. Device context <span class="font-normal text-zinc-500">(optional)</span></label>
                <textarea id="device-context" name="device_context" rows="2" maxlength="2000"
                    placeholder="e.g. Thermostat firmware v2.1; capture taken during a failed cloud connection after device boot."
                    class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">{{ old('device_context') }}</textarea>
                <p class="mt-1 text-xs text-zinc-500">Helpful context: what the device is, firmware version, what happened during capture — device boot, login attempt, started recording, cloud connection failed.</p>

                <label class="mt-4 flex items-start gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm">
                    <input type="checkbox" name="consent" value="1" required class="mt-0.5 rounded border-zinc-300" />
                    <span>I understand bounded, redacted evidence from the selected files (names, formats, extraction output, and — for binaries — disassembly excerpts and function lists, with credentials redacted where recognizable) will be sent to the configured Meta AI provider for this conversation. Local analysis and AI analysis are distinct steps; raw files are never sent in full. Redaction cannot catch every secret.</span>
                </label>
                @error('consent')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror

                <button type="submit" class="mt-3 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Start investigating</button>
            </form>
        </section>
        @endif

        <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
            <h2 class="font-semibold">Conversations ({{ $conversations->count() }})</h2>
            @if($conversations->isEmpty())
                <p class="mt-2 text-sm text-zinc-500">No conversations yet.</p>
            @else
                <ul class="mt-2 divide-y divide-zinc-100">
                    @foreach($conversations as $conversation)
                        <li class="py-3">
                            <a href="{{ route('projects.agent.show', [$project, $conversation]) }}" class="text-sm font-semibold text-emerald-700 hover:underline">{{ $conversation->title ?? 'Untitled conversation' }}</a>
                            <p class="mt-0.5 text-xs text-zinc-500">{{ $conversation->messages_count }} messages · {{ $conversation->updated_at->diffForHumans() }}
                                @if($conversation->user_id !== auth()->id())
                                    · by {{ $conversation->user->name }}
                                @endif
                                @if($conversation->artifact_consent_at !== null)
                                    · upload investigation
                                @endif
                            </p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-app-layout>
