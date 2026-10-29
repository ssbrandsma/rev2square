<nav aria-label="Administration" class="mb-6 flex flex-wrap gap-1 border-b border-zinc-200 pb-3 text-sm">
    @php($links = [
        ['match' => 'admin.dashboard', 'route' => 'admin.dashboard', 'label' => 'Overview', 'gate' => 'admin'],
        ['match' => 'admin.users', 'route' => 'admin.users.index', 'label' => 'Users', 'gate' => 'admin'],
        ['match' => 'admin.usage', 'route' => 'admin.usage.index', 'label' => 'AI usage', 'gate' => 'admin'],
        ['match' => 'admin.settings', 'route' => 'admin.settings.index', 'label' => 'Settings', 'gate' => 'admin'],
        ['match' => 'admin.audit', 'route' => 'admin.audit.index', 'label' => 'Audit log', 'gate' => 'admin'],
        ['match' => 'admin.moderation', 'route' => 'admin.moderation.index', 'label' => 'Moderation', 'gate' => 'moderate'],
    ])
    @foreach($links as $link)
        @can($link['gate'])
            @php($active = request()->routeIs($link['match']) || request()->routeIs($link['match'].'.*'))
            <a href="{{ route($link['route']) }}" @class(['rounded-lg px-3 py-2 font-semibold', 'bg-zinc-900 text-white' => $active, 'text-zinc-600 hover:bg-zinc-100' => ! $active])>{{ $link['label'] }}</a>
        @endcan
    @endforeach
</nav>
