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
                <p class="mt-4 rounded-lg bg-zinc-100 px-4 py-3 text-sm text-zinc-600">Import repository evidence before chatting with the Revival Agent.</p>
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
                    <button type="submit" class="mt-3 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Start investigating</button>
                </form>
            @endif
        </section>

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
                            </p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-app-layout>
