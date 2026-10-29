<x-app-layout>
    <section class="bg-zinc-950 text-white">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-24">
            <div class="grid items-center gap-10 md:grid-cols-5">
                <div class="md:col-span-3">
                    <p class="text-sm font-semibold text-emerald-400">Abandoned hardware · Community engineering</p>
                    <h1 class="mt-4 text-4xl font-semibold leading-tight tracking-tight sm:text-5xl">Reverse Engineer.<br>Revive. Together.</h1>
                    <p class="mt-5 max-w-xl text-lg text-zinc-300">Give abandoned hardware a second life. Reverse engineer together — and keep useful devices out of e-waste.</p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        <a href="{{ route('projects.index') }}" class="rounded-lg bg-emerald-600 px-6 py-3 text-sm font-semibold text-white hover:bg-emerald-500">Explore projects</a>
                        <a href="{{ route('projects.create') }}" class="rounded-lg border border-zinc-700 px-6 py-3 text-sm font-semibold text-white hover:border-zinc-500">Start a revival</a>
                    </div>
                </div>
                <figure class="overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900 md:col-span-2">
                    <img src="{{ asset('images/hero-board.svg') }}" width="640" height="520" fetchpriority="high" decoding="async" alt="Illustration of circuit-board traces converging on a chip with test points" class="h-auto w-full">
                </figure>
            </div>
            <div class="mt-14 border-t border-zinc-800 pt-10">
                <ol aria-label="How a revival works" class="grid gap-8 md:grid-cols-3">
                    <li class="flex gap-4">
                        <span class="text-sm font-semibold text-amber-400">01</span>
                        <div><p class="font-semibold">Gather the evidence</p><p class="mt-1 text-sm text-zinc-400">Repositories, docs, and owner observations, linked to their sources.</p></div>
                    </li>
                    <li class="flex gap-4">
                        <span class="text-sm font-semibold text-amber-400">02</span>
                        <div><p class="font-semibold">Investigate with AI <span class="ml-1 rounded-full bg-zinc-800 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-300">Experimental</span></p><p class="mt-1 text-sm text-zinc-400">Analyze with AI over the evidence; drafts until a maintainer approves.</p></div>
                    </li>
                    <li class="flex gap-4">
                        <span class="text-sm font-semibold text-amber-400">03</span>
                        <div><p class="font-semibold">Revive as a community</p><p class="mt-1 text-sm text-zinc-400">Milestones, tasks, and real hardware testing.</p></div>
                    </li>
                </ol>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="flex items-end justify-between gap-4">
            <div>
                <h2 class="text-2xl font-semibold tracking-tight">Featured revival projects</h2>
            </div>
            <a href="{{ route('projects.index') }}" class="shrink-0 text-sm font-semibold text-emerald-700 hover:text-emerald-600">All projects →</a>
        </div>
        @if($featured->isEmpty())
            <p class="mt-6 rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center text-sm text-zinc-600">No published projects yet. Be the first to <a href="{{ route('projects.create') }}" class="font-semibold text-emerald-700">start a revival</a>.</p>
        @else
            <div class="mt-6 grid gap-6 md:grid-cols-2">
                @foreach($featured as $project)
                    @include('projects._card', ['project' => $project])
                @endforeach
            </div>
        @endif
    </section>

    <section class="border-t border-zinc-200 bg-zinc-50">
        <div class="mx-auto grid max-w-6xl gap-8 px-4 py-14 sm:px-6 md:grid-cols-2">
            <div class="flex gap-4">
                <img src="{{ asset('images/spot-evidence.svg') }}" width="120" height="120" loading="lazy" decoding="async" alt="" aria-hidden="true" class="h-16 w-16 shrink-0">
                <div>
                    <h2 class="text-2xl font-semibold tracking-tight">Evidence before confidence</h2>
                    <p class="mt-3 text-zinc-600">Nothing counts as working until a real test says so.</p>
                    <a href="{{ route('about') }}" class="mt-4 inline-block text-sm font-semibold text-emerald-700 hover:text-emerald-600">Read our methodology →</a>
                </div>
            </div>
            <div class="flex gap-4">
                <img src="{{ asset('images/spot-community.svg') }}" width="120" height="120" loading="lazy" decoding="async" alt="" aria-hidden="true" class="h-16 w-16 shrink-0">
                <div>
                    <h2 class="text-2xl font-semibold tracking-tight">Contributors over donations</h2>
                    <p class="mt-3 text-zinc-600">Code, research, docs, hardware loans, and testing all count.</p>
                    <a href="{{ route('tasks.index') }}" class="mt-4 inline-block text-sm font-semibold text-emerald-700 hover:text-emerald-600">Find a task →</a>
                </div>
            </div>
        </div>
    </section>
</x-app-layout>
