<?php

namespace Tests\Feature;

use App\Jobs\ProcessMediaJob;
use App\Models\Project;
use App\Models\ProjectMedia;
use App\Models\User;
use App\Services\Media\MediaUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MediaProcessingRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function png(int $width = 400, int $height = 300): string
    {
        $img = imagecreatetruecolor($width, $height);
        $blue = imagecolorallocate($img, 40, 80, 180);
        imagefill($img, 0, 0, $blue);
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    private function projectFor(User $owner): Project
    {
        return Project::factory()->draft()->create(['owner_id' => $owner->id]);
    }

    private function processingRow(Project $project, User $owner, string $bytes, string $mime = 'image/png'): ProjectMedia
    {
        Storage::fake('local');

        $media = new ProjectMedia([
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'kind' => ProjectMedia::KIND_IMAGE,
            'category' => 'photo',
            'storage_key' => Str::random(40),
            'original_name' => 'device.png',
            'mime' => $mime,
            'size' => strlen($bytes),
            'width' => 400,
            'height' => 300,
            'sha256' => hash('sha256', $bytes),
            'original_sha256' => hash('sha256', $bytes),
            'processing_status' => ProjectMedia::STATUS_PROCESSING,
        ]);
        $media->save();
        Storage::disk('local')->put($media->originalPath(), $bytes);

        return $media;
    }

    public function test_failed_hook_maps_worker_environment_error_to_retryable_reason(): void
    {
        $owner = User::factory()->create();
        $media = $this->processingRow($this->projectFor($owner), $owner, $this->png());

        // Exact error class observed on the stale queue worker (no GD):
        // "Call to undefined function ... imagecreatefromstring()".
        (new ProcessMediaJob($media->id))->failed(new \Error('Call to undefined function imagecreatefromstring()'));

        $media->refresh();
        $this->assertSame(ProjectMedia::STATUS_FAILED, $media->processing_status);
        $this->assertSame(ProjectMedia::ERROR_PROCESSING_UNAVAILABLE, $media->processing_error);
        $this->assertTrue($media->isRetryable());
    }

    public function test_corrupt_bytes_fail_permanently_without_failed_jobs_row(): void
    {
        $owner = User::factory()->create();
        $media = $this->processingRow($this->projectFor($owner), $owner, 'not-an-image-at-all');

        (new ProcessMediaJob($media->id))->handle();

        $media->refresh();
        $this->assertSame(ProjectMedia::STATUS_FAILED, $media->processing_status);
        $this->assertSame(ProjectMedia::ERROR_INVALID_IMAGE, $media->processing_error);
        $this->assertFalse($media->isRetryable());
        $this->assertSame(0, \DB::table('failed_jobs')->count());
        $this->assertSame(0, \DB::table('jobs')->count());
    }

    public function test_retry_endpoint_requeues_failed_retryable_image_idempotently(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $project = $this->projectFor($owner);
        $media = $this->processingRow($project, $owner, $this->png());
        $media->forceFill([
            'processing_status' => ProjectMedia::STATUS_FAILED,
            'processing_error' => ProjectMedia::ERROR_PROCESSING_UNAVAILABLE,
        ])->save();

        $first = $this->actingAs($owner)->post(route('projects.media.retry', [$project, $media]));
        $first->assertSessionHas('status');
        $this->assertSame(ProjectMedia::STATUS_PROCESSING, $media->fresh()->processing_status);
        Queue::assertPushed(ProcessMediaJob::class, 1);

        // Second click while processing: truthful no-op, no duplicate job.
        $second = $this->actingAs($owner)->post(route('projects.media.retry', [$project, $media]));
        $second->assertSessionHas('status');
        Queue::assertPushed(ProcessMediaJob::class, 1);
    }

    public function test_retry_endpoint_refuses_permanent_failures_and_foreign_ids(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $project = $this->projectFor($owner);
        $media = $this->processingRow($project, $owner, 'corrupt');
        $media->forceFill([
            'processing_status' => ProjectMedia::STATUS_FAILED,
            'processing_error' => ProjectMedia::ERROR_INVALID_IMAGE,
        ])->save();

        $response = $this->actingAs($owner)->post(route('projects.media.retry', [$project, $media]));
        $response->assertSessionHas('error');
        $this->assertSame(ProjectMedia::STATUS_FAILED, $media->fresh()->processing_status);
        Queue::assertNotPushed(ProcessMediaJob::class);

        // Same owner, other project: update passes, media scoping 404s.
        $other = $this->projectFor($owner);
        $this->actingAs($owner)->post(route('projects.media.retry', [$other, $media]))->assertNotFound();
    }

    public function test_reupload_of_failed_image_reports_truthful_status(): void
    {
        Queue::fake();
        Storage::fake('local');
        $owner = User::factory()->create();
        $project = $this->projectFor($owner);

        $upload = fn () => UploadedFile::fake()->createWithContent('device.png', $this->png());

        $this->actingAs($owner)->post(route('projects.media.store-image', $project), ['images' => [$upload()]]);
        $media = ProjectMedia::where('project_id', $project->id)->firstOrFail();
        $media->forceFill([
            'processing_status' => ProjectMedia::STATUS_FAILED,
            'processing_error' => ProjectMedia::ERROR_PROCESSING_UNAVAILABLE,
        ])->save();

        $response = $this->actingAs($owner)->post(route('projects.media.store-image', $project), ['images' => [$upload()]]);
        $message = (string) session('status');
        $this->assertStringNotContainsString('Processing runs in the background', $message);
        $this->assertTrue(
            str_contains($message, 'failed') || str_contains($message, 'Retry'),
            'Reused failed upload must point at recovery, got: '.$message
        );
        $this->assertSame(1, ProjectMedia::where('project_id', $project->id)->count());
    }

    public function test_reupload_of_ready_image_does_not_claim_background_processing(): void
    {
        Queue::fake();
        Storage::fake('local');
        $owner = User::factory()->create();
        $project = $this->projectFor($owner);

        $upload = fn () => UploadedFile::fake()->createWithContent('device.png', $this->png());

        $this->actingAs($owner)->post(route('projects.media.store-image', $project), ['images' => [$upload()]]);
        ProjectMedia::where('project_id', $project->id)->update(['processing_status' => ProjectMedia::STATUS_READY]);

        $response = $this->actingAs($owner)->post(route('projects.media.store-image', $project), ['images' => [$upload()]]);
        $this->assertStringNotContainsString('Processing runs in the background', (string) session('status'));
    }

    public function test_queued_job_processes_through_real_database_worker(): void
    {
        // Async-path integration: real dispatch onto the database
        // queue, real queue:work execution — not a direct call.
        config(['queue.default' => 'database']);
        Storage::fake('local');
        $owner = User::factory()->create();
        $project = $this->projectFor($owner);

        $result = MediaUploadService::storeImage(
            $project, $owner, UploadedFile::fake()->createWithContent('device.png', $this->png())
        );
        $this->assertTrue($result['ok']);
        $this->assertSame(1, \DB::table('jobs')->count());

        $this->artisan('queue:work', ['--once' => true, '--tries' => 1])->assertSuccessful();

        $media = $result['media']->fresh();
        $this->assertSame(ProjectMedia::STATUS_READY, $media->processing_status);
        foreach (['thumb', 'card', 'wide'] as $variant) {
            Storage::disk('local')->assertExists($media->variantPath($variant));
        }
        $this->assertSame(0, \DB::table('failed_jobs')->count());
    }

    public function test_picker_and_library_distinguish_failed_from_processing(): void
    {
        $owner = User::factory()->create();
        $project = $this->projectFor($owner);
        $media = $this->processingRow($project, $owner, $this->png());
        $media->forceFill([
            'processing_status' => ProjectMedia::STATUS_FAILED,
            'processing_error' => ProjectMedia::ERROR_PROCESSING_UNAVAILABLE,
        ])->save();

        $response = $this->actingAs($owner)->get(route('projects.edit', $project));
        $response->assertOk();
        // The failed item must not be labelled "Processing…" anywhere.
        $response->assertDontSee('Processing…', false);
        $response->assertSee('Failed', false);
        $response->assertSee('Retry processing', false);
        $response->assertSee('Image processing is temporarily unavailable', false);
        // No paths, traces, or exception internals leak into the page.
        $response->assertDontSee('imagecreatefromstring', false);
        $response->assertDontSee('storage/app', false);
    }
}
