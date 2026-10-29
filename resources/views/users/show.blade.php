<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Member</p>
        <div class="mt-1 flex items-start gap-4">
            <x-avatar :user="$profileUser" size="lg" />
            <div class="min-w-0">
                <h1 class="break-words text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">{{ $profileUser->name }}</h1>
                @if($profileUser->handle)
                    <p class="font-mono text-sm text-zinc-500">{{ '@'.$profileUser->handle }}</p>
                @endif
                <p class="mt-1 text-sm text-zinc-600">Member since {{ $profileUser->created_at->format('F Y') }}.</p>
            </div>
        </div>
        @if($profileUser->bio)
            <p class="mt-3 max-w-2xl whitespace-pre-line text-sm text-zinc-700">{{ $profileUser->bio }}</p>
        @endif
        @if(count($profileUser->skillList()) > 0)
            <ul class="mt-3 flex flex-wrap gap-2" aria-label="Skills">
                @foreach($profileUser->skillList() as $skill)
                    <li class="rounded-full bg-zinc-100 px-3 py-1 text-xs font-semibold text-zinc-700">{{ $skill }}</li>
                @endforeach
            </ul>
        @endif
        @if($profileUser->availability || $profileUser->hardware_interests)
            <dl class="mt-3 space-y-1 text-sm text-zinc-600">
                @if($profileUser->availability)
                    <div class="flex gap-2"><dt class="font-semibold text-zinc-700">Availability (self-described):</dt><dd>{{ $profileUser->availability }}</dd></div>
                @endif
                @if($profileUser->hardware_interests)
                    <div class="flex gap-2"><dt class="font-semibold text-zinc-700">Hardware interests (self-described):</dt><dd>{{ $profileUser->hardware_interests }}</dd></div>
                @endif
            </dl>
        @endif
        @if(count($profileUser->linkList()) > 0)
            <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                @foreach($profileUser->linkList() as $link)
                    <li><a href="{{ $link['url'] }}" rel="noopener nofollow" class="font-semibold text-emerald-700 hover:text-emerald-600">{{ $link['label'] }}</a></li>
                @endforeach
            </ul>
        @endif
        @if($isSelf)
            <p class="mt-3 text-sm"><a href="{{ route('profile.edit') }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Edit profile</a> <span class="text-zinc-400">·</span> <a href="{{ route('dashboard') }}" class="font-semibold text-emerald-700 hover:text-emerald-600">My workspace</a> <span class="text-zinc-500">(private: drafts, applications, saved)</span></p>
        @endif
    </x-slot>
    <div class="mx-auto max-w-3xl space-y-10 px-4 py-10 sm:px-6">
        @php
        $defs = [
            'started' => ['title' => 'Started', 'empty' => 'Has not started a published project yet.'],
            'maintaining' => ['title' => 'Maintaining', 'empty' => 'Not currently maintaining a published project.'],
            'working_on' => ['title' => 'Working on', 'empty' => 'No current membership or task assignment on a published project.'],
        ];
        @endphp

        @foreach($defs as $key => $meta)
            <section aria-label="{{ $meta['title'] }}">
                <h2 class="text-sm font-semibold text-zinc-500">{{ $meta['title'] }} <span class="text-zinc-400">({{ $sections[$key]['total'] }})</span></h2>
                @if($sections[$key]['items']->isEmpty())
                    <p class="mt-3 rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-600">{{ $meta['empty'] }}</p>
                @else
                    <ul class="mt-3 space-y-3">
                        @foreach($sections[$key]['items'] as $card)
                            <li class="rounded-xl border border-zinc-200 bg-white p-5">
                                <div class="flex items-start gap-3">
                                    @include('projects._thumb', ['project' => $card['project']])
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-start justify-between gap-3">
                                            <a href="{{ route('projects.show', $card['project']) }}" class="font-bold hover:text-emerald-700">{{ $card['project']->title }}</a>
                                            <span class="shrink-0 rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-semibold text-zinc-700">{{ $card['role'] }}</span>
                                        </div>
                                <p class="mt-1 text-sm text-zinc-600">{{ $card['project']->pitch }}</p>
                                <p class="mt-1 text-xs text-zinc-500">{{ $card['project']->phase->label() }} · {{ $card['project']->activity->label() }}@if($card['is_creator'] && $key !== 'started') · Started by this member @endif</p>
                                @if($card['assigned_task'])
                                    <p class="mt-1 text-xs text-zinc-600">Assigned: <a href="{{ route('projects.tasks.show', [$card['project'], $card['assigned_task']]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">{{ $card['assigned_task']->title }}</a> ({{ $card['assigned_task']->status->label() }})</p>
                                @endif
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    @if($sections[$key]['total'] > $sections[$key]['items']->count())
                        <p class="mt-2 text-xs text-zinc-500">Showing {{ $sections[$key]['items']->count() }} of {{ $sections[$key]['total'] }}.</p>
                    @endif
                @endif
            </section>
        @endforeach

        <section aria-label="Contributions">
            <h2 class="text-sm font-semibold text-zinc-500">Contributions <span class="text-zinc-400">({{ $sections['contributions']['total'] }})</span></h2>
            @if($sections['contributions']['items']->isEmpty())
                <p class="mt-3 rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-600">No recorded contributions on published projects yet.</p>
            @else
                <ul class="mt-3 space-y-3">
                    @foreach($sections['contributions']['items'] as $contribution)
                        <li class="rounded-xl border border-zinc-200 bg-white p-5">
                            <p class="text-xs font-semibold text-emerald-700">{{ $contribution['kind'] }}</p>
                            <a href="{{ $contribution['url'] }}" class="mt-0.5 block font-bold hover:text-emerald-700">{{ $contribution['title'] }}</a>
                            <p class="mt-1 text-xs text-zinc-500">{{ $contribution['project']->title }} · {{ $contribution['status'] }}</p>
                        </li>
                    @endforeach
                </ul>
                @if($sections['contributions']['total'] > $sections['contributions']['items']->count())
                    <p class="mt-2 text-xs text-zinc-500">Showing {{ $sections['contributions']['items']->count() }} of {{ $sections['contributions']['total'] }}.</p>
                @endif
            @endif
        </section>
    </div>
</x-app-layout>
