<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Edit project</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">{{ $project->title }}</h1>
    </x-slot>
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        <form method="POST" action="{{ route('projects.update', $project) }}" class="rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
            @csrf
            @method('PUT')
            @include('projects._form', ['project' => $project])
            <div class="mt-6 flex items-center justify-end gap-3 border-t border-zinc-100 pt-6">
                <a href="{{ route('projects.show', $project) }}" class="text-sm font-semibold text-zinc-600 hover:text-zinc-900">Cancel</a>
                <button type="submit" class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">Save changes</button>
            </div>
        </form>
        <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
            <h2 class="text-sm font-semibold text-zinc-500">Publication checklist</h2>
            <p class="mt-1 text-sm text-zinc-500">Early-stage publishing is welcome — this list only shows what visitors will (and will not) find. Nothing here blocks publishing.</p>
            @php($hasDevice = $project->devices()->exists())
            @php($hasEvidence = $project->repositories()->where('import_status', 'ready')->exists() || $project->observations()->exists() || $project->testReports()->exists())
            @php($openTasks = $project->tasks()->active()->count())
            <ul class="mt-3 space-y-2 text-sm">
                <li>{{ $project->pitch && $project->body ? '✓' : '○' }} <span class="font-semibold">Purpose written</span> <span class="text-zinc-500">— pitch and story explain what this revival is about.</span></li>
                <li>{{ $hasDevice ? '✓' : '○' }} <span class="font-semibold">Device described</span> <span class="text-zinc-500">— {{ $hasDevice ? 'a device is linked.' : 'no device linked yet; the page says so explicitly.' }}</span></li>
                <li>{{ $hasEvidence ? '✓' : '○' }} <span class="font-semibold">Evidence started</span> <span class="text-zinc-500">— {{ $hasEvidence ? 'repository evidence or human sources exist.' : 'no evidence yet; import a repository or share an observation.' }}</span></li>
                <li>✓ <span class="font-semibold">Maintainer identity</span> <span class="text-zinc-500">— published as {{ $project->owner->name }}.</span></li>
                <li>{{ $openTasks > 0 ? '✓' : '○' }} <span class="font-semibold">Next steps visible</span> <span class="text-zinc-500">— {{ $openTasks > 0 ? $openTasks.' active task(s) for contributors.' : 'no active tasks yet.' }}</span></li>
            </ul>
            <p class="mt-3 text-sm"><a href="{{ route('projects.show', $project) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Preview the public page →</a></p>
        </section>
        <form method="POST" action="{{ route('projects.destroy', $project) }}" class="mt-4" onsubmit="return confirm('Delete this project and its milestones and tasks?');">
            @csrf
            @method('DELETE')
            <button type="submit" class="text-sm font-semibold text-red-600 hover:text-red-500">Delete project</button>
        </form>
    </div>
</x-app-layout>
