<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ ($title ?? null) ? $title.' — ' : '' }}Rev2Square</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800" rel="stylesheet">
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
                    <span class="block text-lg font-extrabold tracking-tight">Rev2Square</span>
                    <span class="block font-mono text-[11px] uppercase tracking-widest text-zinc-400">Reverse. Revive. Together.</span>
                </span>
            </a>
            <div class="hidden items-center gap-6 md:flex">
                <a href="{{ route('projects.index') }}" class="text-sm font-medium text-zinc-300 hover:text-white">Explore projects</a>
                <a href="{{ route('about') }}" class="text-sm font-medium text-zinc-300 hover:text-white">Methodology</a>
                @auth
                    <a href="{{ route('dashboard') }}" class="text-sm font-medium text-zinc-300 hover:text-white">Dashboard</a>
                    <a href="{{ route('projects.create') }}" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Start a revival</a>
                    <form method="POST" action="{{ route('logout') }}" class="inline">
                        @csrf
                        <button type="submit" class="text-sm font-medium text-zinc-300 hover:text-white">Log out</button>
                    </form>
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
                <a href="{{ route('about') }}" class="block py-2 text-sm font-medium text-zinc-200">Methodology</a>
                @auth
                    <a href="{{ route('dashboard') }}" class="block py-2 text-sm font-medium text-zinc-200">Dashboard</a>
                    <a href="{{ route('projects.create') }}" class="block py-2 text-sm font-semibold text-emerald-400">Start a revival</a>
                    <form method="POST" action="{{ route('logout') }}">
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
                <p class="text-lg font-extrabold tracking-tight text-white">Rev2Square</p>
                <p class="mt-1 font-mono text-xs uppercase tracking-widest">Reverse. Revive. Together.</p>
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
@livewireScripts
</body>
</html>
