<?php

namespace App\Http\Controllers;

use App\Enums\ReportStatus;
use App\Models\AuditEvent;
use App\Models\ChatMessage;
use App\Models\DiscussionPost;
use App\Models\Project;
use App\Models\Report;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ModerationController extends Controller
{
    public function storePost(Request $request, Project $project, DiscussionPost $post): RedirectResponse
    {
        $this->assertBelongsToProject($project, $post);

        return $this->store($request, $project, $post);
    }

    public function storeMessage(Request $request, Project $project, ChatMessage $message): RedirectResponse
    {
        $this->assertBelongsToProject($project, $message);

        return $this->store($request, $project, $message);
    }

    public function storeLoungeMessage(Request $request, ChatMessage $message): RedirectResponse
    {
        abort_unless($message->project_id === null, 404);

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $message->reports()->create([
            'user_id' => $request->user()->id,
            'reason' => $data['reason'],
            'status' => ReportStatus::Pending,
        ]);

        return back()->with('status', 'Thanks — a moderator will review this message.');
    }

    public function index(Project $project): View
    {
        Gate::authorize('moderate', $project);

        // Scope reports to this project's posts and messages in the
        // database instead of plucking every id into PHP first.
        $reports = Report::with(['reporter', 'reportable'])
            ->whereHasMorph('reportable', [DiscussionPost::class, ChatMessage::class], function ($query) use ($project): void {
                $query->withTrashed()->where('project_id', $project->id);
            })
            ->orderByRaw("status = 'pending' DESC, id DESC")
            ->paginate(20);

        return view('projects.moderation.index', ['project' => $project, 'reports' => $reports]);
    }

    public function review(Request $request, Project $project, Report $report): RedirectResponse
    {
        Gate::authorize('moderate', $project);
        abort_unless($report->project()?->id === $project->id, 404);
        abort_unless($report->status === ReportStatus::Pending, 422, 'This report was already reviewed.');

        $data = $request->validate([
            'decision' => ['required', 'in:dismiss,remove'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($data['decision'] === 'remove' && $report->reportable !== null) {
            $report->reportable->delete();

            AuditEvent::record('discussion.moderated', $request->user(), $project, $report->reportable, [
                'reason' => $data['reason'] ?? 'Removed after a member report.',
                'report_id' => $report->id,
            ]);
        }

        $report->update([
            'status' => $data['decision'] === 'remove' ? ReportStatus::Actioned : ReportStatus::Dismissed,
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $request->user()->id,
        ]);

        return back()->with('status', $data['decision'] === 'remove'
            ? 'Content removed and logged.'
            : 'Report dismissed.');
    }

    private function store(Request $request, Project $project, Model $reportable): RedirectResponse
    {
        Gate::authorize('view', $project);

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $duplicate = Report::where([
            'user_id' => $request->user()->id,
            'reportable_type' => $reportable::class,
            'reportable_id' => $reportable->getKey(),
            'status' => ReportStatus::Pending,
        ])->exists();

        if ($duplicate) {
            return back()->withErrors(['reason' => 'You already reported this. Maintainers will review it.']);
        }

        $reportable->reports()->create([
            'user_id' => $request->user()->id,
            'reason' => $data['reason'],
            'status' => ReportStatus::Pending,
        ]);

        return back()->with('status', 'Thanks — the maintainers will review this.');
    }
}
