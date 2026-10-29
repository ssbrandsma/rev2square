<x-app-layout>
    <section class="bg-zinc-950 text-white">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-24">
            <div class="grid items-center gap-10 md:grid-cols-5">
                <div class="md:col-span-3">
                    <p class="text-sm font-semibold text-emerald-400">Abandoned hardware · Community engineering</p>
                    <h1 class="mt-4 text-4xl font-semibold leading-tight tracking-tight sm:text-5xl">Reverse Engineer.<br>Revive. Together.</h1>
                    <p class="mt-5 max-w-xl text-lg text-zinc-300">Bring abandoned hardware and software back to life with AI-assisted reverse engineering and an open-source community. Rev²Square gathers the scattered evidence, investigates what revival would take, and organizes the people who can do the work.</p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        <a href="{{ route('projects.index') }}" class="rounded-lg bg-emerald-600 px-6 py-3 text-sm font-semibold text-white hover:bg-emerald-500">Explore revival projects</a>
                        <a href="{{ route('projects.create') }}" class="rounded-lg border border-zinc-700 px-6 py-3 text-sm font-semibold text-white hover:border-zinc-500">Start a revival</a>
                    </div>
                </div>
                <figure class="overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900 md:col-span-2">
                    <img src="{{ asset('images/hero-board.svg') }}" width="640" height="520" fetchpriority="high" decoding="async" alt="Illustration of circuit-board traces converging on a chip with test points" class="h-auto w-full">
                    <figcaption class="border-t border-zinc-800 px-5 py-3 text-xs font-medium text-zinc-400">Every claim traced to its source.</figcaption>
                </figure>
            </div>
            <div class="mt-14 border-t border-zinc-800 pt-10">
                <p class="text-sm font-semibold text-zinc-400">How a revival works</p>
                <ol class="mt-6 grid gap-8 md:grid-cols-3">
                    <li class="flex gap-4">
                        <span class="text-sm font-semibold text-amber-400">01</span>
                        <div><p class="font-semibold">Gather the evidence</p><p class="mt-1 text-sm text-zinc-400">Repositories, docs, and owner observations — every claim linked to its source.</p></div>
                    </li>
                    <li class="flex gap-4">
                        <span class="text-sm font-semibold text-amber-400">02</span>
                        <div><p class="font-semibold">Investigate with AI assistance <span class="ml-1 rounded-full bg-zinc-800 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-300">Experimental</span></p><p class="mt-1 text-sm text-zinc-400">Analyze with AI inside any project: feasibility, competing hypotheses, and testable experiments — always drafts until a human maintainer approves them.</p></div>
                    </li>
                    <li class="flex gap-4">
                        <span class="text-sm font-semibold text-amber-400">03</span>
                        <div><p class="font-semibold">Revive as a community</p><p class="mt-1 text-sm text-zinc-400">Milestones, contributor tasks, real hardware testing, verified outcomes.</p></div>
                    </li>
                </ol>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="flex items-end justify-between gap-4">
            <div>
                <p class="text-sm font-semibold text-emerald-700">Featured revival projects</p>
                <h2 class="mt-2 text-2xl font-semibold tracking-tight">Real devices, real investigations</h2>
                <p class="mt-1 text-sm text-zinc-500">Published projects with a linked device, newest first. Drafts, archived projects, and early-stage projects without a device stay in Explore.</p>
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
                    <p class="mt-3 text-zinc-600">Every revival project separates what sources say, what is reasonable inference, and what is still an unverified hypothesis. Nothing is presented as working until a real test says so.</p>
                    <a href="{{ route('about') }}" class="mt-4 inline-block text-sm font-semibold text-emerald-700 hover:text-emerald-600">Read our methodology →</a>
                </div>
            </div>
            <div class="flex gap-4">
                <img src="{{ asset('images/spot-community.svg') }}" width="120" height="120" loading="lazy" decoding="async" alt="" aria-hidden="true" class="h-16 w-16 shrink-0">
                <div>
                    <h2 class="text-2xl font-semibold tracking-tight">Contributors over donations</h2>
                    <p class="mt-3 text-zinc-600">Code, protocol research, documentation, hardware loans, and reproducible testing are first-class contributions. There is always a concrete next task waiting for the right skills.</p>
                    <a href="{{ route('tasks.index') }}" class="mt-4 inline-block text-sm font-semibold text-emerald-700 hover:text-emerald-600">Find a task →</a>
                </div>
            </div>
        </div>
    </section>
</x-app-layout>
