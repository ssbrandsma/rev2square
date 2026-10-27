<?php

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Models\AnalysisReport;
use App\Models\AnalysisRun;
use App\Models\AuditEvent;
use App\Models\ContributorTask;
use App\Models\EvidenceSource;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Services\Revival\ProjectPublisher;
use App\Services\Revival\PublisherException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MockAIProvider;
use Tests\TestCase;

class PublishWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{project: Project, report: AnalysisReport} */
    private function approvedReport(array $payloadOverrides = []): array
    {
        $project = Project::factory()->create();
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'import_status' => 'ready',
            'last_commit_sha' => str_repeat('a', 40),
        ]);
        $row = EvidenceSource::factory()->create([
            'project_id' => $project->id,
            'repository_id' => $repository->id,
            'path' => 'README.md',
            'commit_sha' => $repository->last_commit_sha,
            'content' => 'Evidence text.',
        ]);

        $run = AnalysisRun::factory()->create([
            'project_id' => $project->id,
            'user_id' => $project->owner_id,
            'repository_id' => $repository->id,
        ]);

        $payload = MockAIProvider::reportPayload(
            [['source_id' => $row->id, 'path' => 'README.md']],
            $payloadOverrides
        );

        $report = AnalysisReport::factory()->create([
            'analysis_run_id' => $run->id,
            'project_id' => $project->id,
            'version_number' => 1,
            'payload_json' => $payload,
            'review_status' => ReviewStatus::Approved,
            'reviewer_id' => $project->owner_id,
            'reviewed_at' => now(),
        ]);

        return ['project' => $project->fresh(), 'report' => $report->fresh()];
    }

    public function test_approve_records_review_and_audit_without_touching_payload(): void
    {
        ['project' => $project, 'report' => $report] = $this->approvedReport();
        $report->forceFill(['review_status' => ReviewStatus::PendingReview, 'reviewer_id' => null])->save();
        $before = $report->payload_json;

        $this->actingAs($project->owner)
            ->post(route('projects.reports.approve', [$project, $report]), ['review_notes' => 'Looks solid.'])
            ->assertRedirect(route('projects.reports.show', [$project, $report]));

        $report->refresh();
        $this->assertSame(ReviewStatus::Approved, $report->review_status);
        $this->assertSame('Looks solid.', $report->review_notes);
        $this->assertSame($before, $report->payload_json);
        $this->assertDatabaseHas('audit_events', ['event' => 'report.approved', 'project_id' => $project->id]);
    }

    public function test_reject_and_transition_guards(): void
    {
        ['project' => $project, 'report' => $report] = $this->approvedReport();
        $report->forceFill(['review_status' => ReviewStatus::PendingReview])->save();

        $this->actingAs($project->owner)
            ->post(route('projects.reports.reject', [$project, $report]), [])
            ->assertRedirect();

        $this->assertSame(ReviewStatus::Rejected, $report->fresh()->review_status);
        $this->assertDatabaseHas('audit_events', ['event' => 'report.rejected']);

        // Rejected reports cannot be approved.
        $this->actingAs($project->owner)
            ->post(route('projects.reports.approve', [$project, $report]), [])
            ->assertSessionHasErrors('review');
    }

    public function test_stranger_cannot_review_or_publish(): void
    {
        ['project' => $project, 'report' => $report] = $this->approvedReport();
        $stranger = User::factory()->create();

        $this->post(route('projects.reports.publish', [$project, $report]), [])->assertRedirect(route('login'));
        $this->actingAs($stranger)->post(route('projects.reports.approve', [$project, $report]), [])->assertForbidden();
        $this->actingAs($stranger)->post(route('projects.reports.publish', [$project, $report]), [])->assertForbidden();
    }

    public function test_publish_creates_milestones_and_tasks_once(): void
    {
        ['project' => $project, 'report' => $report] = $this->approvedReport();

        $this->actingAs($project->owner)
            ->post(route('projects.reports.publish', [$project, $report]))
            ->assertRedirect(route('projects.show', $project));

        $this->assertNotNull($report->fresh()->published_at);
        $this->assertSame(1, Milestone::where('project_id', $project->id)->count());
        $this->assertSame(1, ContributorTask::where('project_id', $project->id)->count());

        $task = ContributorTask::first();
        $this->assertSame('Run the API probe', $task->title);
        $this->assertSame(['testing'], $task->skills);
        $this->assertTrue($task->requires_hardware);
        $this->assertSame($report->id, $task->origin_report_id);
        $this->assertSame($report->id, $task->last_seen_report_id);
        $this->assertNotNull($task->import_key);

        $this->assertDatabaseHas('audit_events', ['event' => 'report.published', 'project_id' => $project->id]);
    }

    public function test_publish_is_idempotent_and_preserves_ids(): void
    {
        ['project' => $project, 'report' => $report] = $this->approvedReport();
        $publisher = app(ProjectPublisher::class);

        $publisher->publish($report, $project->owner);
        $ids = ContributorTask::pluck('id')->all();
        $milestoneIds = Milestone::pluck('id')->all();

        $summary = $publisher->publish($report->fresh(), $project->owner);

        $this->assertSame($ids, ContributorTask::pluck('id')->all());
        $this->assertSame($milestoneIds, Milestone::pluck('id')->all());
        $this->assertSame(0, $summary['tasks_created']);
        $this->assertSame(0, $summary['milestones_created']);
    }

    public function test_v2_reuses_tasks_and_records_provenance(): void
    {
        ['project' => $project, 'report' => $v1] = $this->approvedReport();
        $publisher = app(ProjectPublisher::class);
        $publisher->publish($v1, $project->owner);

        $v1TaskId = ContributorTask::firstWhere('title', 'Run the API probe')->id;

        $run2 = AnalysisRun::factory()->create(['project_id' => $project->id, 'user_id' => $project->owner_id]);
        $v2 = AnalysisReport::factory()->create([
            'analysis_run_id' => $run2->id,
            'project_id' => $project->id,
            'version_number' => 2,
            'supersedes_report_id' => $v1->id,
            'payload_json' => MockAIProvider::reportPayload(
                [['source_id' => EvidenceSource::first()->id, 'path' => 'README.md']],
                ['contributor_tasks' => [
                    [
                        'id' => 'T1',
                        'title' => 'Run the API probe',
                        'skill_tags' => ['testing', 'hardware'],
                        'definition_of_done' => 'Probe log attached.',
                        'requires_hardware' => true,
                    ],
                    [
                        'id' => 'T2',
                        'title' => 'Document the probe results',
                        'skill_tags' => ['documentation'],
                        'definition_of_done' => 'Docs page merged.',
                        'requires_hardware' => false,
                    ],
                ]]
            ),
            'review_status' => ReviewStatus::Approved,
        ]);

        $publisher->publish($v2, $project->owner);

        // Same title → same row, refreshed skills, provenance updated.
        $task = ContributorTask::find($v1TaskId);
        $this->assertSame(['testing', 'hardware'], $task->skills);
        $this->assertSame($v1->id, $task->origin_report_id);
        $this->assertSame($v2->id, $task->last_seen_report_id);

        // New title → new row.
        $this->assertSame(2, ContributorTask::where('project_id', $project->id)->count());
        $this->assertNotNull(ContributorTask::firstWhere('title', 'Document the probe results'));
    }

    public function test_unkeyed_and_manually_edited_rows_are_preserved(): void
    {
        ['project' => $project, 'report' => $report] = $this->approvedReport();

        $seeded = ContributorTask::factory()->create([
            'project_id' => $project->id,
            'title' => 'Seeded human task',
            'description' => 'Human words.',
            'import_key' => null,
        ]);

        app(ProjectPublisher::class)->publish($report, $project->owner);

        // Publisher-owned row exists alongside; seeded row untouched.
        $this->assertSame(2, ContributorTask::where('project_id', $project->id)->count());
        $this->assertSame('Human words.', $seeded->fresh()->description);
        $this->assertNull($seeded->fresh()->last_seen_report_id);

        // Manually edited publisher row keeps content on re-publish.
        $owned = ContributorTask::whereNotNull('import_key')->firstOrFail();
        $owned->forceFill(['description' => 'Maintainer rewrite.', 'manually_edited' => true])->save();

        app(ProjectPublisher::class)->publish($report->fresh(), $project->owner);

        $this->assertSame('Maintainer rewrite.', $owned->fresh()->description);
        $this->assertSame($report->id, $owned->fresh()->last_seen_report_id);
    }

    public function test_malformed_payload_publishes_nothing(): void
    {
        ['project' => $project, 'report' => $report] = $this->approvedReport([
            'milestones' => [['title' => '  ', 'description' => '', 'depends_on' => [], 'task_ids' => []]],
        ]);

        try {
            app(ProjectPublisher::class)->publish($report, $project->owner);
            $this->fail('Expected PublisherException.');
        } catch (PublisherException) {
            // Expected.
        }

        $this->assertSame(0, Milestone::where('project_id', $project->id)->count());
        $this->assertSame(0, ContributorTask::where('project_id', $project->id)->count());
        $this->assertNull($report->fresh()->published_at);
    }

    public function test_unapproved_report_cannot_publish(): void
    {
        ['project' => $project, 'report' => $report] = $this->approvedReport();
        $report->forceFill(['review_status' => ReviewStatus::PendingReview])->save();

        $this->actingAs($project->owner)
            ->post(route('projects.reports.publish', [$project, $report]))
            ->assertSessionHasErrors('review');

        $this->assertNull($report->fresh()->published_at);
    }

    public function test_unpublished_report_is_hidden_from_public(): void
    {
        ['project' => $project, 'report' => $report] = $this->approvedReport();
        $stranger = User::factory()->create();

        $this->get(route('projects.reports.show', [$project, $report]))->assertNotFound();
        $this->actingAs($stranger)->get(route('projects.reports.show', [$project, $report]))->assertNotFound();
        $this->get(route('projects.show', $project))->assertOk()
            ->assertDontSee('Revival assessment', false);
    }

    public function test_published_report_is_public_with_citations(): void
    {
        ['project' => $project, 'report' => $report] = $this->approvedReport();
        app(ProjectPublisher::class)->publish($report, $project->owner);

        $this->get(route('projects.reports.show', [$project, $report]))
            ->assertOk()
            ->assertSee('The device exposes a local API.', false)
            ->assertSee('README.md:L1-L5', false)
            ->assertSee('Proposed test — not yet performed', false);

        $this->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Revival assessment', false)
            ->assertSee('UNCERTAIN', false);
    }

    public function test_citation_links_require_https_scheme(): void
    {
        ['project' => $project, 'report' => $report] = $this->approvedReport();
        EvidenceSource::where('project_id', $project->id)
            ->firstOrFail()
            ->forceFill(['url' => 'javascript:alert(1)'])
            ->save();
        app(ProjectPublisher::class)->publish($report, $project->owner);

        $this->get(route('projects.reports.show', [$project, $report]))
            ->assertOk()
            ->assertSee('README.md:L1-L5', false)
            ->assertDontSee('javascript:alert(1)', false);
    }

    public function test_old_published_version_stays_accessible(): void
    {
        ['project' => $project, 'report' => $v1] = $this->approvedReport();
        app(ProjectPublisher::class)->publish($v1, $project->owner);

        $run2 = AnalysisRun::factory()->create(['project_id' => $project->id, 'user_id' => $project->owner_id]);
        $v2 = AnalysisReport::factory()->create([
            'analysis_run_id' => $run2->id,
            'project_id' => $project->id,
            'version_number' => 2,
            'supersedes_report_id' => $v1->id,
            'payload_json' => $v1->payload_json,
            'review_status' => ReviewStatus::Approved,
        ]);
        app(ProjectPublisher::class)->publish($v2, $project->owner);

        $this->get(route('projects.reports.show', [$project, $v1]))->assertOk();
        $this->get(route('projects.reports.show', [$project, $v2]))->assertOk()
            ->assertSee('Earlier published assessments', false);
    }

    public function test_cross_project_report_access_is_blocked(): void
    {
        ['project' => $project, 'report' => $report] = $this->approvedReport();
        app(ProjectPublisher::class)->publish($report, $project->owner);
        $other = Project::factory()->create();

        $this->get(route('projects.reports.show', [$other, $report]))->assertNotFound();
        // Managing project A grants nothing under project B's URL.
        $this->actingAs($project->owner)
            ->post(route('projects.reports.publish', [$other, $report]))
            ->assertForbidden();
        // B's own maintainer gets a 404: the report is not B's.
        $this->actingAs($other->owner)
            ->post(route('projects.reports.publish', [$other, $report]))
            ->assertNotFound();
    }

    public function test_audit_trail_covers_review_lifecycle(): void
    {
        ['project' => $project, 'report' => $report] = $this->approvedReport();
        $report->forceFill(['review_status' => ReviewStatus::PendingReview])->save();

        $this->actingAs($project->owner)->post(route('projects.reports.approve', [$project, $report]), []);
        $this->actingAs($project->owner)->post(route('projects.reports.publish', [$project, $report]));

        $events = AuditEvent::where('project_id', $project->id)->pluck('event')->all();
        $this->assertSame(['report.approved', 'report.published'], $events);
    }
}
