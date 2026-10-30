<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Administration</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">Projects</h1>
    </x-slot>
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        @include('admin._nav')
        @if(session('status'))
            <p class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</p>
        @endif
        @if(session('error'))
            <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ session('error') }}</p>
        @endif

        <form method="GET" action="{{ route('admin.projects.index') }}" class="mb-4 flex flex-col gap-2 rounded-xl border border-zinc-200 bg-white p-4 text-sm sm:flex-row sm:flex-wrap sm:items-end">
            <label class="flex flex-col gap-1">Search
                <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Title, slug, or owner" class="rounded-lg border border-zinc-300 px-3 py-2 text-sm" />
            </label>
            <label class="flex flex-col gap-1">Publication
                <select name="publication" class="rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                    <option value="">All</option>
                    @foreach(\App\Enums\PublicationStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected(($filters['publication'] ?? '') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1">Activity
                <select name="activity" class="rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                    <option value="">All</option>
                    @foreach(\App\Enums\ProjectActivity::cases() as $activity)
                        <option value="{{ $activity->value }}" @selected(($filters['activity'] ?? '') === $activity->value)>{{ $activity->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1">Phase
                <select name="phase" class="rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                    <option value="">All</option>
                    @foreach(\App\Enums\ProjectPhase::cases() as $phase)
                        <option value="{{ $phase->value }}" @selected(($filters['phase'] ?? '') === $phase->value)>{{ $phase->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1">Owner status
                <select name="owner_status" class="rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                    <option value="">All</option>
                    <option value="active" @selected(($filters['owner_status'] ?? '') === 'active')>Active</option>
                    <option value="suspended" @selected(($filters['owner_status'] ?? '') === 'suspended')>Suspended</option>
                </select>
            </label>
            <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Filter</button>
            <a href="{{ route('admin.projects.index') }}" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50">Clear</a>
        </form>

        <p class="mb-2 text-sm text-zinc-500" role="status">{{ $projects->total() }} {{ Str::plural('project', $projects->total()) }} found.</p>

        <section class="overflow-x-auto rounded-xl border border-zinc-200 bg-white">
            <table class="w-full min-w-[880px] text-left text-sm">
                <thead class="border-b border-zinc-200 text-xs text-zinc-500">
                    <tr>
                        <th class="px-4 py-3">Project</th>
                        <th class="px-4 py-3">Owner</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Team</th>
                        <th class="px-4 py-3">Created</th>
                        <th class="px-4 py-3"><span class="sr-only">Open</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse($projects as $project)
                        <tr>
                            <td class="px-4 py-3">
                                <p class="font-semibold">{{ $project->title }}</p>
                                <p class="font-mono text-xs text-zinc-500">#{{ $project->id }} · /{{ $project->slug }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.users.show', $project->owner) }}" class="font-semibold text-emerald-700">{{ $project->owner->name }}</a>
                                @if($project->owner->isSuspended())
                                    <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Owner suspended</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <span class="rounded-full px-2 py-0.5 font-semibold {{ $project->isPublished() ? 'bg-emerald-100 text-emerald-800' : 'bg-zinc-100 text-zinc-700' }}">{{ $project->publication_status->label() }}</span>
                                <span class="text-zinc-500">{{ $project->activity->label() }} · {{ $project->phase->label() }}</span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $project->members_count }} members · {{ $project->tasks_count }} tasks</td>
                            <td class="whitespace-nowrap px-4 py-3 text-xs text-zinc-500">{{ $project->created_at->format('Y-m-d') }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-xs">
                                @can('view', $project)
                                    <a href="{{ route('projects.show', $project) }}" class="font-semibold text-emerald-700">Project →</a>
                                @endcan
                                <a href="{{ route('admin.projects.show', $project) }}" class="ml-2 font-semibold text-emerald-700">Manage →</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-zinc-500">No projects match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
        <div class="mt-4">{{ $projects->links() }}</div>
        <p class="mt-2 text-xs text-zinc-500">This listing shows administrative metadata only. Opening project content still follows project authorization.</p>
    </div>
</x-app-layout>
