<?php

namespace App\Http\Controllers;

use App\Enums\InvitationStatus;
use App\Enums\OfferStatus;
use App\Models\TeamInvitation;
use App\Services\Profile\MemberProfile;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(MemberProfile $profiles): View
    {
        $user = request()->user();

        $projects = $user->createdProjects()
            ->withCount('tasks')
            ->latest()
            ->get();

        $applications = $user->offers()->with(['project', 'task'])->latest()->take(20)->get();

        $memberProjectIds = $user->activeMemberships()->pluck('project_id')->all();

        $joinableOfferIds = $applications
            ->where('status', OfferStatus::Accepted)
            ->reject(fn ($offer): bool => in_array($offer->project_id, $memberProjectIds, true)
                || (int) $offer->project->owner_id === $user->id)
            ->pluck('id')
            ->all();

        $invitations = TeamInvitation::where('user_id', $user->id)
            ->where('status', InvitationStatus::Pending)
            ->where('expires_at', '>', now())
            ->with('project')
            ->get();

        $saved = $user->savedProjects()->with('project')->latest()->get()
            ->filter(fn ($save): bool => $save->project !== null && $user->can('view', $save->project));

        $activity = $user->notifications()->latest()->take(8)->get();

        return view('dashboard', [
            'projects' => $projects,
            'applications' => $applications,
            'joinableOfferIds' => $joinableOfferIds,
            'invitations' => $invitations,
            'sections' => $profiles->workspaceSections($user),
            'saved' => $saved,
            'activity' => $activity,
            'unreadCount' => $user->unreadNotifications()->count(),
        ]);
    }
}
