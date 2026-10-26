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
</head>
<body class="bg-zinc-950 font-sans text-zinc-900 antialiased">
<div class="flex min-h-screen flex-col items-center px-4 py-10 sm:justify-center">
    <a href="{{ route('home') }}" class="flex items-center gap-3">
        <svg width="34" height="34" viewBox="0 0 30 30" aria-hidden="true">
            <rect x="1" y="1" width="13" height="13" rx="2" fill="#10b981"/>
            <rect x="16" y="1" width="13" height="13" rx="2" fill="#3f3f46"/>
            <rect x="1" y="16" width="13" height="13" rx="2" fill="#3f3f46"/>
            <rect x="16" y="16" width="13" height="13" rx="2" fill="#fbbf24"/>
        </svg>
        <span class="leading-tight">
            <span class="block text-xl font-extrabold tracking-tight text-white">Rev2Square</span>
            <span class="block font-mono text-[11px] uppercase tracking-widest text-zinc-400">Reverse. Revive. Together.</span>
        </span>
    </a>
    <div class="mt-6 w-full max-w-md overflow-hidden rounded-xl bg-white px-6 py-8 shadow-2xl sm:px-8">
        {{ $slot }}
    </div>
    <p class="mt-6 text-center text-xs text-zinc-500">
        <a href="{{ route('home') }}" class="underline hover:text-zinc-300">Back to Rev2Square</a>
    </p>
</div>
</body>
</html>
