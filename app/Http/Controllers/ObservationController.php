<?php

namespace App\Http\Controllers;

use App\Enums\CorrectionStatus;
use App\Models\AuditEvent;
use App\Models\DeviceVariant;
use App\Models\Observation;
use App\Models\ObservationCorrection;
use App\Models\Project;
use App\Notifications\CorrectionReviewedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ObservationController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);

        $observation = $project->observations()->create([
            ...$this->validatedData($request, $project),
            'user_id' => $request->user()->id,
        ]);

        AuditEvent::record('observation.created', $request->user(), $project, $observation);

        return redirect(route('projects.show', $project).'#observations')
            ->with('status', 'Observation shared. Thank you for documenting what you found.');
    }

    public function edit(Project $project, Observation $observation): View
    {
        Gate::authorize('view', $project);
        abort_unless($observation->project_id === $project->id, 404);
        abort_unless($this->canEdit($project, $observation, auth()->user()), 403);

        return view('projects.observations.edit', [
            'project' => $project,
            'observation' => $observation,
            'tasks' => $project->tasks()->active()->orderBy('title')->get(),
            'variants' => $this->variants($project),
        ]);
    }

    public function update(Request $request, Project $project, Observation $observation): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($observation->project_id === $project->id, 404);
        abort_unless($this->canEdit($project, $observation, $request->user()), 403);

        $wasVerified = $observation->isVerified();

        $observation->update([
            ...$this->validatedData($request, $project),
            'verified_at' => null,
            'verified_by_user_id' => null,
        ]);

        AuditEvent::record('observation.updated', $request->user(), $project, $observation, $wasVerified
            ? ['verification_cleared' => true]
            : []);

        return redirect(route('projects.show', $project).'#observations')->with('status', $wasVerified
            ? 'Observation updated. It needs re-verification because the text changed.'
            : 'Observation updated.');
    }

    public function verify(Request $request, Project $project, Observation $observation): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($observation->project_id === $project->id, 404);

        $data = $request->validate(['verified' => ['required', 'boolean']]);

        if ($data['verified']) {
            $observation->update([
                'verified_at' => now(),
                'verified_by_user_id' => $request->user()->id,
            ]);
            AuditEvent::record('observation.verified', $request->user(), $project, $observation);

            $message = 'Marked as a verified hardware claim.';
        } else {
            $observation->update(['verified_at' => null, 'verified_by_user_id' => null]);
            AuditEvent::record('observation.unverified', $request->user(), $project, $observation);

            $message = 'Verification removed.';
        }

        return redirect(route('projects.show', $project).'#observations')->with('status', $message);
    }

    public function storeCorrection(Request $request, Project $project, Observation $observation): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($observation->project_id === $project->id, 404);

        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);

        $correction = $observation->corrections()->create([
            'user_id' => $request->user()->id,
            'body' => $data['body'],
            'status' => CorrectionStatus::Pending,
        ]);

        AuditEvent::record('correction.proposed', $request->user(), $project, $correction);

        return redirect(route('projects.show', $project).'#observations')
            ->with('status', 'Correction proposed. Maintainers will review it.');
    }

    public function reviewCorrection(Request $request, Project $project, Observation $observation, ObservationCorrection $correction): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($observation->project_id === $project->id, 404);
        abort_unless($correction->observation_id === $observation->id, 404);
        abort_unless($correction->status === CorrectionStatus::Pending, 422, 'This correction was already reviewed.');

        $data = $request->validate(['decision' => ['required', 'in:accept,dismiss']]);

        if ($data['decision'] === 'accept') {
            $previousBody = $observation->body;
            $observation->update([
                'body' => $correction->body,
                'verified_at' => null,
                'verified_by_user_id' => null,
            ]);
            $correction->update(['status' => CorrectionStatus::Accepted]);
            AuditEvent::record('correction.accepted', $request->user(), $project, $correction, [
                'previous_body' => $previousBody,
            ]);
            $correction->author->notify(new CorrectionReviewedNotification($correction, 'accept'));

            $message = 'Correction accepted and applied. Verification was cleared because the text changed.';
        } else {
            $correction->update(['status' => CorrectionStatus::Dismissed]);
            AuditEvent::record('correction.dismissed', $request->user(), $project, $correction);
            $correction->author->notify(new CorrectionReviewedNotification($correction, 'dismiss'));

            $message = 'Correction dismissed.';
        }

        return redirect(route('projects.show', $project).'#observations')->with('status', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request, Project $project): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'source_url' => ['nullable', 'url:http,https', 'max:2000'],
            'contributor_task_id' => ['nullable', 'integer', 'exists:contributor_tasks,id'],
            'device_variant_id' => ['nullable', 'integer', 'exists:device_variants,id'],
        ]);

        if (($data['contributor_task_id'] ?? null) !== null) {
            abort_unless(
                $project->tasks()->whereKey($data['contributor_task_id'])->exists(),
                422,
                'Task does not belong to this project.'
            );
        }

        if (($data['device_variant_id'] ?? null) !== null) {
            abort_unless(
                $this->variantBelongsToProject($project, (int) $data['device_variant_id']),
                422,
                'Device variant does not belong to this project.'
            );
        }

        return [
            'title' => trim($data['title']),
            'body' => $data['body'],
            'source_url' => $data['source_url'] ?? null,
            'contributor_task_id' => $data['contributor_task_id'] ?? null,
            'device_variant_id' => $data['device_variant_id'] ?? null,
        ];
    }

    private function variantBelongsToProject(Project $project, int $variantId): bool
    {
        return DeviceVariant::whereKey($variantId)
            ->whereIn('device_id', $project->devices()->pluck('devices.id'))
            ->exists();
    }

    /** @return Collection<int, DeviceVariant> */
    private function variants(Project $project)
    {
        return DeviceVariant::with('device')
            ->whereIn('device_id', $project->devices()->pluck('devices.id'))
            ->get();
    }

    private function canEdit(Project $project, Observation $observation, mixed $user): bool
    {
        return $observation->user_id === $user?->id || $project->managedBy($user);
    }
}
