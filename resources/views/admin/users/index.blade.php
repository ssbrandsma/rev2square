<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Administration</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">Users</h1>
    </x-slot>
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        @include('admin._nav')
        @if(session('status'))
            <p class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</p>
        @endif
        @if(session('error'))
            <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ session('error') }}</p>
        @endif

        <form method="GET" action="{{ route('admin.users.index') }}" class="mb-4 flex flex-col gap-2 rounded-xl border border-zinc-200 bg-white p-4 text-sm sm:flex-row sm:flex-wrap sm:items-end">
            <label class="flex flex-col gap-1">Search
                <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name or email" class="rounded-lg border border-zinc-300 px-3 py-2 text-sm" />
            </label>
            <label class="flex flex-col gap-1">Role
                <select name="role" class="rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                    <option value="">All</option>
                    @foreach(['user' => 'User', 'moderator' => 'Moderator', 'admin' => 'Admin'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['role'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1">Status
                <select name="status" class="rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                    <option value="">All</option>
                    @foreach(['active' => 'Active', 'suspended' => 'Suspended'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1">From
                <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="rounded-lg border border-zinc-300 px-3 py-2 text-sm" />
            </label>
            <label class="flex flex-col gap-1">To
                <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="rounded-lg border border-zinc-300 px-3 py-2 text-sm" />
            </label>
            <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Filter</button>
            <a href="{{ route('admin.users.index') }}" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50">Clear</a>
        </form>

        <section class="overflow-x-auto rounded-xl border border-zinc-200 bg-white">
            <table class="w-full min-w-[720px] text-left text-sm">
                <thead class="border-b border-zinc-200 text-xs text-zinc-500">
                    <tr>
                        <th class="px-4 py-3">Account</th>
                        <th class="px-4 py-3">Role</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Registered</th>
                        <th class="px-4 py-3">Projects</th>
                        <th class="px-4 py-3"><span class="sr-only">Open</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse($users as $user)
                        <tr>
                            <td class="px-4 py-3">
                                <p class="font-semibold">#{{ $user->id }} {{ $user->name }}</p>
                                <p class="text-xs text-zinc-500">{{ $user->email }}</p>
                            </td>
                            <td class="px-4 py-3">{{ $user->global_role->label() }}</td>
                            <td class="px-4 py-3">
                                {{ $user->account_status->label() }}{{ $user->ai_enabled ? '' : ' · AI off' }}
                            </td>
                            <td class="px-4 py-3">{{ $user->created_at->format('Y-m-d') }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $user->owned_projects_count }} owned · {{ $user->project_memberships_count }} memberships</td>
                            <td class="px-4 py-3"><a href="{{ route('admin.users.show', $user) }}" class="font-semibold text-emerald-700">Open →</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-zinc-500">No accounts match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
        <div class="mt-4">{{ $users->links() }}</div>
    </div>
</x-app-layout>
