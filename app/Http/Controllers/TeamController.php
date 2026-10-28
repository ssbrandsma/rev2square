<?php

namespace App\Http\Controllers;

use App\Enums\InvitationStatus;
use App\Enums\OfferStatus;
use App\Enums\ProjectRole;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TeamController extends Controller
{
    public function index(Project $project): View
    {
        Gate::authorize('view', $project);

        $user = auth()->user();

        $contributors = $project->offers()
            ->with('user')
            ->where('status', OfferStatus::Accepted)
            ->get()
            ->pluck('user')
            ->filter()
            ->unique('id')
            ->reject(fn (User $contributor): bool => $contributor->id === $project->owner_id
                || $project->members->contains('user_id', $contributor->id))
            ->values();

        return view('projects.team.index', [
            'project' => $project,
            'members' => $project->members()->with('user')->orderBy('id')->get(),
            'contributors' => $contributors,
            'invitations' => $project->managedBy($user)
                ? TeamInvitation::with('user')->where('project_id', $project->id)->latest()->get()
                : collect(),
            'myInvitation' => $user
                ? TeamInvitation::where(['project_id' => $project->id, 'user_id' => $user->id, 'status' => InvitationStatus::Pending])->first()
                : null,
            'isOwner' => $user !== null && $project->owner_id === $user->id,
        ]);
    }

    public function invite(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($project->owner_id === $request->user()->id, 403, 'Only the project owner can invite maintainers.');

        $data = $request->validate(['email' => ['required', 'email:filter', 'max:255']]);

        $invitee = User::where('email', $data['email'])->first();
        abort_unless($invitee !== null, 422, 'No registered account uses that email address.');

        if ($invitee->id === $request->user()->id || $project->managedBy($invitee)) {
            return back()->withErrors(['email' => 'That person already maintains this project.']);
        }

        $pending = TeamInvitation::where([
            'project_id' => $project->id,
            'user_id' => $invitee->id,
            'status' => InvitationStatus::Pending,
        ])->exists();

        if ($pending) {
            return back()->withErrors(['email' => 'That person already has a pending invitation.']);
        }

        $invitation = TeamInvitation::create([
            'project_id' => $project->id,
            'user_id' => $invitee->id,
            'role' => ProjectRole::Maintainer,
            'token' => Str::random(48),
            'status' => InvitationStatus::Pending,
            'expires_at' => now()->addDays(14),
        ]);

        AuditEvent::record('team.invited', $request->user(), $project, $invitation);
        $invitee->notify(new TeamInvitationNotification($invitation));

        return back()->with('status', "Invitation sent to {$invitee->name}. Nothing changes until they accept.");
    }

    public function revokeMember(Project $project, ProjectMember $member): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($project->owner_id === request()->user()->id, 403, 'Only the project owner can revoke team access.');
        abort_unless($member->project_id === $project->id, 404);

        if ($member->status !== 'active') {
            return back()->withErrors(['member' => 'That membership is already revoked.']);
        }

        // The owner row lives on the project itself, never in members, so
        // revoking members can never strand a project without a manager.
        $member->update(['status' => 'revoked']);

        AuditEvent::record('team.revoked', request()->user(), $project, $member, [
            'revoked_user_id' => $member->user_id,
        ]);

        return back()->with('status', 'Team access revoked.');
    }

    public function revokeInvitation(Project $project, TeamInvitation $invitation): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($project->owner_id === request()->user()->id, 403, 'Only the project owner can revoke invitations.');
        abort_unless($invitation->project_id === $project->id, 404);
        abort_unless($invitation->isPending(), 422, 'Only a pending invitation can be revoked.');

        $invitation->update(['status' => InvitationStatus::Revoked]);

        AuditEvent::record('team.invitation_revoked', request()->user(), $project, $invitation);

        return back()->with('status', 'Invitation revoked.');
    }

    public function accept(Project $project, TeamInvitation $invitation): RedirectResponse
    {
        abort_unless($invitation->project_id === $project->id, 404);
        abort_unless($invitation->user_id === request()->user()->id, 403, 'This invitation belongs to a different account.');
        abort_unless($invitation->isPending(), 422, 'This invitation is no longer pending.');

        if ($invitation->isExpired()) {
            $invitation->update(['status' => InvitationStatus::Expired]);

            return back()->withErrors(['invitation' => 'This invitation has expired. Ask the owner for a new one.']);
        }

        $member = ProjectMember::firstOrNew([
            'project_id' => $project->id,
            'user_id' => $invitation->user_id,
        ]);
        $member->role = ProjectRole::Maintainer;
        $member->status = 'active';
        $member->save();

        $invitation->update(['status' => InvitationStatus::Accepted]);

        AuditEvent::record('team.accepted', request()->user(), $project, $invitation);

        return back()->with('status', 'Welcome to the team. You can now maintain this project.');
    }

    public function decline(Project $project, TeamInvitation $invitation): RedirectResponse
    {
        abort_unless($invitation->project_id === $project->id, 404);
        abort_unless($invitation->user_id === request()->user()->id, 403, 'This invitation belongs to a different account.');
        abort_unless($invitation->isPending(), 422, 'This invitation is no longer pending.');

        $invitation->update(['status' => InvitationStatus::Declined]);

        AuditEvent::record('team.declined', request()->user(), $project, $invitation);

        return back()->with('status', 'Invitation declined.');
    }
}
