<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditEvent;
use App\Models\Project;
use App\Models\User;
use App\Services\Admin\AdminProjectService;
use App\Services\Admin\ProjectTransferException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('admin');

        $filters = $request->only(['q', 'publication', 'activity', 'phase', 'owner_status']);

        $projects = Project::query()
            ->when(($filters['q'] ?? '') !== '', function ($query) use ($filters): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $filters['q']).'%';
                $query->where(fn ($q) => $q->where('title', 'like', $term)
                    ->orWhere('slug', 'like', $term)
                    ->orWhereHas('owner', fn ($o) => $o->where('name', 'like', $term)->orWhere('email', 'like', $term)));
            })
            ->when(($filters['publication'] ?? '') !== '', fn ($q) => $q->where('publication_status', $filters['publication']))
            ->when(($filters['activity'] ?? '') !== '', fn ($q) => $q->where('activity', $filters['activity']))
            ->when(($filters['phase'] ?? '') !== '', fn ($q) => $q->where('phase', $filters['phase']))
            ->when(($filters['owner_status'] ?? '') !== '', fn ($q) => $q->whereHas('owner',
                fn ($o) => $o->where('account_status', $filters['owner_status'])))
            ->with('owner')
            ->withCount(['members', 'tasks', 'milestones'])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.projects.index', ['projects' => $projects, 'filters' => $filters]);
    }

    public function show(Project $project): View
    {
        Gate::authorize('admin');

        $project->load(['owner', 'creator']);
        $maintainers = $project->members()->with('user')->where('status', 'active')->orderBy('id')->get();

        return view('admin.projects.show', [
            'project' => $project,
            'maintainers' => $maintainers,
            'counts' => [
                'tasks' => $project->tasks()->count(),
                'milestones' => $project->milestones()->count(),
                'offers_pending' => $project->offers()->where('status', 'pending')->count(),
            ],
            'recentAudits' => AdminAuditEvent::where('target_type', $project->getMorphClass())
                ->where('target_id', $project->id)
                ->latest('id')
                ->take(10)
                ->get(),
        ]);
    }

    public function transfer(Project $project): View
    {
        Gate::authorize('admin');

        return view('admin.projects.transfer', ['project' => $project->load('owner')]);
    }

    public function preview(Request $request, Project $project): View|RedirectResponse
    {
        Gate::authorize('admin');

        $data = $request->validate([
            'email' => ['required', 'email:filter', 'max:255'],
            'keep_previous_owner' => ['required', 'in:keep,remove'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $recipient = User::where('email', $data['email'])->first();

        if ($recipient === null) {
            return back()->withErrors(['email' => 'No registered account uses that email address.'])->withInput();
        }
        if ($recipient->isSuspended()) {
            return back()->withErrors(['email' => 'That account is suspended and cannot receive ownership.'])->withInput();
        }
        if ($recipient->id === $project->owner_id) {
            return back()->withErrors(['email' => 'That account already owns this project — nothing to transfer.'])->withInput();
        }

        $project->load('owner');

        return view('admin.projects.transfer-preview', [
            'project' => $project,
            'recipient' => $recipient,
            'recipientMembership' => $project->members()
                ->where('user_id', $recipient->id)->where('status', 'active')->first(),
            'keepPreviousOwner' => $data['keep_previous_owner'] === 'keep',
            'reason' => $data['reason'],
            'expectedOwnerId' => $project->owner_id,
        ]);
    }

    public function confirm(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('admin');

        // Manual validator: failures return to the transfer form, never
        // back() to this POST-only preview confirmation.
        $validator = Validator::make($request->all(), [
            'recipient_id' => ['required', 'integer'],
            'keep_previous_owner' => ['required', 'in:keep,remove'],
            'reason' => ['required', 'string', 'max:2000'],
            'expected_owner_id' => ['required', 'integer'],
        ]);
        if ($validator->fails()) {
            return redirect()->route('admin.projects.transfer', $project)
                ->withErrors(['transfer' => 'The confirmation expired or was altered. Review the details and confirm again.']);
        }
        $data = $validator->validated();

        try {
            AdminProjectService::transferOwnership(
                $request->user(),
                $project->id,
                (int) $data['recipient_id'],
                $data['keep_previous_owner'] === 'keep',
                $data['reason'],
                (int) $data['expected_owner_id'],
            );
        } catch (ProjectTransferException $e) {
            return redirect()->route('admin.projects.transfer', $project)
                ->withErrors(['transfer' => $e->getMessage()]);
        }

        return redirect()->route('admin.projects.show', $project)
            ->with('status', 'Ownership transferred. Both owners were notified.');
    }
}
