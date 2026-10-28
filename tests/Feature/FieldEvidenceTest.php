<?php

namespace Tests\Feature;

use App\Enums\CorrectionStatus;
use App\Enums\PublicationStatus;
use App\Enums\TestOutcome;
use App\Models\Attachment;
use App\Models\ContributorTask;
use App\Models\Device;
use App\Models\DeviceVariant;
use App\Models\Observation;
use App\Models\ObservationCorrection;
use App\Models\Project;
use App\Models\TestReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FieldEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function publishedProject(): Project
    {
        return Project::factory()->create(['publication_status' => PublicationStatus::Published]);
    }

    private function linkVariant(Project $project): DeviceVariant
    {
        $device = Device::factory()->create();
        $project->devices()->sync([$device->id => ['device_variant_id' => null]]);

        return DeviceVariant::factory()->create(['device_id' => $device->id]);
    }

    public function test_member_can_share_observation_with_task_and_variant(): void
    {
        $project = $this->publishedProject();
        $variant = $this->linkVariant($project);
        $task = ContributorTask::factory()->create(['project_id' => $project->id]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.observations.store', $project), [
                'title' => '3.3V rail is stable',
                'body' => 'Measured with a multimeter at the test pads.',
                'contributor_task_id' => $task->id,
                'device_variant_id' => $variant->id,
            ])
            ->assertRedirect(route('projects.show', $project).'#observations');

        $observation = Observation::firstOrFail();
        $this->assertSame($task->id, $observation->contributor_task_id);
        $this->assertSame($variant->id, $observation->device_variant_id);
        $this->assertFalse($observation->isVerified());

        $this->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('3.3V rail is stable', false)
            ->assertSee('Unverified', false);
    }

    public function test_observation_rejects_foreign_task_and_variant(): void
    {
        $project = $this->publishedProject();
        $other = $this->publishedProject();
        $foreignTask = ContributorTask::factory()->create(['project_id' => $other->id]);
        $foreignVariant = DeviceVariant::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('projects.observations.store', $project), [
                'title' => 'Sneaky note',
                'body' => 'Trying a foreign task.',
                'contributor_task_id' => $foreignTask->id,
            ])
            ->assertStatus(422);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.observations.store', $project), [
                'title' => 'Sneaky note',
                'body' => 'Trying a foreign variant.',
                'device_variant_id' => $foreignVariant->id,
            ])
            ->assertStatus(422);

        $this->assertSame(0, Observation::count());
    }

    public function test_maintainer_verifies_and_edit_clears_verification(): void
    {
        $project = $this->publishedProject();
        $observation = Observation::factory()->create(['project_id' => $project->id]);

        // Non-maintainer cannot verify.
        $this->actingAs(User::factory()->create())
            ->patch(route('projects.observations.verify', [$project, $observation]), ['verified' => '1'])
            ->assertForbidden();

        $this->actingAs($project->owner)
            ->patch(route('projects.observations.verify', [$project, $observation]), ['verified' => '1'])
            ->assertRedirect();
        $this->assertTrue($observation->fresh()->isVerified());

        $this->get(route('projects.show', $project))->assertSee('Verified hardware claim', false);

        // Author edits -> verification cleared.
        $this->actingAs($observation->author)
            ->patch(route('projects.observations.update', [$project, $observation]), [
                'title' => $observation->title,
                'body' => 'Updated wording after remeasuring.',
            ])
            ->assertRedirect();
        $this->assertFalse($observation->fresh()->isVerified());
    }

    public function test_correction_workflow_accept_and_dismiss(): void
    {
        $project = $this->publishedProject();
        $observation = Observation::factory()->create(['project_id' => $project->id, 'body' => 'Original wording.']);
        $member = User::factory()->create();

        $this->actingAs($member)
            ->post(route('projects.observations.corrections.store', [$project, $observation]), [
                'body' => 'Corrected wording.',
            ])
            ->assertRedirect();

        $correction = ObservationCorrection::firstOrFail();
        $this->assertSame(CorrectionStatus::Pending, $correction->status);

        // Non-maintainer cannot review.
        $this->actingAs($member)
            ->patch(route('projects.observations.corrections.review', [$project, $observation, $correction]), ['decision' => 'accept'])
            ->assertForbidden();

        $this->actingAs($project->owner)
            ->patch(route('projects.observations.corrections.review', [$project, $observation, $correction]), ['decision' => 'accept'])
            ->assertRedirect();

        $this->assertSame(CorrectionStatus::Accepted, $correction->fresh()->status);
        $this->assertSame('Corrected wording.', $observation->fresh()->body);

        // Second correction, dismissed.
        $second = ObservationCorrection::create([
            'observation_id' => $observation->id,
            'user_id' => $member->id,
            'body' => 'Another proposal.',
            'status' => CorrectionStatus::Pending,
        ]);

        $this->actingAs($project->owner)
            ->patch(route('projects.observations.corrections.review', [$project, $observation, $second]), ['decision' => 'dismiss'])
            ->assertRedirect();

        $this->assertSame(CorrectionStatus::Dismissed, $second->fresh()->status);
        $this->assertSame('Corrected wording.', $observation->fresh()->body);
    }

    public function test_member_can_file_test_report_with_outcome(): void
    {
        $project = $this->publishedProject();
        $variant = $this->linkVariant($project);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.test-reports.store', $project), [
                'title' => 'Serial console works',
                'outcome' => TestOutcome::Worked->value,
                'body' => "1. Solder header.\n2. Connect 115200 8N1.\n3. See boot log.",
                'device_variant_id' => $variant->id,
            ])
            ->assertRedirect();

        $report = TestReport::firstOrFail();
        $this->assertSame(TestOutcome::Worked, $report->outcome);
        $this->assertFalse($report->isConfirmed());

        $this->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Serial console works', false)
            ->assertSee('Worked: 1', false);

        $this->get(route('projects.test-reports.show', [$project, $report]))
            ->assertOk()
            ->assertSee('See boot log', false)
            ->assertSee('Awaiting confirmation', false);
    }

    public function test_second_user_can_confirm_but_author_cannot(): void
    {
        $project = $this->publishedProject();
        $report = TestReport::factory()->create(['project_id' => $project->id]);

        $this->actingAs($report->author)
            ->post(route('projects.test-reports.confirm', [$project, $report]))
            ->assertForbidden();

        $confirmer = User::factory()->create();
        $this->actingAs($confirmer)
            ->post(route('projects.test-reports.confirm', [$project, $report]))
            ->assertRedirect();
        $this->assertTrue($report->fresh()->isConfirmed());
        $this->assertSame($confirmer->id, $report->fresh()->confirmed_by_user_id);

        $this->get(route('projects.test-reports.show', [$project, $report]))
            ->assertSee('Confirmed by '.$confirmer->name, false);
    }

    public function test_editing_test_report_clears_confirmation(): void
    {
        $project = $this->publishedProject();
        $report = TestReport::factory()->create([
            'project_id' => $project->id,
            'confirmed_at' => now(),
            'confirmed_by_user_id' => User::factory()->create()->id,
        ]);

        $this->actingAs($report->author)
            ->patch(route('projects.test-reports.update', [$project, $report]), [
                'title' => $report->title,
                'outcome' => TestOutcome::Partially->value,
                'body' => 'Revised steps.',
            ])
            ->assertRedirect();

        $this->assertFalse($report->fresh()->isConfirmed());
    }

    public function test_observation_accepts_documentation_link_and_environment_on_reports(): void
    {
        $project = $this->publishedProject();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.observations.store', $project), [
                'title' => 'Pinout matches the datasheet',
                'body' => 'Checked against section 4.',
                'source_url' => 'https://example.test/datasheet.pdf',
            ])
            ->assertRedirect();

        $this->assertSame('https://example.test/datasheet.pdf', Observation::firstOrFail()->source_url);

        $this->get(route('projects.show', $project))
            ->assertSee('Referenced document', false)
            ->assertSee('https://example.test/datasheet.pdf', false);

        $this->actingAs($user)
            ->post(route('projects.test-reports.store', $project), [
                'title' => 'UART boot log',
                'outcome' => TestOutcome::Worked->value,
                'body' => 'Steps here.',
                'environment' => 'CH340 adapter, 115200 8N1',
            ])
            ->assertRedirect();

        $report = TestReport::firstOrFail();
        $this->assertSame('CH340 adapter, 115200 8N1', $report->environment);
        $this->get(route('projects.test-reports.show', [$project, $report]))
            ->assertSee('CH340 adapter, 115200 8N1', false);
    }

    public function test_variant_matrix_shows_per_revision_outcomes(): void
    {
        $project = $this->publishedProject();
        $device = Device::factory()->create();
        $project->devices()->sync([$device->id => ['device_variant_id' => null]]);
        $revA = DeviceVariant::factory()->create(['device_id' => $device->id, 'hardware_revision' => 'A']);
        $revB = DeviceVariant::factory()->create(['device_id' => $device->id, 'hardware_revision' => 'B']);

        TestReport::factory()->create(['project_id' => $project->id, 'device_variant_id' => $revA->id, 'outcome' => TestOutcome::Worked]);
        TestReport::factory()->create(['project_id' => $project->id, 'device_variant_id' => $revB->id, 'outcome' => TestOutcome::Failed]);

        $this->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Test outcomes per hardware variant', false)
            ->assertSee('never implies the whole model works', false);
    }

    public function test_attachments_upload_review_download_delete(): void
    {
        Storage::fake('local');

        $project = $this->publishedProject();
        $report = TestReport::factory()->create(['project_id' => $project->id]);
        $user = User::factory()->create();

        $file = UploadedFile::fake()->create('boot.log', 10, 'text/plain');

        $this->actingAs($user)
            ->post(route('projects.test-reports.attachments.store', [$project, $report]), ['file' => $file])
            ->assertRedirect();

        $attachment = Attachment::firstOrFail();
        $this->assertSame('boot.log', $attachment->original_name);
        Storage::disk('local')->assertExists($attachment->path);

        // Executables are rejected.
        $evil = UploadedFile::fake()->create('run.php', 10, 'application/x-php');
        $this->actingAs($user)
            ->post(route('projects.test-reports.attachments.store', [$project, $report]), ['file' => $evil])
            ->assertSessionHasErrors('file');
        $this->assertSame(1, Attachment::count());

        // Anyone who can view the project can download.
        $this->get(route('projects.attachments.download', [$project, $attachment]))->assertOk();

        // Maintainer reviews.
        $this->actingAs($project->owner)
            ->patch(route('projects.attachments.review', [$project, $attachment]))
            ->assertRedirect();
        $this->assertNotNull($attachment->fresh()->reviewed_at);

        // Strangers cannot delete; the uploader can.
        $this->actingAs(User::factory()->create())
            ->delete(route('projects.attachments.destroy', [$project, $attachment]))
            ->assertForbidden();

        $this->actingAs($user)
            ->delete(route('projects.attachments.destroy', [$project, $attachment]))
            ->assertRedirect();
        $this->assertSame(0, Attachment::count());
    }

    public function test_evidence_page_lists_human_sources(): void
    {
        $project = $this->publishedProject();
        Observation::factory()->create(['project_id' => $project->id, 'title' => 'Capacitor looks fine']);
        TestReport::factory()->create(['project_id' => $project->id, 'title' => 'Power-on test']);

        $this->actingAs($project->owner)
            ->get(route('projects.evidence', $project))
            ->assertOk()
            ->assertSee('Human sources', false)
            ->assertSee('Capacitor looks fine', false)
            ->assertSee('Power-on test', false);
    }

    public function test_unrelated_user_cannot_edit_field_evidence(): void
    {
        $project = $this->publishedProject();
        $observation = Observation::factory()->create(['project_id' => $project->id]);
        $report = TestReport::factory()->create(['project_id' => $project->id]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->patch(route('projects.observations.update', [$project, $observation]), [
                'title' => 'Hijacked',
                'body' => 'Hijacked.',
            ])
            ->assertForbidden();

        $this->actingAs($stranger)
            ->patch(route('projects.test-reports.update', [$project, $report]), [
                'title' => 'Hijacked',
                'outcome' => TestOutcome::Failed->value,
                'body' => 'Hijacked.',
            ])
            ->assertForbidden();
    }
}
