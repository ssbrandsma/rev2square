<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeBinaryJob;
use App\Jobs\ExtractArtifactJob;
use App\Models\BinaryAnalysis;
use App\Models\Project;
use App\Models\ProjectArtifact;
use App\Models\User;
use App\Services\Artifacts\ArtifactExtractor;
use App\Services\Artifacts\ArtifactStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArtifactStorageSharingTest extends TestCase
{
    use RefreshDatabase;

    private function seedProject(): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->draft()->create(['owner_id' => $owner->id]);

        return [$project->fresh(), $owner];
    }

    private function tinyElf(): string
    {
        return "\x7fELF".str_repeat("\0", 60);
    }

    private function makeRow(Project $project, User $uploader, string $bytes, string $name, string $state): ProjectArtifact
    {
        return ProjectArtifact::create([
            'project_id' => $project->id,
            'uploader_id' => $uploader->id,
            'original_name' => $name,
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'storage_key' => str()->random(40),
            'format' => ArtifactExtractor::detect($bytes, $name),
            'state' => $state,
        ]);
    }

    private function putSnapshot(ProjectArtifact $artifact): void
    {
        Storage::disk('artifacts')->put($artifact->snapshotPath(), json_encode([
            'schema' => '1',
            'extractor' => 'test/1.0',
            'extracted_at' => now()->toIso8601String(),
            'elapsed_ms' => 0,
            'limits' => [],
            'detection' => [],
            'detection_warnings' => [],
            'warnings' => [],
            'data' => [],
        ]));
    }

    public function test_storage_status_reports_ok_and_missing_on_fake_disk(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $bytes = $this->tinyElf();
        $artifact = $this->makeRow($project, $owner, $bytes, 'tiny.elf', ProjectArtifact::STATE_QUEUED);
        Storage::disk('artifacts')->put($artifact->rawPath(), $bytes);

        $this->assertSame(ArtifactStorage::OK, ArtifactStorage::status('artifacts', $artifact->rawPath()));

        Storage::disk('artifacts')->delete($artifact->rawPath());

        // Ancestors are healthy on the fake disk, so this is genuinely
        // missing — not an unreadable mount.
        $this->assertSame(ArtifactStorage::MISSING, ArtifactStorage::status('artifacts', $artifact->rawPath()));
    }

    public function test_extraction_failure_names_retry_not_only_reupload(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $bytes = $this->tinyElf();
        $artifact = ProjectArtifact::create([
            'project_id' => $project->id,
            'uploader_id' => $owner->id,
            'original_name' => 'tiny.elf',
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'storage_key' => str()->random(40),
            'format' => ArtifactExtractor::detect($bytes, 'tiny.elf'),
            'state' => ProjectArtifact::STATE_QUEUED,
        ]);

        ArtifactExtractor::extract($artifact->fresh());
        $artifact->refresh();

        $this->assertSame(ProjectArtifact::STATE_FAILED, $artifact->state);
        $this->assertSame('missing_input', $artifact->failure_code);
        $this->assertStringContainsString('Retry from this page', (string) $artifact->failure_message);
        $this->assertStringNotContainsString('Re-upload it to investigate', (string) $artifact->failure_message);
    }

    public function test_analysis_missing_original_is_retryable_after_restore(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $bytes = $this->tinyElf();
        $artifact = ProjectArtifact::create([
            'project_id' => $project->id,
            'uploader_id' => $owner->id,
            'original_name' => 'tiny.elf',
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'storage_key' => str()->random(40),
            'format' => ArtifactExtractor::detect($bytes, 'tiny.elf'),
            'state' => ProjectArtifact::STATE_READY,
        ]);
        $analysis = BinaryAnalysis::create([
            'project_artifact_id' => $artifact->id,
            'requested_by' => $owner->id,
            'adapter' => 'php-native',
            'adapter_version' => '1.0.0',
            'target' => 'x86-64',
            'config' => ['target' => 'x86-64'],
            'status' => BinaryAnalysis::STATUS_QUEUED,
            'dedupe_key' => str()->random(64),
        ]);

        (new AnalyzeBinaryJob($analysis->id))->handle();
        $analysis->refresh();

        $this->assertSame(BinaryAnalysis::STATUS_FAILED, $analysis->status);
        $this->assertSame('missing_original', $analysis->error_code);
        $this->assertTrue($analysis->isRetryable());
        $this->assertStringContainsString('Retry from this page', (string) $analysis->error_message);

        // Storage restored: retry re-queues instead of demanding re-upload.
        // (Queue faked so the re-dispatched job does not run inline and
        // move the row past queued before the assertion.)
        Queue::fake();
        Storage::disk('artifacts')->put($artifact->rawPath(), $bytes);
        $this->actingAs($owner)
            ->from(route('projects.artifacts.analyses.show', [$project, $artifact, $analysis]))
            ->post(route('projects.artifacts.analyses.retry', [$project, $artifact, $analysis]))
            ->assertSessionHas('status', 'Analysis queued again.');
        $this->assertSame(BinaryAnalysis::STATUS_QUEUED, $analysis->fresh()->status);
        Queue::assertPushed(AnalyzeBinaryJob::class);
    }

    public function test_artifact_retry_reports_still_unavailable_then_recovers(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $bytes = $this->tinyElf();
        $artifact = ProjectArtifact::create([
            'project_id' => $project->id,
            'uploader_id' => $owner->id,
            'original_name' => 'tiny.elf',
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'storage_key' => str()->random(40),
            'format' => ArtifactExtractor::detect($bytes, 'tiny.elf'),
            'state' => ProjectArtifact::STATE_FAILED,
            'failure_code' => 'missing_input',
            'failure_message' => ArtifactStorage::unavailableMessage(),
        ]);

        $this->actingAs($owner)
            ->from(route('projects.artifacts.show', [$project, $artifact]))
            ->post(route('projects.artifacts.retry', [$project, $artifact]))
            ->assertSessionHas('error');
        $this->assertStringContainsString('still unavailable', (string) session('error'));
        $this->assertSame(ProjectArtifact::STATE_FAILED, $artifact->fresh()->state);

        // Restore the bytes, then the same retry re-queues extraction
        // instead of failing. (Queue faked: the re-dispatched job must
        // not run inline here; extraction success from bytes is covered
        // by the parser suites.)
        Queue::fake();
        Storage::disk('artifacts')->put($artifact->rawPath(), $bytes);
        $this->actingAs($owner)
            ->from(route('projects.artifacts.show', [$project, $artifact]))
            ->post(route('projects.artifacts.retry', [$project, $artifact]))
            ->assertSessionHas('status', 'Extraction queued again.');
        $this->assertSame(ProjectArtifact::STATE_QUEUED, $artifact->fresh()->state);
        Queue::assertPushed(ExtractArtifactJob::class);
    }

    public function test_show_warns_when_snapshot_lost_but_stays_available(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $bytes = $this->tinyElf();
        $artifact = $this->makeRow($project, $owner, $bytes, 'tiny.elf', ProjectArtifact::STATE_READY);
        Storage::disk('artifacts')->put($artifact->rawPath(), $bytes);
        $this->putSnapshot($artifact);
        $this->assertTrue($artifact->fresh()->isUsable());

        $this->actingAs($owner)->get(route('projects.artifacts.show', [$project, $artifact]))
            ->assertOk()
            ->assertDontSee('temporarily unavailable', false);

        Storage::disk('artifacts')->delete($artifact->snapshotPath());

        $this->actingAs($owner)->get(route('projects.artifacts.show', [$project, $artifact]))
            ->assertOk()
            ->assertSee('temporarily unavailable', false)
            ->assertSee('nothing needs re-uploading', false);
    }

    public function test_verify_storage_command_reports_and_recovers(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $bytes = $this->tinyElf();
        $artifact = $this->makeRow($project, $owner, $bytes, 'tiny.elf', ProjectArtifact::STATE_READY);
        Storage::disk('artifacts')->put($artifact->rawPath(), $bytes);
        $this->putSnapshot($artifact);

        $this->artisan('artifacts:verify-storage')->assertSuccessful();

        Storage::disk('artifacts')->delete($artifact->rawPath());
        $this->artisan('artifacts:verify-storage')
            ->assertFailed()
            ->expectsOutputToContain('raw file missing');

        Storage::disk('artifacts')->put($artifact->rawPath(), $bytes);
        $this->artisan('artifacts:verify-storage')->assertSuccessful();
    }

    /**
     * Slice one top-level service block out of a compose file.
     */
    private function serviceBlock(string $compose, string $service): string
    {
        $capture = [];
        $in = false;
        foreach (explode("\n", $compose) as $line) {
            if (preg_match('/^  '.preg_quote($service, '/').':\s*$/', $line)) {
                $in = true;

                continue;
            }
            if ($in && preg_match('/^(  [A-Za-z0-9_-]+:\s*|volumes:\s*)$/', $line)) {
                break;
            }
            if ($in) {
                $capture[] = $line;
            }
        }
        $this->assertNotSame([], $capture, "service {$service} not found");

        return implode("\n", $capture);
    }

    public function test_prod_composes_share_durable_app_storage(): void
    {
        $repo = base_path();
        $cases = [
            'compose.prod.yaml' => ['app', 'queue'],
            'compose.virtualmin.yaml' => ['app', 'queue', 'analysis-coordinator'],
        ];

        foreach ($cases as $file => $services) {
            $compose = file_get_contents($repo.'/'.$file);
            // Named volume declared once at top level.
            $this->assertStringContainsString("\n  app-storage:\n", $compose, $file);
            foreach ($services as $service) {
                $block = $this->serviceBlock($compose, $service);
                // Exact read-write mount shared by every authorized
                // reader/writer; a :ro suffix would break writers.
                $this->assertStringContainsString("- app-storage:/var/www/storage/app\n", $block, "{$file} {$service}");
                // Same-UID sharing: image and runtime user agree, so no
                // chmod is needed and modes stay private.
                $this->assertStringContainsString('user: "www-data"', $block, "{$file} {$service}");
            }
        }

        // The image side of the UID contract: storage pre-chowned so the
        // named-volume init copy preserves uid 33.
        $this->assertStringContainsString(
            'chown -R www-data:www-data /var/www/storage',
            file_get_contents($repo.'/docker/php/Dockerfile.prod')
        );
    }

    public function test_runners_and_scheduler_mount_no_app_storage(): void
    {
        $compose = file_get_contents(base_path().'/compose.virtualmin.yaml');

        // Isolated runners keep only their per-job spool inputs: no
        // general artifact access and no app environment.
        foreach (['objdump-runner', 'ghidra-runner', 'jadx-runner', 'ilspy-runner', 'extract-runner'] as $runner) {
            $block = $this->serviceBlock($compose, $runner);
            $this->assertStringNotContainsString('app-storage', $block, $runner);
            $this->assertStringNotContainsString('env_file', $block, $runner);
        }

        // Scheduler only touches the database and dispatches jobs, so it
        // intentionally stays off the shared mount in both variants.
        foreach (['compose.prod.yaml', 'compose.virtualmin.yaml'] as $file) {
            $this->assertStringNotContainsString(
                'app-storage',
                $this->serviceBlock(file_get_contents(base_path().'/'.$file), 'scheduler'),
                $file
            );
        }
    }

    public function test_no_world_writable_modes_or_public_symlinks_in_prod_wiring(): void
    {
        $repo = base_path();

        // Private permissions: nothing world-writable in production
        // wiring (the dev entrypoint's bind-mount chmod is out of scope).
        // Comments are stripped first: prose like "Never 777" must not
        // trip the matcher.
        foreach (['docker/php/Dockerfile.prod', 'compose.prod.yaml', 'compose.virtualmin.yaml'] as $file) {
            $code = (string) preg_replace('/#[^\n]*/', '', (string) file_get_contents($repo.'/'.$file));
            $this->assertStringNotContainsString('777', $code, $file);
            $this->assertStringNotContainsString('a+rwx', $code, $file);
        }

        // Uploads stay private: the public disk is never linked into the
        // web root by any image or entrypoint.
        foreach (['docker/php/Dockerfile.prod', 'docker/php/Dockerfile', 'docker/php/entrypoint-prod.sh', 'docker/php/entrypoint.sh'] as $file) {
            $this->assertStringNotContainsString('storage:link', file_get_contents($repo.'/'.$file), $file);
        }
    }
}
