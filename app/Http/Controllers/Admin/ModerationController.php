<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Report;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Cross-project moderation queue metadata for global moderators and
 * admins. Review actions themselves live in ModerationController and
 * re-authorize there; this page only lists and links.
 */
class ModerationController extends Controller
{
    public function index(): View
    {
        Gate::authorize('moderate');

        $reports = Report::with(['reporter', 'reportable'])
            ->orderByRaw("status = 'pending' DESC, id DESC")
            ->paginate(25);

        return view('admin.moderation.index', ['reports' => $reports]);
    }

    /**
     * Review a lounge (project-less) report. Project reports are
     * reviewed on their project queue, which global moderators can
     * also reach through the links on the index page.
     */
    public function review(Request $request, Report $report): RedirectResponse
    {
        Gate::authorize('moderate');
        abort_unless($report->project() === null, 404);
        abort_unless($report->status === ReportStatus::Pending, 422, 'This report was already reviewed.');

        $data = $request->validate([
            'decision' => ['required', 'in:dismiss,remove'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($data['decision'] === 'remove' && $report->reportable !== null) {
            $report->reportable->delete();

            AuditEvent::record('discussion.moderated', $request->user(), null, $report->reportable, [
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
}
