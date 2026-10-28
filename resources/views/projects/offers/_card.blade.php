<li class="rounded-xl border border-zinc-200 bg-white p-5">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="font-bold">{{ $offer->user->name }} <span class="font-normal text-zinc-500">· {{ $offer->type->label() }}</span></p>
        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold
            @if($offer->status->value === 'accepted') bg-emerald-100 text-emerald-800
            @elseif($offer->status->value === 'pending') bg-amber-100 text-amber-800
            @else bg-zinc-100 text-zinc-700 @endif">{{ $offer->status->label() }}</span>
    </div>
    @if($offer->task)
        <p class="mt-1 text-sm text-zinc-600">For task: <span class="font-semibold text-zinc-900">{{ $offer->task->title }}</span></p>
    @endif
    <p class="mt-2 text-sm text-zinc-700">{{ $offer->message }}</p>
    <p class="mt-2 text-xs text-zinc-500">{{ $offer->created_at->diffForHumans() }} ·
        <a href="{{ route('projects.offers.show', [$project, $offer]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Open discussion</a></p>
    @if($offer->decision_reason)
        <p class="mt-1 text-sm text-zinc-600"><span class="font-semibold text-zinc-900">Decision note:</span> {{ $offer->decision_reason }}</p>
    @endif
    @if($offer->status->value === 'pending')
        <form method="POST" action="{{ route('projects.offers.accept', [$project, $offer]) }}" class="mt-3 border-t border-zinc-100 pt-3">
            @csrf
            <label for="decision_reason_{{ $offer->id }}" class="block text-xs font-semibold text-zinc-600">Decision note <span class="font-normal">(optional, visible to the contributor)</span></label>
            <input id="decision_reason_{{ $offer->id }}" name="decision_reason" type="text" maxlength="2000"
                class="mt-1 block w-full rounded-lg border-zinc-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
            @if($offer->type === \App\Enums\OfferType::MaintainProject)
                <p class="mt-1 text-xs text-zinc-500">Accepting sends a maintainer invitation. Access changes only after the applicant accepts it.</p>
            @endif
            <div class="mt-2 flex gap-2">
                <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-emerald-500">Accept</button>
                <button type="submit" formaction="{{ route('projects.offers.decline', [$project, $offer]) }}" class="rounded-lg border border-zinc-300 px-4 py-1.5 text-sm font-semibold text-zinc-700 hover:border-zinc-400">Decline</button>
            </div>
        </form>
    @endif
</li>
