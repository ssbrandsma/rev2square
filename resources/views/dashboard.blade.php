<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex items-center gap-3">
                <x-avatar :user="auth()->user()" />
                <div>
                    <p class="text-sm font-semibold text-emerald-700">My workspace</p>
                    <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">{{ auth()->user()->name }}</h1>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ auth()->user()->profileUrl() }}" class="text-sm font-semibold text-emerald-700 hover:text-emerald-600">View public profile</a>
                <a href="{{ route('projects.create') }}" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Start a revival</a>
            </div>
        </div>
    </x-slot>
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        @if(session('status'))
            <p class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</p>
        @endif

        <section aria-label="Needs attention">
            <h2 class="mb-3 text-lg font-semibold tracking-tight">Needs attention</h2>
            @php
            $blockedTasks = $sections['assigned_tasks']->filter(fn ($task) => $task->status->value === 'BLOCKED');
            $hasAttention = $invitations->isNotEmpty() || count($joinableOfferIds) > 0 || $blockedTasks->isNotEmpty() || $unreadCount > 0;
            @endphp
            @if(! $hasAttention)
                <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-600">Nothing waiting.</p>
            @else
                <ul class="space-y-3">
                    @foreach($invitations as $invitation)
                        <li class="rounded-xl border border-amber-200 bg-amber-50/50 p-4 text-sm">
                            <p><span class="font-bold">{{ $invitation->project->title }}</span> invited you to join the team. Expires {{ $invitation->expires_at->diffForHumans() }}.</p>
                            <p class="mt-2 flex gap-3">
                                <form method="POST" action="{{ route('projects.team.accept', [$invitation->project, $invitation]) }}" class="inline">@csrf<button type="submit" class="font-semibold text-emerald-700 hover:text-emerald-600">Accept</button></form>
                                <form method="POST" action="{{ route('projects.team.decline', [$invitation->project, $invitation]) }}" class="inline">@csrf<button type="submit" class="font-semibold text-zinc-500 hover:text-zinc-800">Decline</button></form>
                            </p>
                        </li>
                    @endforeach
                    @foreach($applications->whereIn('id', $joinableOfferIds) as $offer)
                        <li class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-4 text-sm">
                            <p>Your help request for <span class="font-bold">{{ $offer->project->title }}</span> was accepted. Join the team to appear under Working on.</p>
                            <form method="POST" action="{{ route('projects.offers.join', [$offer->project, $offer]) }}" class="mt-2">@csrf<button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-500">Join project as contributor</button></form>
                        </li>
                    @endforeach
                    @foreach($blockedTasks as $task)
                        <li class="rounded-xl border border-red-200 bg-red-50/50 p-4 text-sm">
                            <p><a href="{{ route('projects.tasks.show', [$task->project, $task]) }}" class="font-bold hover:text-emerald-700">{{ $task->title }}</a> is blocked. <span class="text-zinc-600">{{ $task->project->title }}</span></p>
                        </li>
                    @endforeach
                    @if($unreadCount > 0)
                        <li class="rounded-xl border border-zinc-200 bg-white p-4 text-sm">
                            <p><a href="{{ route('inbox.index') }}" class="font-bold text-emerald-700 hover:text-emerald-600">{{ $unreadCount }} unread notification{{ $unreadCount === 1 ? '' : 's' }}</a> in your inbox.</p>
                        </li>
                    @endif
                </ul>
            @endif
        </section>

        <h2 class="mb-3 mt-10 text-lg font-semibold tracking-tight">Started by me</h2>
        @if($projects->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-10 text-center">
                <p class="font-semibold">No projects yet</p>
                <p class="mt-1 text-sm text-zinc-600">Start the first revival for a device you care about.</p>
                <a href="{{ route('projects.create') }}" class="mt-4 inline-block rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">Start a revival</a>
            </div>
        @else
            <div class="overflow-x-auto rounded-xl border border-zinc-200">
                <table class="min-w-full divide-y divide-zinc-200 text-sm">
                    <thead class="bg-zinc-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-700">Project</th>
                            <th class="hidden px-4 py-3 text-left font-semibold text-zinc-700 sm:table-cell">Phase</th>
                            <th class="hidden px-4 py-3 text-left font-semibold text-zinc-700 md:table-cell">Tasks</th>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-700">Visibility</th>
                            <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 bg-white">
                        @foreach($projects as $project)
                            <tr>
                                <td class="px-4 py-3">
                                    <a href="{{ route('projects.show', $project) }}" class="font-bold hover:text-emerald-700">{{ $project->title }}</a>
                                    <p class="font-mono text-xs text-zinc-500">/{{ $project->slug }}</p>
                                </td>
                                <td class="hidden px-4 py-3 text-zinc-600 sm:table-cell">{{ $project->phase->label() }}</td>
                                <td class="hidden px-4 py-3 text-zinc-600 md:table-cell">{{ $project->tasks_count }}</td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $project->isPublished() ? 'bg-emerald-100 text-emerald-800' : 'bg-zinc-100 text-zinc-700' }}">{{ $project->publication_status->label() }}</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @can('view', $project)
                                        <a href="{{ route('projects.evidence', $project) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Evidence</a>
                                    @endcan
                                    @can('update', $project)
                                        <span class="mx-1 text-zinc-300">·</span>
                                        <a href="{{ route('projects.edit', $project) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Edit</a>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <h2 class="mb-3 mt-10 text-lg font-semibold tracking-tight">Working on <span class="text-sm font-semibold text-zinc-400">({{ $sections['working_on']['total'] }})</span></h2>
        @if($sections['working_on']['items']->isEmpty())
            <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-600">No team memberships or task assignments yet.</p>
        @else
            <ul class="space-y-3">
                @foreach($sections['working_on']['items'] as $card)
                    <li class="rounded-xl border border-zinc-200 bg-white p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <a href="{{ route('projects.show', $card['project']) }}" class="font-bold hover:text-emerald-700">{{ $card['project']->title }}</a>
                            <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-semibold text-zinc-700">{{ $card['role'] }}</span>
                        </div>
                        <p class="mt-1 text-xs text-zinc-500">{{ $card['project']->phase->label() }} · {{ $card['project']->activity->label() }} · {{ $card['project']->publication_status->label() }}</p>
                        @if($card['assigned_task'])
                            <p class="mt-1 text-xs text-zinc-600">Assigned: <a href="{{ route('projects.tasks.show', [$card['project'], $card['assigned_task']]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">{{ $card['assigned_task']->title }}</a> ({{ $card['assigned_task']->status->label() }})</p>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if($sections['working_on']['total'] > $sections['working_on']['items']->count())
                <p class="mt-2 text-xs text-zinc-500">Showing {{ $sections['working_on']['items']->count() }} of {{ $sections['working_on']['total'] }}.</p>
            @endif
        @endif

        <h2 id="applications" class="mb-3 mt-10 text-lg font-semibold tracking-tight">My applications</h2>
        @if($applications->isEmpty())
            <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-600">No applications yet. Find a project on <a href="{{ route('projects.index') }}" class="font-semibold text-emerald-700">Explore</a> and offer help.</p>
        @else
            <ul class="space-y-3">
                @foreach($applications as $offer)
                    <li class="rounded-xl border border-zinc-200 bg-white p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm"><a href="{{ route('projects.show', $offer->project) }}" class="font-bold hover:text-emerald-700">{{ $offer->project->title }}</a>
                                <span class="text-zinc-500">· {{ $offer->type->label() }}@if($offer->task) · {{ $offer->task->title }}@endif</span></p>
                            <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold
                                @if($offer->status->value === 'accepted') bg-emerald-100 text-emerald-800
                                @elseif($offer->status->value === 'pending') bg-amber-100 text-amber-800
                                @else bg-zinc-100 text-zinc-700 @endif">{{ $offer->status->label() }}</span>
                        </div>
                        <p class="mt-1 text-sm text-zinc-600">{{ $offer->nextStep() }}</p>
                        @if($offer->decision_reason)
                            <p class="mt-1 text-sm text-zinc-600"><span class="font-semibold text-zinc-900">Maintainers:</span> {{ $offer->decision_reason }}</p>
                        @endif
                        <div class="mt-1 flex flex-wrap items-center justify-between gap-2">
                            <p class="text-xs text-zinc-500">{{ $offer->created_at->diffForHumans() }} ·
                                <a href="{{ route('projects.offers.show', [$offer->project, $offer]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Open discussion</a></p>
                            <div class="flex items-center gap-3">
                                @if(in_array($offer->id, $joinableOfferIds, true))
                                    <form method="POST" action="{{ route('projects.offers.join', [$offer->project, $offer]) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-xs font-semibold text-emerald-700 hover:text-emerald-600">Join project</button>
                                    </form>
                                @endif
                                @if($offer->status->value === 'pending')
                                    <form method="POST" action="{{ route('projects.offers.withdraw', [$offer->project, $offer]) }}">
                                        @csrf
                                        <button type="submit" class="text-xs font-semibold text-zinc-500 hover:text-zinc-900">Withdraw</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        <h2 id="saved" class="mb-3 mt-10 text-lg font-semibold tracking-tight">Saved projects <span class="text-sm font-semibold text-zinc-400">({{ $saved->count() }})</span></h2>
        @if($saved->isEmpty())
            <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-600">Nothing saved yet.</p>
        @else
            <ul class="space-y-3">
                @foreach($saved as $save)
                    <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-zinc-200 bg-white p-4">
                        <div>
                            <a href="{{ route('projects.show', $save->project) }}" class="font-bold hover:text-emerald-700">{{ $save->project->title }}</a>
                            <p class="mt-1 text-xs text-zinc-500">{{ $save->project->phase->label() }} · {{ $save->project->activity->label() }}</p>
                        </div>
                        <form method="POST" action="{{ route('projects.unsave', $save->project) }}" class="inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs font-semibold text-zinc-500 hover:text-zinc-900">Remove</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
        @if($sections['hidden_saved'] > 0)
            <p class="mt-2 text-xs text-zinc-500">{{ $sections['hidden_saved'] }} saved project{{ $sections['hidden_saved'] === 1 ? ' is' : 's are' }} hidden because you can no longer view {{ $sections['hidden_saved'] === 1 ? 'it' : 'them' }}.</p>
        @endif

        <h2 class="mb-3 mt-10 text-lg font-semibold tracking-tight">My contributions <span class="text-sm font-semibold text-zinc-400">({{ $sections['contributions']['total'] }})</span></h2>
        @if($sections['contributions']['items']->isEmpty())
            <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-600">No recorded work yet.</p>
        @else
            <ul class="space-y-3">
                @foreach($sections['contributions']['items'] as $contribution)
                    <li class="rounded-xl border border-zinc-200 bg-white p-4">
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
        @if(($sections['contributions']['hidden'] ?? 0) > 0)
            <p class="mt-2 text-xs text-zinc-500">{{ $sections['contributions']['hidden'] }} contribution{{ $sections['contributions']['hidden'] === 1 ? ' is' : 's are' }} hidden because {{ $sections['contributions']['hidden'] === 1 ? 'its' : 'their' }} project is no longer visible to you.</p>
        @endif

        <div class="mb-3 mt-10 flex items-center justify-between">
            <h2 class="text-lg font-semibold tracking-tight">Recent activity</h2>
            <a href="{{ route('inbox.index') }}" class="text-sm font-semibold text-emerald-700 hover:text-emerald-600">Open inbox @if($unreadCount > 0)({{ $unreadCount }} unread)@endif</a>
        </div>
        @if($activity->isEmpty())
            <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-600">No activity yet.</p>
        @else
            <ul class="space-y-3">
                @foreach($activity as $notification)
                    <li class="rounded-xl border p-4 {{ $notification->read_at ? 'border-zinc-200 bg-white' : 'border-emerald-200 bg-emerald-50/50' }}">
                        <a href="{{ route('inbox.read', $notification->id) }}" class="block">
                            <p class="text-sm font-bold">{{ $notification->data['title'] ?? 'Notification' }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $notification->created_at->diffForHumans() }}</p>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-app-layout>
