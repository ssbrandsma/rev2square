<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @auth
        <meta name="user-id" content="{{ auth()->id() }}">
    @endauth
    <title>{{ ($title ?? null) ? $title.' — ' : '' }}Rev2Square</title>
    <!-- Type is self-hosted (Source Sans 3 via Fontsource); no external font requests. -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-white font-sans text-zinc-900 antialiased">
<div class="flex min-h-screen flex-col">
    <header class="bg-zinc-950 text-white">
        <nav class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-4 sm:px-6" x-data="{ open: false }">
            <a href="{{ route('home') }}" class="flex items-center gap-3">
                <svg width="30" height="30" viewBox="0 0 30 30" aria-hidden="true" class="shrink-0">
                    <rect x="1" y="1" width="13" height="13" rx="2" fill="#10b981"/>
                    <rect x="16" y="1" width="13" height="13" rx="2" fill="#3f3f46"/>
                    <rect x="1" y="16" width="13" height="13" rx="2" fill="#3f3f46"/>
                    <rect x="16" y="16" width="13" height="13" rx="2" fill="#fbbf24"/>
                </svg>
                <span class="leading-tight">
                    <span class="block text-lg font-semibold tracking-tight">Rev2Square</span>
                    <span class="block text-xs font-semibold text-zinc-400">Reverse. Revive. Together.</span>
                </span>
            </a>
            <div class="hidden items-center gap-6 md:flex">
                <a href="{{ route('projects.index') }}" class="text-sm font-medium text-zinc-300 hover:text-white">Explore projects</a>
                <a href="{{ route('lounge') }}" class="text-sm font-medium text-zinc-300 hover:text-white">Lounge</a>
                <a href="{{ route('about') }}" class="text-sm font-medium text-zinc-300 hover:text-white">Methodology</a>
                @auth
                    <a href="{{ route('dashboard') }}" class="text-sm font-medium text-zinc-300 hover:text-white">My workspace</a>
                    @php($unreadCount = auth()->user()->unreadNotifications()->count())
                    <a href="{{ route('inbox.index') }}" class="text-sm font-medium text-zinc-300 hover:text-white" aria-label="Inbox{{ $unreadCount > 0 ? ", {$unreadCount} unread" : '' }}">Inbox @if($unreadCount > 0)<span class="ml-1 rounded-full bg-emerald-600 px-1.5 py-0.5 text-[11px] font-bold text-white">{{ $unreadCount }}</span>@endif</a>
                    <a href="{{ route('projects.create') }}" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Start a revival</a>
                    <x-account-menu />
                @else
                    <a href="{{ route('login') }}" class="text-sm font-medium text-zinc-300 hover:text-white">Log in</a>
                    <a href="{{ route('register') }}" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Join</a>
                @endauth
            </div>
            <button type="button" @click="open = !open" class="rounded-lg p-2 text-zinc-300 hover:bg-zinc-800 md:hidden" aria-label="Toggle menu">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <div x-show="open" x-cloak class="absolute inset-x-0 top-[72px] z-50 border-t border-zinc-800 bg-zinc-950 px-4 pb-6 pt-2 md:hidden">
                <a href="{{ route('projects.index') }}" class="block py-2 text-sm font-medium text-zinc-200">Explore projects</a>
                <a href="{{ route('lounge') }}" class="block py-2 text-sm font-medium text-zinc-200">Lounge</a>
                <a href="{{ route('about') }}" class="block py-2 text-sm font-medium text-zinc-200">Methodology</a>
                @auth
                    <div class="flex items-center gap-3 border-b border-zinc-800 py-3">
                        <x-avatar :user="auth()->user()" size="sm" />
                        <div class="min-w-0">
                            <p class="truncate text-sm font-bold text-white">{{ auth()->user()->name }}</p>
                            <p class="truncate text-xs text-zinc-400">@if(auth()->user()->handle){{ '@'.auth()->user()->handle }}@else{{ auth()->user()->email }}@endif</p>
                        </div>
                    </div>
                    <a href="{{ auth()->user()->profileUrl() }}" class="block py-2 text-sm font-medium text-zinc-200">My public profile</a>
                    <a href="{{ route('dashboard') }}" class="block py-2 text-sm font-medium text-zinc-200">My workspace</a>
                    <a href="{{ route('dashboard') }}#saved" class="block py-2 text-sm font-medium text-zinc-200">Saved projects</a>
                    <a href="{{ route('inbox.index') }}" class="block py-2 text-sm font-medium text-zinc-200">Inbox</a>
                    <a href="{{ route('profile.edit') }}" class="block py-2 text-sm font-medium text-zinc-200">Edit profile &amp; settings</a>
                    <a href="{{ route('projects.create') }}" class="block py-2 text-sm font-semibold text-emerald-400">Start a revival</a>
                    <form method="POST" action="{{ route('logout') }}" data-logout>
                        @csrf
                        <button type="submit" class="block w-full py-2 text-left text-sm font-medium text-zinc-200">Log out</button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="block py-2 text-sm font-medium text-zinc-200">Log in</a>
                    <a href="{{ route('register') }}" class="block py-2 text-sm font-semibold text-emerald-400">Join</a>
                @endauth
            </div>
        </nav>
    </header>

    @isset($header)
        <div class="border-b border-zinc-200 bg-zinc-50">
            <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6">{{ $header }}</div>
        </div>
    @endisset

    <main class="flex-1">{{ $slot }}</main>

    <footer class="bg-zinc-950 text-zinc-400">
        <div class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-10 sm:px-6 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-lg font-semibold tracking-tight text-white">Rev2Square</p>
                <p class="mt-1 text-xs font-medium text-zinc-400">Reverse. Revive. Together.</p>
                <p class="mt-3 max-w-md text-sm">A community for reverse engineering, restoring, and sustaining abandoned hardware.</p>
            </div>
            <div class="flex gap-6 text-sm">
                <a href="{{ route('projects.index') }}" class="hover:text-white">Explore</a>
                <a href="{{ route('about') }}" class="hover:text-white">Methodology</a>
            </div>
        </div>
        <div class="border-t border-zinc-800">
            <p class="mx-auto max-w-6xl px-4 py-4 text-xs sm:px-6">Built for the Meta Global AI Developer Hackathon 2026 · MIT licensed</p>
        </div>
    </footer>
</div>
@auth
    <div data-chat-launcher data-unread-url="{{ route('chat.unread') }}" class="fixed bottom-24 right-4 z-40 sm:bottom-4">
        <div data-launcher-panel class="mb-2 hidden w-72 rounded-xl border border-zinc-200 bg-white p-2 shadow-xl">
            <p class="px-3 pb-1 pt-2 text-sm font-semibold text-zinc-500">Your chats</p>
            <ul data-launcher-rooms class="max-h-72 space-y-1 overflow-y-auto"></ul>
            <p class="px-3 py-2 text-xs text-zinc-500"><a href="{{ route('lounge') }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Open the lounge →</a></p>
        </div>
        <button type="button" data-launcher-toggle aria-label="Open chat rooms"
            class="relative flex h-12 w-12 items-center justify-center rounded-full bg-emerald-600 text-white shadow-lg hover:bg-emerald-500">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8m-8 4h5m7-2a8 8 0 01-8 8H6l-3 3v-5.5A8 8 0 0111 6h6a8 8 0 016 6.5z" transform="translate(-1 -1) scale(0.92)" /></svg>
            <span data-launcher-dot class="absolute -right-0.5 -top-0.5 hidden h-3.5 w-3.5 rounded-full border-2 border-white bg-red-500" aria-label="Unread chat activity"></span>
        </button>
    </div>
@endauth
@livewireScripts
</body>
</html>
