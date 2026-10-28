<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">
            <a href="{{ route('projects.show', $project) }}" class="hover:underline">{{ $project->title }}</a> · Task
        </p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">{{ $task->title }}</h1>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <span class="rounded-full bg-zinc-100 px-3 py-1 text-xs font-semibold text-zinc-800">{{ $task->status->label() }}</span>
            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800">{{ $task->origin->label() }}</span>
            @if($task->requires_hardware)
                <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-900">Needs hardware</span>
            @endif
        </div>
    </x-slot>
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        @if(session('status'))
            <p class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</p>
        @endif

        <div class="rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
            <dl class="grid gap-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="font-semibold text-zinc-500">Assignee</dt>
                    <dd class="mt-1">{{ $task->assignee?->name ?? 'Unassigned' }}</dd>
                </div>
                <div>
                    <dt class="font-semibold text-zinc-500">Milestone</dt>
                    <dd class="mt-1">{{ $task->milestone?->title ?? 'No milestone' }}</dd>
                </div>
            </dl>

            @if($task->skillTags() !== [])
                <h2 class="mt-6 text-sm font-semibold text-zinc-500">Skills</h2>
                <div class="mt-1 flex flex-wrap gap-2">
                    @foreach($task->skillTags() as $skill)
                        <span class="rounded-full bg-zinc-100 px-3 py-1 text-xs font-medium text-zinc-700">{{ $skill }}</span>
                    @endforeach
                </div>
            @endif

            @if($task->description)
                <h2 class="mt-6 text-sm font-semibold text-zinc-500">Description</h2>
                <p class="mt-1 whitespace-pre-line text-sm text-zinc-800">{{ $task->description }}</p>
            @endif

            @if($task->definition_of_done)
                <h2 class="mt-6 text-sm font-semibold text-zinc-500">Definition of done</h2>
                <p class="mt-1 whitespace-pre-line text-sm text-zinc-800">{{ $task->definition_of_done }}</p>
            @endif

            @if($canManage || $transitions !== [])
                <div class="mt-8 border-t border-zinc-100 pt-6">
                    <h2 class="text-sm font-semibold text-zinc-500">Workflow</h2>
                    @if($transitions === [])
                        <p class="mt-2 text-sm text-zinc-500">No actions available for you in the {{ $task->status->label() }} state.</p>
                    @else
                        <div class="mt-3 space-y-3">
                            @foreach($transitions as $to => $rule)
                                <form method="POST" action="{{ route('projects.tasks.transition', [$project, $task]) }}" class="flex flex-wrap items-center gap-2">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="{{ $to }}">
                                    @if($rule['note'])
                                        <label class="sr-only" for="note-{{ $to }}">Reason</label>
                                        <input id="note-{{ $to }}" name="note" type="text" required maxlength="2000" placeholder="Reason (required)"
                                            class="min-w-0 flex-1 rounded-lg border-zinc-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                    @endif
                                    <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">
                                        {{ \App\Enums\TaskStatus::from($to)->label() }}
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    @endif
                    @error('status')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                    @error('note')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror

                    @if($canManage)
                        <form method="POST" action="{{ route('projects.tasks.assign', [$project, $task]) }}" class="mt-4 flex flex-wrap items-end gap-2">
                            @csrf
                            @method('PATCH')
                            <div>
                                <label for="assignee_id" class="block text-xs font-semibold text-zinc-500">Assign to</label>
                                <select id="assignee_id" name="assignee_id" class="mt-1 block rounded-lg border-zinc-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                    <option value="">Unassigned</option>
                                    @foreach($candidates as $candidate)
                                        <option value="{{ $candidate->id }}" @selected($task->assigned_to_user_id === $candidate->id)>{{ $candidate->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="submit" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50">Save assignee</button>
                        </form>
                        @error('assignee')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                        <p class="mt-4 text-sm">
                            <a href="{{ route('projects.tasks.edit', [$project, $task]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Edit task</a>
                        </p>
                    @endif
                </div>
            @endif
        </div>

        @if($discussions->isNotEmpty())
            <div class="mt-6 rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
                <h2 class="text-sm font-semibold text-zinc-500">Linked discussions</h2>
                <ul class="mt-3 space-y-2">
                    @foreach($discussions as $discussion)
                        <li class="text-sm">
                            <a href="{{ route('projects.discussion.show', [$project, $discussion->thread()]) }}{{ $discussion->parent_id !== null ? '#reply-'.$discussion->id : '' }}" class="font-semibold text-emerald-700 hover:text-emerald-600">{{ \App\Services\Discussion\Markup::excerpt($discussion->body, 90) }}</a>
                            <span class="text-zinc-500">— {{ $discussion->author->name }}, {{ $discussion->created_at->diffForHumans() }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mt-6 rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
            <h2 class="text-sm font-semibold text-zinc-500">History</h2>
            @if($history->isEmpty())
                <p class="mt-2 text-sm text-zinc-500">No recorded activity yet.</p>
            @else
                <ol class="mt-3 space-y-3">
                    @foreach($history as $event)
                        <li class="text-sm text-zinc-700">
                            <span class="font-semibold">{{ $event->user?->name ?? 'System' }}</span>
                            <span class="text-zinc-500">· {{ $event->created_at->diffForHumans() }} ·</span>
                            @switch($event->event)
                                @case('task.status')
                                    moved this task from <strong>{{ \App\Enums\TaskStatus::tryFrom($event->metadata['from'] ?? '')?->label() ?? '—' }}</strong>
                                    to <strong>{{ \App\Enums\TaskStatus::tryFrom($event->metadata['to'] ?? '')?->label() ?? '—' }}</strong>@if(!empty($event->metadata['note'])) — “{{ $event->metadata['note'] }}”@endif
                                    @break
                                @case('task.assigned')
                                    assigned this task to <strong>{{ $event->metadata['assignee'] ?? 'someone' }}</strong>
                                    @break
                                @case('task.unassigned')
                                    unassigned this task
                                    @break
                                @case('task.created')
                                    created this task
                                    @break
                                @case('offer.accepted')
                                    accepted a claim for this task
                                    @break
                                @default
                                    {{ $event->event }}
                            @endswitch
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>

        <p class="mt-6"><a href="{{ route('projects.show', $project) }}" class="text-sm font-semibold text-emerald-700 hover:text-emerald-600">← Back to project</a></p>
    </div>
</x-app-layout>
