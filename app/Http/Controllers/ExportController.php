<?php

namespace App\Http\Controllers;

use App\Enums\DiscussionType;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Services\Media\StoryRenderer;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ExportController extends Controller
{
    /**
     * Audit events safe to publish: project-visible work, never private
     * applications, messages, invitations, or decision history.
     *
     * @var list<string>
     */
    public const PUBLIC_HISTORY = [
        'task.created',
        'task.updated',
        'task.status',
        'task.assigned',
        'task.unassigned',
        'milestone.created',
        'milestone.updated',
        'milestone.retired',
        'milestone.restored',
        'observation.created',
        'observation.updated',
        'observation.verified',
        'observation.unverified',
        'correction.accepted',
        'test_report.created',
        'test_report.updated',
        'test_report.confirmed',
        'attachment.uploaded',
        'attachment.reviewed',
        'discussion.posted',
        'discussion.update_published',
        'discussion.moderated',
    ];

    public function show(Project $project, string $format): Response
    {
        Gate::authorize('view', $project);
        abort_unless(in_array($format, ['md', 'json'], true), 404);

        $project->load([
            'devices.variants',
            'milestones',
            'tasks.assignee',
            'tasks.milestone',
            'observations.author',
            'observations.variant.device',
            'observations.verifiedBy',
            'testReports.author',
            'testReports.variant.device',
            'testReports.confirmedBy',
            'repositories',
        ]);

        $threads = $project->discussionThreads()
            ->with('author')
            ->withCount('replies')
            ->orderBy('id')
            ->get()
            ->map(fn ($thread): array => [
                'title' => $thread->title,
                'body' => $thread->body,
                'author' => $thread->author->name,
                'posted_at' => $thread->created_at->toDateString(),
                'announcement' => $thread->type === DiscussionType::Announcement,
                'update' => $thread->type === DiscussionType::Update,
                'replies' => $thread->replies_count,
            ]);

        $history = AuditEvent::where('project_id', $project->id)
            ->whereIn('event', self::PUBLIC_HISTORY)
            ->with('user')
            ->orderBy('id')
            ->get()
            ->map(fn (AuditEvent $event): array => [
                'at' => $event->created_at->toIso8601String(),
                'actor' => $event->user?->name ?? 'System',
                'event' => $event->event,
                'subject' => $event->subject_type ? class_basename($event->subject_type).' #'.$event->subject_id : null,
                'details' => $event->metadata,
            ])
            ->all();

        $data = [
            'title' => $project->title,
            'pitch' => $project->pitch,
            'story' => $project->body,
            'story_markdown' => $project->hasRichStory()
                ? StoryRenderer::toMarkdown($project->story_document, $project)
                : $project->body,
            'story_format' => $project->story_format,
            'story_document' => $project->hasRichStory() ? $project->story_document : null,
            'cover' => $project->cover && $project->cover->isReady() ? [
                'alt' => $project->cover->altText(),
                'caption' => $project->cover->caption,
                'credit' => $project->cover->credit,
                'license' => $project->cover->license,
            ] : null,
            'media' => $project->media->map(fn ($item): array => [
                'kind' => $item->kind,
                'category' => $item->category,
                'title' => $item->displayTitle(),
                'caption' => $item->caption,
                'alt' => $item->alt,
                'credit' => $item->credit,
                'license' => $item->license,
                'in_gallery' => $item->in_gallery,
                'mime' => $item->mime,
                'size' => $item->size,
            ])->all(),
            'phase' => $project->phase->label(),
            'activity' => $project->activity->label(),
            'publication_status' => $project->publication_status->label(),
            'exported_at' => now()->toIso8601String(),
            'devices' => $project->devices->map(fn ($device): array => [
                'name' => $device->displayName(),
                'category' => $device->category,
                'description' => $device->description,
                'variants' => $device->variants->map(fn ($variant): array => [
                    'hardware_revision' => $variant->hardware_revision,
                    'firmware_version' => $variant->firmware_version,
                    'notes' => $variant->notes,
                ])->all(),
            ])->all(),
            'repositories' => $project->repositories->map(fn ($repository): array => [
                'url' => $repository->canonical_url,
                'commit_sha' => $repository->last_commit_sha,
                'import_status' => $repository->import_status,
            ])->all(),
            'milestones' => $project->milestones->map(fn ($milestone): array => [
                'title' => $milestone->title,
                'description' => $milestone->description,
                'status' => $milestone->status->label(),
                'origin' => $milestone->origin->label(),
                'target_date' => $milestone->target_date?->toDateString(),
                'retired' => $milestone->isRetired(),
            ])->all(),
            'tasks' => $project->tasks->map(fn ($task): array => [
                'title' => $task->title,
                'description' => $task->description,
                'status' => $task->status->label(),
                'origin' => $task->origin->label(),
                'assignee' => $task->assignee?->name,
                'milestone' => $task->milestone?->title,
                'skills' => $task->skillTags(),
                'definition_of_done' => $task->definition_of_done,
                'requires_hardware' => $task->requires_hardware,
            ])->all(),
            'observations' => $project->observations->map(fn ($observation): array => [
                'title' => $observation->title,
                'body' => $observation->body,
                'author' => $observation->author->name,
                'observed_at' => $observation->created_at->toDateString(),
                'variant' => $observation->variantLabel(),
                'source_url' => $observation->source_url,
                'verified' => $observation->isVerified(),
                'verified_by' => $observation->verifiedBy?->name,
            ])->all(),
            'test_reports' => $project->testReports->map(fn ($report): array => [
                'title' => $report->title,
                'outcome' => $report->outcome->label(),
                'body' => $report->body,
                'environment' => $report->environment,
                'author' => $report->author->name,
                'reported_at' => $report->created_at->toDateString(),
                'variant' => $report->variantLabel(),
                'confirmed' => $report->isConfirmed(),
                'confirmed_by' => $report->confirmedBy?->name,
            ])->all(),
            'updates' => $threads->where('update', true)->values()->all(),
            'discussion' => $threads->where('update', false)->values()->all(),
            'history' => $history,
        ];

        if ($format === 'json') {
            return response()->json($data)
                ->header('Content-Disposition', "attachment; filename=\"{$project->slug}-revival.json\"");
        }

        return response()
            ->view('projects.export.md', ['project' => $project, 'data' => $data], 200, [
                'Content-Type' => 'text/markdown; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$project->slug}-revival.md\"",
            ]);
    }
}
