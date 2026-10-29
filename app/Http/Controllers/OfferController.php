<?php

namespace App\Http\Controllers;

use App\Enums\InvitationStatus;
use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Enums\ProjectActivity;
use App\Enums\ProjectRole;
use App\Enums\TaskStatus;
use App\Models\AuditEvent;
use App\Models\ContributionOffer;
use App\Models\ContributorTask;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\TeamInvitation;
use App\Notifications\OfferMessageNotification;
use App\Notifications\OfferReceivedNotification;
use App\Notifications\OfferReviewedNotification;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TeamInvitationNotification;
use App\Services\Community\ProjectActionPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OfferController extends Controller
{
    public function create(Request $request, Project $project): View
    {
        Gate::authorize('view', $project);

        $tasks = $project->tasks()->orderBy('title')->get();
        $requestedType = OfferType::tryFrom((string) $request->query('type'));

        return view('projects.offers.create', [
            'project' => $project,
            'tasks' => $tasks,
            'selectedTaskId' => $request->integer('task') ?: null,
            'selectedType' => $requestedType,
            'denial' => ProjectActionPolicy::contributionDenial($project, $request->user()),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);

        if ($denial = ProjectActionPolicy::contributionDenial($project, $request->user())) {
            return back()->withInput()->withErrors(['type' => $denial]);
        }

        $data = $request->validate([
            'type' => ['required', Rule::enum(OfferType::class)],
            'contributor_task_id' => [
                Rule::requiredIf($request->input('type') === OfferType::ClaimTask->value),
                'nullable',
                'integer',
                Rule::exists('contributor_tasks', 'id')->where('project_id', $project->id),
            ],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        // A claim needs an eligible task: open, unassigned, and current.
        // General help may reference a task for context but never claims it.
        if ($data['type'] === OfferType::ClaimTask->value) {
            $task = ContributorTask::where('project_id', $project->id)->findOrFail($data['contributor_task_id']);

            if (! $task->status->isOpenForClaim() || $task->assigned_to_user_id !== null) {
                return back()->withInput()->withErrors([
                    'contributor_task_id' => 'That task is no longer available to claim ('.$task->status->label().').',
                ]);
            }
        }

        // Maintainer applications are separate from task claims: no task,
        // only while the project seeks a maintainer, and never for someone
        // who already maintains it.
        if ($data['type'] === OfferType::MaintainProject->value) {
            if (($data['contributor_task_id'] ?? null) !== null) {
                return back()->withInput()->withErrors([
                    'contributor_task_id' => 'A maintainer application is not attached to a task.',
                ]);
            }

            if ($project->activity !== ProjectActivity::SeekingMaintainer) {
                return back()->withInput()->withErrors([
                    'type' => 'This project is not looking for a maintainer right now.',
                ]);
            }

            if ($project->managedBy($request->user())) {
                return back()->withInput()->withErrors([
                    'type' => 'You already maintain this project.',
                ]);
            }
        }

        // One pending request per user/project/type/task: accidental double
        // submits and refreshes land here instead of creating duplicates.
        $duplicate = ContributionOffer::where([
            'project_id' => $project->id,
            'user_id' => $request->user()->id,
            'type' => $data['type'],
            'status' => OfferStatus::Pending,
        ])
            ->when(
                ($data['contributor_task_id'] ?? null) === null,
                fn ($query) => $query->whereNull('contributor_task_id'),
                fn ($query) => $query->where('contributor_task_id', $data['contributor_task_id'])
            )
            ->exists();

        if ($duplicate) {
            return back()->withInput()->withErrors([
                'type' => 'You already have a pending request of this kind. See My applications on your dashboard.',
            ]);
        }

        $offer = ContributionOffer::create([
            'project_id' => $project->id,
            'contributor_task_id' => $data['contributor_task_id'] ?? null,
            'user_id' => $request->user()->id,
            'type' => $data['type'],
            'message' => $data['message'],
            'status' => OfferStatus::Pending,
        ]);

        AuditEvent::record('offer.submitted', $request->user(), $project, $offer);

        foreach ($project->managers()->where('id', '!=', $request->user()->id) as $manager) {
            $manager->notify(new OfferReceivedNotification($offer));
        }

        return redirect()->route('projects.offers.show', [$project, $offer])
            ->with('status', 'Offer sent. The maintainers will review it. You can follow up here.');
    }

    public function index(Project $project): View
    {
        Gate::authorize('update', $project);

        $offers = $project->offers()->with(['user', 'task'])->latest()->get();

        return view('projects.offers.index', ['project' => $project, 'offers' => $offers]);
    }

    public function show(Project $project, ContributionOffer $offer): View
    {
        abort_unless($offer->project_id === $project->id, 404);
        abort_unless($this->canParticipate($project, $offer, auth()->user()), 403);

        $offer->load(['user', 'task', 'decidedBy', 'messages.author']);

        $history = AuditEvent::where('subject_type', ContributionOffer::class)
            ->where('subject_id', $offer->id)
            ->with('user')
            ->orderByDesc('id')
            ->get();

        return view('projects.offers.show', [
            'project' => $project,
            'offer' => $offer,
            'history' => $history,
            'isMaintainer' => $project->managedBy(auth()->user()),
        ]);
    }

    public function message(Request $request, Project $project, ContributionOffer $offer): RedirectResponse
    {
        abort_unless($offer->project_id === $project->id, 404);
        abort_unless($this->canParticipate($project, $offer, $request->user()), 403);

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $message = $offer->messages()->create([
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        // Private application thread: the applicant hears from maintainers
        // and vice versa. Nobody else is notified.
        if ($request->user()->id === $offer->user_id) {
            $recipients = $project->managers()->where('id', '!=', $request->user()->id);
        } else {
            $recipients = collect([$offer->user]);
        }

        foreach ($recipients as $recipient) {
            $recipient->notify(new OfferMessageNotification($message));
        }

        AuditEvent::record('offer.message', $request->user(), $project, $offer);

        return back()->with('status', 'Message sent.');
    }

    public function reopen(Project $project, ContributionOffer $offer): RedirectResponse
    {
        abort_unless($offer->project_id === $project->id, 404);

        $user = request()->user();
        $isApplicant = $offer->user_id === $user->id;
        abort_unless($isApplicant || $project->managedBy($user), 403);

        if (! in_array($offer->status, [OfferStatus::Declined, OfferStatus::Withdrawn], true)) {
            return back()->withErrors(['offer' => 'Only a declined or withdrawn request can be reopened.']);
        }

        $previous = $offer->status;

        $offer->forceFill([
            'status' => OfferStatus::Pending,
            'decision_reason' => null,
            'decided_by_user_id' => null,
            'decided_at' => null,
        ])->save();

        AuditEvent::record('offer.reopened', $user, $project, $offer, [
            'from' => $previous->value,
            'by' => $isApplicant ? 'applicant' : 'maintainer',
        ]);

        // A reopened request needs review again.
        foreach ($project->managers()->where('id', '!=', $user->id) as $manager) {
            $manager->notify(new OfferReceivedNotification($offer));
        }

        return back()->with('status', 'Request reopened and back in the review queue.');
    }

    private function canParticipate(Project $project, ContributionOffer $offer, mixed $user): bool
    {
        return $user !== null
            && ($offer->user_id === $user->id || $project->managedBy($user));
    }

    public function accept(Request $request, Project $project, ContributionOffer $offer): RedirectResponse
    {
        return $this->decide($request, $project, $offer, OfferStatus::Accepted, 'accepted');
    }

    public function decline(Request $request, Project $project, ContributionOffer $offer): RedirectResponse
    {
        return $this->decide($request, $project, $offer, OfferStatus::Declined, 'declined');
    }

    /**
     * Explicit Join step after an accepted offer. Accepting help never
     * grants membership by itself; the applicant chooses to join as a
     * contributor (no maintainer rights). Maintainer access still only
     * comes from an owner invitation.
     */
    public function join(Project $project, ContributionOffer $offer): RedirectResponse
    {
        abort_unless($offer->project_id === $project->id, 404);
        abort_unless($offer->user_id === request()->user()->id, 403);
        abort_unless($offer->status === OfferStatus::Accepted, 422, 'Only an accepted request can join the team.');

        $user = request()->user();

        if ($project->managedBy($user)) {
            return back()->with('status', 'You already manage this project.');
        }

        $member = ProjectMember::firstOrNew([
            'project_id' => $project->id,
            'user_id' => $user->id,
        ]);

        if ($member->exists && $member->status === 'active') {
            return back()->with('status', 'You are already on the team.');
        }

        // Revocation is an owner decision; a stale accepted request must
        // not silently undo it.
        if ($member->exists && $member->status !== 'active') {
            return back()->withErrors(['offer' => 'Your team access was revoked. Ask the owner for a new invitation.']);
        }

        $member->role = ProjectRole::Contributor;
        $member->status = 'active';
        $member->save();

        AuditEvent::record('team.joined', $user, $project, $member, ['offer_id' => $offer->id]);

        return back()->with('status', 'You joined the project team as a contributor.');
    }

    public function withdraw(Project $project, ContributionOffer $offer): RedirectResponse
    {
        abort_unless($offer->project_id === $project->id, 404);
        abort_unless($offer->user_id === request()->user()->id, 403);

        if ($offer->status !== OfferStatus::Pending) {
            return back()->withErrors(['offer' => 'Only a pending request can be withdrawn.']);
        }

        $offer->forceFill(['status' => OfferStatus::Withdrawn])->save();

        AuditEvent::record('offer.withdrawn', request()->user(), $project, $offer);

        return back()->with('status', 'Request withdrawn.');
    }

    private function decide(Request $request, Project $project, ContributionOffer $offer, OfferStatus $status, string $verb): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($offer->project_id === $project->id, 404);

        $data = $request->validate(['decision_reason' => ['nullable', 'string', 'max:2000']]);

        if ($offer->status !== OfferStatus::Pending) {
            return back()->withErrors(['offer' => 'Only a pending offer can be reviewed.']);
        }

        return DB::transaction(function () use ($request, $project, $offer, $status, $verb, $data) {
            // A pending claim is not an assignment. Accepting one atomically
            // assigns the task; a task taken meanwhile rejects the review.
            if ($status === OfferStatus::Accepted
                && $offer->type === OfferType::ClaimTask
                && $offer->contributor_task_id !== null) {
                $task = ContributorTask::whereKey($offer->contributor_task_id)->lockForUpdate()->first();

                if ($task === null || $task->project_id !== $project->id
                    || ! $task->status->isOpenForClaim() || $task->assigned_to_user_id !== null) {
                    return back()->withErrors(['offer' => 'That task is no longer available; the claim cannot be accepted.']);
                }

                $task->forceFill([
                    'status' => TaskStatus::Claimed,
                    'assigned_to_user_id' => $offer->user_id,
                ])->save();

                AuditEvent::record('task.assigned', $request->user(), $project, $task, [
                    'assignee_id' => $offer->user_id,
                    'assignee' => $offer->user->name,
                    'offer_id' => $offer->id,
                ]);
            }

            // Accepting a maintainer application never grants access
            // directly: it creates an invitation the applicant must
            // explicitly accept before anything changes.
            if ($status === OfferStatus::Accepted && $offer->type === OfferType::MaintainProject) {
                if ($project->managedBy($offer->user)) {
                    return back()->withErrors(['offer' => 'This applicant already maintains the project.']);
                }

                $existing = TeamInvitation::where([
                    'project_id' => $project->id,
                    'user_id' => $offer->user_id,
                    'status' => InvitationStatus::Pending,
                ])->first();

                if ($existing !== null) {
                    return back()->withErrors(['offer' => 'This applicant already has a pending invitation.']);
                }

                $invitation = TeamInvitation::create([
                    'project_id' => $project->id,
                    'user_id' => $offer->user_id,
                    'role' => ProjectRole::Maintainer,
                    'token' => Str::random(48),
                    'status' => InvitationStatus::Pending,
                    'expires_at' => now()->addDays(14),
                ]);

                AuditEvent::record('team.invited', $request->user(), $project, $invitation, [
                    'offer_id' => $offer->id,
                ]);

                $offer->user->notify(new TeamInvitationNotification($invitation));
            }

            $offer->forceFill([
                'status' => $status,
                'decision_reason' => $data['decision_reason'] ?? null,
                'decided_by_user_id' => $request->user()->id,
                'decided_at' => now(),
            ])->save();

            AuditEvent::record('offer.'.$verb, $request->user(), $project, $offer);

            $offer->user->notify(new OfferReviewedNotification($offer, $status));

            if ($status === OfferStatus::Accepted && $offer->contributor_task_id !== null) {
                $assigned = ContributorTask::find($offer->contributor_task_id);
                if ($assigned !== null && $assigned->assigned_to_user_id === $offer->user_id) {
                    $offer->user->notify(new TaskAssignedNotification($assigned));
                }
            }

            return back()->with('status', "Offer {$verb}.");
        });
    }
}
