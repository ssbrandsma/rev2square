<x-app-layout>
    <x-slot name="title">Session expired</x-slot>
    <div class="mx-auto max-w-2xl px-4 py-20 text-center sm:px-6">
        <p class="text-sm font-semibold text-emerald-700">419</p>
        <h1 class="mt-2 text-3xl font-semibold tracking-tight">Your session expired.</h1>
        <p class="mt-3 text-zinc-600">For your security, idle sessions time out. Anything you typed is kept as a draft in this browser: sign in again, return here, and your text will still be waiting.</p>
        <p class="mt-6 flex flex-wrap justify-center gap-3">
            <a href="{{ route('login') }}" class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">Sign in again</a>
            <a href="{{ url()->previous() }}" class="rounded-lg bg-zinc-100 px-5 py-2.5 text-sm font-semibold text-zinc-800 hover:bg-zinc-200">Back to the form</a>
        </p>
    </div>
</x-app-layout>
