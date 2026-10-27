<?php

namespace App\Http\Controllers;

use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Models\AuditEvent;
use App\Models\ContributionOffer;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OfferController extends Controller
{
    public function create(Request $request, Project $project): View
    {
        Gate::authorize('view', $project);

        $tasks = $project->tasks()->orderBy('title')->get();

        return view('projects.offers.create', [
            'project' => $project,
            'tasks' => $tasks,
            'selectedTaskId' => $request->integer('task') ?: null,
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);

        $data = $request->validate([
            'type' => ['required', Rule::enum(OfferType::class)],
            'contributor_task_id' => ['nullable', 'integer', Rule::exists('contributor_tasks', 'id')->where('project_id', $project->id)],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $offer = ContributionOffer::create([
            'project_id' => $project->id,
            'contributor_task_id' => $data['contributor_task_id'] ?? null,
            'user_id' => $request->user()->id,
            'type' => $data['type'],
            'message' => $data['message'],
            'status' => OfferStatus::Pending,
        ]);

        AuditEvent::record('offer.submitted', $request->user(), $project, $offer);

        return redirect()->route('projects.show', $project)
            ->with('status', 'Offer sent. The maintainers will review it.');
    }

    public function index(Project $project): View
    {
        Gate::authorize('update', $project);

        $offers = $project->offers()->with(['user', 'task'])->latest()->get();

        return view('projects.offers.index', ['project' => $project, 'offers' => $offers]);
    }

    public function accept(Project $project, ContributionOffer $offer): RedirectResponse
    {
        return $this->decide($project, $offer, OfferStatus::Accepted, 'accepted');
    }

    public function decline(Project $project, ContributionOffer $offer): RedirectResponse
    {
        return $this->decide($project, $offer, OfferStatus::Declined, 'declined');
    }

    private function decide(Project $project, ContributionOffer $offer, OfferStatus $status, string $verb): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($offer->project_id === $project->id, 404);

        if ($offer->status !== OfferStatus::Pending) {
            return back()->withErrors(['offer' => 'Only a pending offer can be reviewed.']);
        }

        $offer->forceFill(['status' => $status])->save();

        AuditEvent::record('offer.'.$verb, request()->user(), $project, $offer);

        return back()->with('status', "Offer {$verb}.");
    }
}
