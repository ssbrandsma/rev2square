<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Contribution offers</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">{{ $project->title }}</h1>
        <p class="mt-1 text-sm text-zinc-600"><a href="{{ route('projects.show', $project) }}" class="font-semibold text-emerald-700">← Back to project</a></p>
    </x-slot>
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6">
        @if(session('status'))
            <p class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</p>
        @endif
        @if($errors->has('offer'))
            <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ $errors->first('offer') }}</p>
        @endif
        @php($maintainerApplications = $offers->where('type', \App\Enums\OfferType::MaintainProject))
        @php($contributionOffers = $offers->where('type', '!=', \App\Enums\OfferType::MaintainProject))

        <h2 class="mb-3 text-sm font-semibold text-zinc-500">Contribution offers ({{ $contributionOffers->count() }})</h2>
        @if($contributionOffers->isEmpty())
            <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center text-sm text-zinc-600">No offers yet.</p>
        @else
            <ul class="space-y-4">
                @foreach($contributionOffers as $offer)
                    @include('projects.offers._card', ['project' => $project, 'offer' => $offer])
                @endforeach
            </ul>
        @endif

        <h2 class="mb-3 mt-10 text-sm font-semibold text-zinc-500">Maintainer applications ({{ $maintainerApplications->count() }})</h2>
        @if($maintainerApplications->isEmpty())
            <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center text-sm text-zinc-600">No maintainer applications. Accepting one sends an invitation; it never grants access directly.</p>
        @else
            <ul class="space-y-4">
                @foreach($maintainerApplications as $offer)
                    @include('projects.offers._card', ['project' => $project, 'offer' => $offer])
                @endforeach
            </ul>
        @endif
    </div>
</x-app-layout>
