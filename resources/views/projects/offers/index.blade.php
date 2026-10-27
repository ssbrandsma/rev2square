<x-app-layout>
    <x-slot name="header">
        <p class="font-mono text-xs uppercase tracking-widest text-emerald-700">Contribution offers</p>
        <h1 class="mt-1 text-2xl font-extrabold tracking-tight">{{ $project->title }}</h1>
        <p class="mt-1 text-sm text-zinc-600"><a href="{{ route('projects.show', $project) }}" class="font-semibold text-emerald-700">← Back to project</a></p>
    </x-slot>
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6">
        @if(session('status'))
            <p class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</p>
        @endif
        @if($errors->has('offer'))
            <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ $errors->first('offer') }}</p>
        @endif
        @if($offers->isEmpty())
            <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center text-sm text-zinc-600">No offers yet.</p>
        @else
            <ul class="space-y-4">
                @foreach($offers as $offer)
                    <li class="rounded-xl border border-zinc-200 bg-white p-5">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="font-bold">{{ $offer->user->name }} <span class="font-normal text-zinc-500">· {{ $offer->type->label() }}</span></p>
                            <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold
                                @if($offer->status->value === 'accepted') bg-emerald-100 text-emerald-800
                                @elseif($offer->status->value === 'declined') bg-zinc-100 text-zinc-700
                                @else bg-amber-100 text-amber-800 @endif">{{ $offer->status->label() }}</span>
                        </div>
                        @if($offer->task)
                            <p class="mt-1 text-sm text-zinc-600">For task: <span class="font-semibold text-zinc-900">{{ $offer->task->title }}</span></p>
                        @endif
                        <p class="mt-2 text-sm text-zinc-700">{{ $offer->message }}</p>
                        <p class="mt-2 text-xs text-zinc-500">{{ $offer->created_at->diffForHumans() }}</p>
                        @if($offer->status->value === 'pending')
                            <div class="mt-3 flex gap-2 border-t border-zinc-100 pt-3">
                                <form method="POST" action="{{ route('projects.offers.accept', [$project, $offer]) }}">
                                    @csrf
                                    <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-emerald-500">Accept</button>
                                </form>
                                <form method="POST" action="{{ route('projects.offers.decline', [$project, $offer]) }}">
                                    @csrf
                                    <button type="submit" class="rounded-lg border border-zinc-300 px-4 py-1.5 text-sm font-semibold text-zinc-700 hover:border-zinc-400">Decline</button>
                                </form>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-app-layout>
