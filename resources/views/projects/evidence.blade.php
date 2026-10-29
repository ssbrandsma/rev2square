<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Evidence</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">{{ $project->title }}</h1>
        <p class="mt-1 text-sm text-zinc-600"><a href="{{ route('projects.show', $project) }}" class="font-semibold text-emerald-700">← Back to project</a></p>
    </x-slot>
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        <livewire:projects.evidence-manager :project="$project" />

        <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
            <h2 class="font-semibold">Human sources</h2>
            <p class="mt-1 text-sm text-zinc-600">Owner observations and hardware test reports. The AI investigator does not read them yet — reviewers do.</p>

            <h3 class="mt-5 text-sm font-semibold text-zinc-500">Observations ({{ $observations->count() }})</h3>
            @if($observations->isEmpty())
                <p class="mt-2 text-sm text-zinc-500">No observations yet.</p>
            @else
                <ul class="mt-2 divide-y divide-zinc-100 text-sm">
                    @foreach($observations as $observation)
                        <li class="py-2">
                            <span class="font-semibold">{{ $observation->title }}</span>
                            <span class="text-zinc-500">— {{ $observation->author->name }}, {{ $observation->created_at->toDateString() }}</span>
                            @if($observation->variantLabel())<span class="text-zinc-500"> · {{ $observation->variantLabel() }}</span>@endif
                            @if($observation->isVerified())
                                <span class="ml-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-900">Verified hardware claim</span>
                            @else
                                <span class="ml-1 rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-semibold text-zinc-700">Unverified</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            <h3 class="mt-5 text-sm font-semibold text-zinc-500">Test reports ({{ $testReports->count() }})</h3>
            @if($testReports->isEmpty())
                <p class="mt-2 text-sm text-zinc-500">No test reports yet.</p>
            @else
                <ul class="mt-2 divide-y divide-zinc-100 text-sm">
                    @foreach($testReports as $testReport)
                        <li class="py-2">
                            <a href="{{ route('projects.test-reports.show', [$project, $testReport]) }}" class="font-semibold hover:text-emerald-700">{{ $testReport->title }}</a>
                            <span class="text-zinc-500">— {{ $testReport->author->name }}, {{ $testReport->created_at->toDateString() }}, {{ $testReport->outcome->label() }}</span>
                            @if($testReport->variantLabel())<span class="text-zinc-500"> · {{ $testReport->variantLabel() }}</span>@endif
                            @if($testReport->isConfirmed())
                                <span class="ml-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-900">Confirmed</span>
                            @else
                                <span class="ml-1 rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-semibold text-zinc-700">Unconfirmed</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            <p class="mt-4 text-sm"><a href="{{ route('projects.show', $project) }}#observations" class="font-semibold text-emerald-700 hover:text-emerald-600">Manage observations and test reports on the project page →</a></p>
        </section>
    </div>
</x-app-layout>
