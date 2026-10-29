<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Inbox</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">Notifications</h1>
    </x-slot>
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        @if(session('status'))
            <p class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</p>
        @endif

        <div class="mb-4 flex items-center justify-between">
            <p class="text-sm text-zinc-500">{{ auth()->user()->unreadNotifications()->count() }} unread. Rev²Square only notifies you in-app; no emails are sent.</p>
            <form method="POST" action="{{ route('inbox.read-all') }}" class="inline">
                @csrf
                @method('PATCH')
                <button type="submit" class="text-sm font-semibold text-emerald-700 hover:text-emerald-600">Mark all as read</button>
            </form>
        </div>

        <div class="space-y-3">
            @forelse($notifications as $notification)
                <a href="{{ route('inbox.read', $notification->id) }}"
                    class="block rounded-xl border p-5 transition hover:border-emerald-300 {{ $notification->read_at ? 'border-zinc-200 bg-white' : 'border-emerald-200 bg-emerald-50/50' }}">
                    <div class="flex items-start justify-between gap-3">
                        <h2 class="font-semibold">{{ $notification->data['title'] ?? 'Notification' }}</h2>
                        @unless($notification->read_at)
                            <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-emerald-600" aria-label="Unread"></span>
                        @endunless
                    </div>
                    @if(! empty($notification->data['body']))
                        <p class="mt-1 text-sm text-zinc-600">{{ \Illuminate\Support\Str::limit($notification->data['body'], 240) }}</p>
                    @endif
                    <p class="mt-2 text-xs text-zinc-500">{{ $notification->created_at->diffForHumans() }}</p>
                </a>
            @empty
                <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-600">Nothing here yet.</p>
            @endforelse
        </div>

        <div class="mt-6">
            {{ $notifications->links() }}
        </div>
    </div>
</x-app-layout>
