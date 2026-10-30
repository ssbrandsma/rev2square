<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Uploaded files</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">{{ $project->title }}</h1>
        <p class="mt-1 text-sm text-zinc-600"><a href="{{ route('projects.agent.index', $project) }}" class="font-semibold text-emerald-700">← Back to Revival Agent</a></p>
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
            <h2 class="font-semibold">Files ({{ $artifacts->count() }} of {{ $maxPerProject }})</h2>
            <p class="mt-1 max-w-[70ch] text-sm text-zinc-600">
                Executables, firmware, and captures, detected from contents. Limit {{ number_format($maxBytes / 1024 / 1024, 0) }} MiB per file.
                Files stay in private project storage and never appear in public exports.
            </p>
            @if($artifacts->isEmpty())
                <p class="mt-3 text-sm text-zinc-500">No uploads yet. Upload from the Revival Agent page.</p>
            @else
                <ul class="mt-3 divide-y divide-zinc-100">
                    @foreach($artifacts as $artifact)
                        <li class="py-3">
                            <p class="text-sm">
                                <span class="font-mono font-semibold">[A{{ $artifact->id }}]</span>
                                <a href="{{ route('projects.artifacts.show', [$project, $artifact]) }}" class="font-semibold text-emerald-700 hover:underline">{{ $artifact->original_name }}</a>
                            </p>
                            <p class="mt-0.5 text-xs text-zinc-500">
                                {{ $artifact->format }}@if($artifact->arch), {{ $artifact->arch }}@endif
                                · {{ number_format($artifact->byte_size / 1024, 0) }} KB
                                · {{ $artifact->stateLabel() }}
                                @if($artifact->state === 'failed' && $artifact->failure_message)
                                    — {{ $artifact->failure_message }}
                                @endif
                                · uploaded {{ $artifact->created_at->diffForHumans() }} by {{ $artifact->uploader->name ?? 'someone' }}
                            </p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-app-layout>
