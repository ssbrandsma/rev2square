<x-app-layout>
    <x-slot name="title">{{ $project->title }}</x-slot>
    <section class="bg-zinc-950 bg-no-repeat text-white" style="background-image: url('{{ asset('images/card-pattern.svg') }}'); background-position: right center; background-size: 800px auto;">
        <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6">
            @include('projects._cover', ['project' => $project])
            <p class="text-sm font-semibold text-zinc-400">{{ $project->devices->first()?->displayName() ?? 'Device to be linked' }}</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl">{{ $project->title }}</h1>
            @if($project->pitch)
                <p class="mt-3 max-w-2xl text-lg text-zinc-300">{{ $project->pitch }}</p>
            @endif
            <div class="mt-4 flex flex-wrap items-center gap-2">
                <span class="rounded-full bg-emerald-900/60 px-3 py-1 text-xs font-semibold text-emerald-300">{{ $project->phase->label() }}</span>
                <span class="rounded-full bg-zinc-800 px-3 py-1 text-xs font-semibold text-zinc-200">{{ $project->activity->label() }}</span>
                @unless($project->isPublished())
                    <span class="rounded-full bg-amber-900/60 px-3 py-1 text-xs font-semibold text-amber-300">Draft — visible to maintainers only</span>
                @endunless
                @can('update', $project)
                    <a href="{{ route('projects.edit', $project) }}" class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-zinc-900 hover:bg-zinc-200">Edit project</a>
                    @php($pendingOffers = $project->offers()->where('status', 'pending')->count())
                    <a href="{{ route('projects.offers.index', $project) }}" class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-zinc-900 hover:bg-zinc-200">{{ $pendingOffers > 0 ? "Offers — {$pendingOffers} pending" : 'Offers' }}</a>
                @endcan
                @auth
                    @if($project->follows()->where('user_id', auth()->id())->exists())
                        <form method="POST" action="{{ route('projects.unfollow', $project) }}" class="inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-zinc-900 hover:bg-zinc-200">Following ✓</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('projects.follow', $project) }}" class="inline">
                            @csrf
                            <button type="submit" class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-zinc-900 hover:bg-zinc-200">Follow</button>
                        </form>
                    @endif
                    @if($project->saves()->where('user_id', auth()->id())->exists())
                        <form method="POST" action="{{ route('projects.unsave', $project) }}" class="inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-zinc-900 hover:bg-zinc-200">Saved ✓</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('projects.save', $project) }}" class="inline">
                            @csrf
                            <button type="submit" class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-zinc-900 hover:bg-zinc-200">Save</button>
                        </form>
                    @endif
                @endauth
            </div>
        </div>
    </section>

    @include('projects._nav', ['project' => $project, 'active' => 'overview'])

    <div class="mx-auto grid max-w-6xl gap-10 px-4 py-12 sm:px-6 lg:grid-cols-3">
        <div class="space-y-10 lg:col-span-2">
            @if($project->activity === \App\Enums\ProjectActivity::SeekingMaintainer && ! $project->managedBy(auth()->user()))
                <section class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-6">
                    <p class="text-sm font-semibold text-emerald-800">Seeking maintainer</p>
                    <p class="mt-2 text-[15px] text-zinc-800">This project is looking for someone to maintain it. Applications are reviewed by the owner, and access only changes after you explicitly accept an invitation.</p>
                    <p class="mt-3"><a href="{{ route('projects.offers.create', [$project, 'type' => \App\Enums\OfferType::MaintainProject->value]) }}" class="inline-block rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">Apply to maintain →</a></p>
                </section>
            @endif
            @php($publishedReport = $project->reports()->whereNotNull('published_at')->first())
            @if($publishedReport)
                @php($assessment = $publishedReport->payload_json)
                <section class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-6">
                    <p class="text-sm font-semibold text-emerald-800">Revival assessment · v{{ $publishedReport->version_number }} · maintainer-approved</p>
                    <p class="mt-2 leading-relaxed text-zinc-800">{{ $assessment['summary'] ?? '' }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach($assessment['revival_paths'] ?? [] as $path)
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold
                                @if($path['feasibility'] === 'PROMISING') bg-emerald-100 text-emerald-900
                                @elseif($path['feasibility'] === 'HIGH_RISK') bg-red-100 text-red-900
                                @elseif($path['feasibility'] === 'INSUFFICIENT_EVIDENCE') bg-zinc-200 text-zinc-800
                                @else bg-amber-100 text-amber-900 @endif">{{ $path['feasibility'] }}: {{ \Illuminate\Support\Str::limit($path['approach'], 60) }}</span>
                        @endforeach
                    </div>
                    <p class="mt-3 text-sm"><a href="{{ route('projects.reports.show', [$project, $publishedReport]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Read the full assessment with sources →</a></p>
                </section>
            @endif
            @include('projects._story', ['project' => $project])
            @include('projects._gallery', ['project' => $project])
            @include('projects._media-files', ['project' => $project])

            <section id="roadmap">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-zinc-500">Roadmap</h2>
                    @can('update', $project)
                        <a href="{{ route('projects.milestones.create', $project) }}" class="text-sm font-semibold text-emerald-700 hover:text-emerald-600">+ Add milestone</a>
                    @endcan
                </div>
                <div class="mt-3 space-y-4">
                    @forelse($milestones as $milestone)
                        <div class="rounded-xl border border-zinc-200 p-5">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="font-bold">{{ $milestone->title }}</h3>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-semibold text-zinc-700">{{ $milestone->status->label() }}</span>
                                    <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">{{ $milestone->origin->label() }}</span>
                                    @if($milestone->target_date)
                                        <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-semibold text-zinc-700">Target {{ $milestone->target_date->toDateString() }}</span>
                                    @endif
                                </div>
                            </div>
                            @if($milestone->description)
                                <p class="mt-2 text-sm text-zinc-600">{{ $milestone->description }}</p>
                            @endif
                            @can('update', $project)
                                <div class="mt-3 flex flex-wrap items-center gap-3 border-t border-zinc-100 pt-3 text-sm">
                                    <a href="{{ route('projects.milestones.edit', [$project, $milestone]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Edit</a>
                                    @if($milestone->isRetired())
                                        <form method="POST" action="{{ route('projects.milestones.restore', [$project, $milestone]) }}" class="inline">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="font-semibold text-emerald-700 hover:text-emerald-600">Restore</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('projects.milestones.retire', [$project, $milestone]) }}" class="inline">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="font-semibold text-zinc-500 hover:text-zinc-800" onclick="return confirm('Retire this milestone? Its tasks stay visible under Help wanted.')">Retire</button>
                                        </form>
                                    @endif
                                </div>
                            @endcan
                        </div>
                    @empty
                        <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-600">No milestones yet.</p>
                    @endforelse
                </div>
                @if($retiredMilestoneCount > 0)
                    <p class="mt-3 text-sm text-zinc-500">{{ $retiredMilestoneCount }} retired {{ Str::plural('milestone', $retiredMilestoneCount) }} hidden. <a href="{{ route('projects.show', [$project, 'show_retired' => 1]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Show retired</a></p>
                @elseif($showRetired)
                    <p class="mt-3 text-sm"><a href="{{ route('projects.show', $project) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Hide retired</a></p>
                @endif
            </section>

            <section id="tasks">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-zinc-500">Help wanted</h2>
                    @can('update', $project)
                        <a href="{{ route('projects.tasks.create', $project) }}" class="text-sm font-semibold text-emerald-700 hover:text-emerald-600">+ Add task</a>
                    @endcan
                </div>
                <div class="mt-3 space-y-4">
                    @forelse($tasks as $task)
                        <div class="rounded-xl border border-zinc-200 p-5">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="font-bold"><a href="{{ route('projects.tasks.show', [$project, $task]) }}" class="hover:text-emerald-700">{{ $task->title }}</a></h3>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-semibold text-zinc-700">{{ $task->status->label() }}</span>
                                    <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">{{ $task->origin->label() }}</span>
                                </div>
                            </div>
                            @if($task->description)
                                <p class="mt-2 text-sm text-zinc-600">{{ \Illuminate\Support\Str::limit($task->description, 200) }}</p>
                            @endif
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                @foreach($task->skillTags() as $skill)
                                    <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-[11px] font-semibold text-zinc-700">{{ $skill }}</span>
                                @endforeach
                                @if($task->requires_hardware)
                                    <span class="rounded-full bg-amber-100 px-2.5 py-0.5 font-mono text-[11px] font-semibold text-amber-800">needs hardware</span>
                                @endif
                                @if($task->assignee)
                                    <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-[11px] font-semibold text-zinc-700">→ {{ $task->assignee->name }}</span>
                                @endif
                            </div>
                            <p class="mt-3 flex flex-wrap gap-4">
                                <a href="{{ route('projects.tasks.show', [$project, $task]) }}" class="text-sm font-semibold text-emerald-700 hover:text-emerald-600">Details →</a>
                                @if($task->status === \App\Enums\TaskStatus::Open && ! $contributionDenial)
                                    <a href="{{ route('projects.offers.create', [$project, 'task' => $task->id]) }}" class="text-sm font-semibold text-emerald-700 hover:text-emerald-600">Help with this task →</a>
                                @endif
                            </p>
                        </div>
                    @empty
                        <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-600">No contributor tasks yet.</p>
                    @endforelse
                </div>
                @if($retiredTaskCount > 0)
                    <p class="mt-3 text-sm text-zinc-500">{{ $retiredTaskCount }} retired {{ Str::plural('task', $retiredTaskCount) }} hidden. <a href="{{ route('projects.show', [$project, 'show_retired' => 1]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Show retired</a></p>
                @endif
                @if($contributionDenial)
                    <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ $contributionDenial }}</p>
                @else
                    <p class="mt-4"><a href="{{ route('projects.offers.create', $project) }}" class="inline-block rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">Offer help →</a></p>
                @endif
            </section>

            <section id="observations">
                <h2 class="text-sm font-semibold text-zinc-500">Field observations</h2>
                <p class="mt-1 text-sm text-zinc-500">What members found on real hardware.</p>
                <div class="mt-3 space-y-4">
                    @forelse($observations as $observation)
                        <article class="rounded-xl border border-zinc-200 p-5">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="font-bold">{{ $observation->title }}</h3>
                                @if($observation->isVerified())
                                    <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-900">Verified hardware claim</span>
                                @else
                                    <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-semibold text-zinc-700">Unverified</span>
                                @endif
                            </div>
                            <p class="mt-1 text-xs text-zinc-500">
                                <a href="{{ $observation->author->profileUrl() }}" class="font-semibold hover:text-emerald-700">{{ $observation->author->name }}</a> · {{ $observation->created_at->diffForHumans() }}
                                @if($observation->task) · task: {{ $observation->task->title }}@endif
                                @if($observation->variantLabel()) · {{ $observation->variantLabel() }}@endif
                            </p>
                            <p class="mt-2 max-w-[70ch] whitespace-pre-line text-zinc-700">{{ $observation->body }}</p>
                            @if($observation->source_url)
                                <p class="mt-2 text-sm"><a href="{{ $observation->source_url }}" target="_blank" rel="noopener" class="font-semibold text-emerald-700 hover:text-emerald-600">Referenced document ↗</a>
                                    <span class="text-xs text-zinc-500">(external link, stored as a reference — Rev²Square does not fetch it)</span></p>
                            @endif
                            @include('projects._attachments', ['project' => $project, 'attachments' => $observation->attachments, 'uploadRoute' => route('projects.observations.attachments.store', [$project, $observation]), 'parentId' => 'obs-'.$observation->id])
                            @if($observation->corrections->isNotEmpty())
                                <div class="mt-3 space-y-2 border-t border-zinc-100 pt-3">
                                    @foreach($observation->corrections as $correction)
                                        <div class="rounded-lg bg-zinc-50 p-3 text-sm">
                                            <p class="text-xs text-zinc-500"><a href="{{ $correction->author->profileUrl() }}" class="font-semibold hover:text-emerald-700">{{ $correction->author->name }}</a> proposed a correction · {{ $correction->status->label() }}</p>
                                            <p class="mt-1 max-w-[70ch] whitespace-pre-line text-zinc-700">{{ $correction->body }}</p>
                                            @can('update', $project)
                                                @if($correction->status === \App\Enums\CorrectionStatus::Pending)
                                                    <div class="mt-2 flex gap-2">
                                                        <form method="POST" action="{{ route('projects.observations.corrections.review', [$project, $observation, $correction]) }}" class="inline">
                                                            @csrf
                                                            @method('PATCH')
                                                            <input type="hidden" name="decision" value="accept">
                                                            <button type="submit" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-500">Accept &amp; apply</button>
                                                        </form>
                                                        <form method="POST" action="{{ route('projects.observations.corrections.review', [$project, $observation, $correction]) }}" class="inline">
                                                            @csrf
                                                            @method('PATCH')
                                                            <input type="hidden" name="decision" value="dismiss">
                                                            <button type="submit" class="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50">Dismiss</button>
                                                        </form>
                                                    </div>
                                                @endif
                                            @endcan
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                            <div class="mt-3 flex flex-wrap items-center gap-3 border-t border-zinc-100 pt-3 text-sm">
                                @auth
                                    <details class="text-sm">
                                        <summary class="cursor-pointer font-semibold text-emerald-700 hover:text-emerald-600">Propose correction</summary>
                                        <form method="POST" action="{{ route('projects.observations.corrections.store', [$project, $observation]) }}" class="mt-2">
                                            @csrf
                                            <label for="correction-{{ $observation->id }}" class="sr-only">Corrected text</label>
                                            <textarea id="correction-{{ $observation->id }}" name="body" rows="3" required maxlength="10000" placeholder="Write the corrected wording…"
                                                class="block w-full rounded-lg border-zinc-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500"></textarea>
                                            <button type="submit" class="mt-2 rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-500">Send correction</button>
                                        </form>
                                    </details>
                                @endauth
                                @if(auth()->check() && ($observation->user_id === auth()->id() || $project->managedBy(auth()->user())))
                                    <a href="{{ route('projects.observations.edit', [$project, $observation]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Edit</a>
                                @endif
                                @can('update', $project)
                                    <form method="POST" action="{{ route('projects.observations.verify', [$project, $observation]) }}" class="inline">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="verified" value="{{ $observation->isVerified() ? '0' : '1' }}">
                                        <button type="submit" class="font-semibold text-emerald-700 hover:text-emerald-600">{{ $observation->isVerified() ? 'Remove verification' : 'Mark verified hardware claim' }}</button>
                                    </form>
                                @endcan
                            </div>
                        </article>
                    @empty
                        <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-600">No observations yet.</p>
                    @endforelse
                </div>
                @auth
                    <details class="mt-4 rounded-xl border border-zinc-200 bg-white p-5">
                        <summary class="cursor-pointer text-sm font-semibold text-emerald-700 hover:text-emerald-600">+ Share an observation</summary>
                        <form method="POST" action="{{ route('projects.observations.store', $project) }}" class="mt-4 space-y-4">
                            @csrf
                            <div>
                                <label for="observation-title" class="block text-sm font-semibold">Title</label>
                                <input id="observation-title" name="title" type="text" required maxlength="255" value="{{ old('title') }}"
                                    class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                @error('title')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="observation-body" class="block text-sm font-semibold">What did you find?</label>
                                <textarea id="observation-body" name="body" rows="4" required maxlength="10000"
                                    class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('body') }}</textarea>
                                @error('body')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="observation-source-url" class="block text-sm font-semibold">Documentation link <span class="font-normal text-zinc-500">(optional reference)</span></label>
                                <input id="observation-source-url" name="source_url" type="url" maxlength="2000" value="{{ old('source_url') }}" placeholder="https://…"
                                    class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                @error('source_url')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="observation-task" class="block text-sm font-semibold">Related task <span class="font-normal text-zinc-500">(optional)</span></label>
                                    <select id="observation-task" name="contributor_task_id" class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                        <option value="">No specific task</option>
                                        @foreach($openTasks as $openTask)
                                            <option value="{{ $openTask->id }}" @selected((int) old('contributor_task_id') === $openTask->id)>{{ $openTask->title }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                @include('devices._variant_select', ['variants' => $variants, 'fieldId' => 'observation-variant'])
                            </div>
                            <div class="flex justify-end">
                                <button type="submit" class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">Share observation</button>
                            </div>
                        </form>
                    </details>
                @endauth
            </section>

            <section id="test-reports">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-zinc-500">Device test reports</h2>
                    @auth
                        <a href="{{ route('projects.test-reports.create', $project) }}" class="text-sm font-semibold text-emerald-700 hover:text-emerald-600">+ File a test report</a>
                    @endauth
                </div>
                @if($testReports->isNotEmpty())
                    <div class="mt-2 flex flex-wrap gap-2 text-xs font-semibold">
                        @foreach(\App\Enums\TestOutcome::cases() as $outcome)
                            @php($count = $testReports->where('outcome', $outcome)->count())
                            @if($count > 0)
                                <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-zinc-700">{{ $outcome->label() }}: {{ $count }}</span>
                            @endif
                        @endforeach
                        @php($confirmed = $testReports->whereNotNull('confirmed_at')->count())
                        @if($confirmed > 0)
                            <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-emerald-900">Confirmed by a second member: {{ $confirmed }}</span>
                        @endif
                    </div>
                    @php($byVariant = $testReports->filter(fn ($r) => $r->variantLabel() !== null)->groupBy(fn ($r) => $r->variantLabel()))
                    @if($byVariant->isNotEmpty())
                        <div class="mt-3 overflow-x-auto rounded-xl border border-zinc-200">
                            <table class="w-full text-left text-sm">
                                <caption class="sr-only">Test outcomes per hardware variant</caption>
                                <thead>
                                    <tr class="bg-zinc-50 text-xs font-semibold text-zinc-500">
                                        <th scope="col" class="px-4 py-2">Variant</th>
                                        <th scope="col" class="px-4 py-2">Worked</th>
                                        <th scope="col" class="px-4 py-2">Partially</th>
                                        <th scope="col" class="px-4 py-2">Failed</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100">
                                    @foreach($byVariant as $label => $reports)
                                        <tr>
                                            <th scope="row" class="px-4 py-2 font-semibold">{{ $label }}</th>
                                            <td class="px-4 py-2">{{ $reports->where('outcome', \App\Enums\TestOutcome::Worked)->count() ?: '—' }}</td>
                                            <td class="px-4 py-2">{{ $reports->where('outcome', \App\Enums\TestOutcome::Partially)->count() ?: '—' }}</td>
                                            <td class="px-4 py-2">{{ $reports->where('outcome', \App\Enums\TestOutcome::Failed)->count() ?: '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-1 text-xs text-zinc-500">One passing report covers only its own variant. A single-device pass never implies the whole model works.</p>
                    @endif
                @endif
                <div class="mt-3 space-y-4">
                    @forelse($testReports as $testReport)
                        <article class="rounded-xl border border-zinc-200 p-5">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="font-bold"><a href="{{ route('projects.test-reports.show', [$project, $testReport]) }}" class="hover:text-emerald-700">{{ $testReport->title }}</a></h3>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold
                                        @if($testReport->outcome === \App\Enums\TestOutcome::Worked) bg-emerald-100 text-emerald-900
                                        @elseif($testReport->outcome === \App\Enums\TestOutcome::Partially) bg-amber-100 text-amber-900
                                        @else bg-red-100 text-red-900 @endif">{{ $testReport->outcome->label() }}</span>
                                    @if($testReport->isConfirmed())
                                        <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-900">Confirmed by {{ $testReport->confirmedBy->name }}</span>
                                    @endif
                                </div>
                            </div>
                            <p class="mt-1 text-xs text-zinc-500">
                                <a href="{{ $testReport->author->profileUrl() }}" class="font-semibold hover:text-emerald-700">{{ $testReport->author->name }}</a> · {{ $testReport->created_at->diffForHumans() }}
                                @if($testReport->task) · task: {{ $testReport->task->title }}@endif
                                @if($testReport->variantLabel()) · {{ $testReport->variantLabel() }}@endif
                            </p>
                            <p class="mt-2 text-sm text-zinc-600">{{ \Illuminate\Support\Str::limit($testReport->body, 220) }}</p>
                            <p class="mt-3"><a href="{{ route('projects.test-reports.show', [$project, $testReport]) }}" class="text-sm font-semibold text-emerald-700 hover:text-emerald-600">Read full report →</a></p>
                        </article>
                    @empty
                        <p class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-600">No test reports yet.</p>
                    @endforelse
                </div>
            </section>

            <section id="history">
                <h2 class="text-sm font-semibold text-zinc-500">Project history</h2>
                @if($historyEvents->isEmpty())
                    <p class="mt-3 rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-600">No activity yet.</p>
                @else
                    <ol class="mt-3 space-y-2 rounded-xl border border-zinc-200 bg-white p-5">
                        @foreach($historyEvents as $event)
                            <li class="text-sm text-zinc-700">
                                <span class="font-semibold">{{ $event->user?->name ?? 'System' }}</span>
                                <span class="text-zinc-500">· {{ $event->created_at->diffForHumans() }} ·</span>
                                @switch($event->event)
                                    @case('task.created') created a task @break
                                    @case('task.updated') updated a task @break
                                    @case('task.status')
                                        moved a task from <strong>{{ \App\Enums\TaskStatus::tryFrom($event->metadata['from'] ?? '')?->label() ?? '—' }}</strong>
                                        to <strong>{{ \App\Enums\TaskStatus::tryFrom($event->metadata['to'] ?? '')?->label() ?? '—' }}</strong>
                                        @break
                                    @case('task.assigned') assigned a task to @include('projects._history-assignee', ['event' => $event]) @break
                                    @case('task.unassigned') unassigned a task @break
                                    @case('milestone.created') created a milestone @break
                                    @case('milestone.updated') updated a milestone @break
                                    @case('milestone.retired') retired a milestone @break
                                    @case('milestone.restored') restored a milestone @break
                                    @case('observation.created') shared an observation @break
                                    @case('observation.updated') updated an observation @break
                                    @case('observation.verified') marked an observation as a verified hardware claim @break
                                    @case('observation.unverified') removed an observation verification @break
                                    @case('correction.accepted') accepted a correction @break
                                    @case('test_report.created') filed a test report @break
                                    @case('test_report.updated') updated a test report @break
                                    @case('test_report.confirmed') confirmed a test report @break
                                    @case('attachment.uploaded') attached <strong>{{ $event->metadata['original_name'] ?? 'a file' }}</strong> @break
                                    @case('attachment.reviewed') reviewed an attachment @break
                                    @case('discussion.posted') started a discussion @break
                                    @case('discussion.update_published') published a project update @break
                                    @case('discussion.moderated') removed a post after review @break
                                    @default {{ $event->event }}
                                @endswitch
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>

        <aside class="space-y-6">
            <section class="rounded-xl border border-zinc-200 p-5">
                <h2 class="text-sm font-semibold text-zinc-500">Device</h2>
                @forelse($project->devices as $device)
                    <p class="mt-2 font-bold">{{ $device->displayName() }}</p>
                    @if($device->category)
                        <p class="text-sm text-zinc-600">{{ $device->category }}</p>
                    @endif
                    @if($device->description)
                        <p class="mt-2 text-sm text-zinc-600">{{ $device->description }}</p>
                    @endif
                @empty
                    <p class="mt-2 text-sm text-zinc-600">No device linked yet.</p>
                    @can('update', $project)
                        <p class="mt-2 text-sm"><a href="{{ route('projects.edit', $project) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Edit project to link one →</a></p>
                    @endcan
                @endforelse
            </section>

            <section class="rounded-xl border {{ $publishedReport ? 'border-emerald-200 bg-emerald-50/50' : 'border-amber-300 bg-amber-50' }} p-5">
                <h2 class="text-sm font-semibold {{ $publishedReport ? 'text-emerald-800' : 'text-amber-800' }}">AI revival assessment</h2>
                @if($publishedReport)
                    <p class="mt-2 text-sm text-zinc-700">Version {{ $publishedReport->version_number }}, approved {{ $publishedReport->reviewed_at?->toDateString() ?? '—' }}{{ $publishedReport->run?->model ? ' · drafted with '.$publishedReport->run->model : '' }}.</p>
                    <p class="mt-2 text-sm"><a href="{{ route('projects.reports.show', [$project, $publishedReport]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Read the full assessment →</a></p>
                @else
                    <p class="mt-2 text-sm text-amber-900">No assessment published.</p>
                    @can('update', $project)
                        <p class="mt-2 text-sm"><a href="{{ route('projects.evidence', $project) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Run the AI analysis →</a></p>
                    @endcan
                @endif
            </section>

            <section class="rounded-xl border border-zinc-200 p-5">
                <h2 class="text-sm font-semibold text-zinc-500">Evidence sources</h2>
                @php($repository = $project->repositories()->latest()->first())
                @if($repository?->isReady())
                    <p class="mt-2 text-sm text-zinc-600">{{ $repository->currentEvidence()->count() }} imported files from <a href="{{ $repository->canonical_url }}" target="_blank" rel="noopener" class="font-mono text-xs hover:text-emerald-700">{{ $repository->owner }}/{{ $repository->name }}</a>, pinned to commit <span class="font-mono text-xs">{{ substr($repository->last_commit_sha, 0, 12) }}</span>.</p>
                @elseif($repository)
                    <p class="mt-2 text-sm text-zinc-600">Repository import {{ $repository->import_status }}.</p>
                @else
                    <p class="mt-2 text-sm text-zinc-600">No evidence imported yet.</p>
                @endif
                @can('update', $project)
                    <p class="mt-3"><a href="{{ route('projects.evidence', $project) }}" class="text-sm font-semibold text-emerald-700 hover:text-emerald-600">Manage evidence →</a></p>
                @endcan
            </section>

            <section class="rounded-xl border border-zinc-200 p-5">
                <h2 class="text-sm font-semibold text-zinc-500">Export</h2>
                <p class="mt-2 flex flex-wrap gap-3 text-sm">
                    <a href="{{ route('projects.export', [$project, 'md']) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Markdown ↓</a>
                    <a href="{{ route('projects.export', [$project, 'json']) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">JSON ↓</a>
                </p>
                <details class="mt-2 text-sm text-zinc-600">
                    <summary class="cursor-pointer font-semibold text-emerald-700 hover:text-emerald-600">Privacy</summary>
                    <p class="mt-1">Exports never include private offers, messages, or invitations.</p>
                </details>
            </section>
        </aside>
    </div>
</x-app-layout>
