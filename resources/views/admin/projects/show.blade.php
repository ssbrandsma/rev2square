<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Administration · Project #{{ $project->id }}</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">{{ $project->title }}</h1>
        <p class="mt-1 text-sm text-zinc-600"><a href="{{ route('admin.projects.index') }}" class="font-semibold text-emerald-700">← All projects</a></p>
    </x-slot>
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        @include('admin._nav')
        @if(session('status'))
            <p class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</p>
        @endif
        @if(session('error'))
            <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ session('error') }}</p>
        @endif
        @if($errors->has('transfer'))
            <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ $errors->first('transfer') }}</p>
        @endif

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <section class="rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
                <h2 class="font-semibold">Project</h2>
                <dl class="mt-2 space-y-2 text-sm">
                    <div><dt class="text-zinc-500">Slug</dt><dd class="font-mono text-xs">/{{ $project->slug }}</dd></div>
                    <div><dt class="text-zinc-500">Status</dt><dd>{{ $project->publication_status->label() }} · {{ $project->activity->label() }} · {{ $project->phase->label() }}</dd></div>
                    <div><dt class="text-zinc-500">Created</dt><dd>{{ $project->created_at->format('Y-m-d H:i') }} by {{ $project->creator?->name ?? '—' }}</dd></div>
                    <div><dt class="text-zinc-500">Content</dt><dd class="font-mono text-xs">{{ $counts['tasks'] }} tasks · {{ $counts['milestones'] }} milestones · {{ $counts['offers_pending'] }} pending offers</dd></div>
                </dl>
                <p class="mt-3 text-sm">
                    @can('view', $project)
                        <a href="{{ route('projects.show', $project) }}" class="font-semibold text-emerald-700">Open project →</a>
                    @else
                        <span class="text-zinc-500">Not viewable with your project access (draft).</span>
                    @endcan
                </p>
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
                <h2 class="font-semibold">Ownership and team</h2>
                <dl class="mt-2 space-y-2 text-sm">
                    <div><dt class="text-zinc-500">Owner</dt><dd>
                        <a href="{{ route('admin.users.show', $project->owner) }}" class="font-semibold text-emerald-700">{{ $project->owner->name }}</a>
                        <span class="text-xs text-zinc-500">{{ $project->owner->email }} · {{ $project->owner->account_status->label() }}</span>
                        @if($project->owner->isSuspended())
                            <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Suspended — reassignment may be needed</span>
                        @endif
                    </dd></div>
                    <div><dt class="text-zinc-500">Maintainers ({{ $maintainers->count() }})</dt><dd>
                        @if($maintainers->isEmpty())
                            <span class="text-zinc-500">None.</span>
                        @else
                            <ul class="mt-1 space-y-1">
                                @foreach($maintainers as $member)
                                    <li><a href="{{ route('admin.users.show', $member->user) }}" class="font-semibold text-emerald-700">{{ $member->user->name }}</a>
                                        <span class="text-xs text-zinc-500">{{ $member->role->label() }}</span></li>
                                @endforeach
                            </ul>
                        @endif
                    </dd></div>
                </dl>
                <p class="mt-3 text-sm"><a href="{{ route('admin.projects.transfer', $project) }}" class="font-semibold text-emerald-700">Transfer ownership →</a></p>
            </section>
        </div>

        <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-4 sm:p-6">
            <h2 class="font-semibold">Recent administrative history</h2>
            @if($recentAudits->isEmpty())
                <p class="mt-2 text-sm text-zinc-500">No recorded administrative actions for this project.</p>
            @else
                <ul class="mt-2 space-y-2 text-sm">
                    @foreach($recentAudits as $audit)
                        <li>
                            <span class="font-mono text-xs">{{ $audit->created_at->format('Y-m-d H:i') }}</span>
                            <span class="font-semibold">{{ $audit->action }}</span>
                            by {{ $audit->actor?->name ?? 'console' }}
                            @if($audit->reason)<span class="text-zinc-600">— {{ $audit->reason }}</span>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-app-layout>
