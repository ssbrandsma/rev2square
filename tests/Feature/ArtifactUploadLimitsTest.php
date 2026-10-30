<?php

namespace Tests\Feature;

use App\Enums\GlobalRole;
use App\Models\Project;
use App\Models\ProjectArtifact;
use App\Models\User;
use App\Services\Admin\AppSettings;
use App\Services\Artifacts\ArtifactUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArtifactUploadLimitsTest extends TestCase
{
    use RefreshDatabase;

    private const LIMIT = 67108864; // 64 MiB

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['global_role' => GlobalRole::Admin])->save();

        return $admin->fresh();
    }

    private function makeSparseUpload(string $name, string $head, int $size): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        $fh = fopen($path, 'wb');
        fwrite($fh, $head);
        fseek($fh, $size - 1);
        fwrite($fh, "\0");
        fclose($fh);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_small_elf_and_pcap_uploads_still_work(): void
    {
        Storage::fake('artifacts');
        $project = Project::factory()->create();

        $response = $this->actingAs($project->owner)->post(route('projects.artifacts.store', $project), [
            'files' => [
                UploadedFile::fake()->createWithContent('tiny.elf', "\x7fELF".str_repeat("\0", 60)),
                UploadedFile::fake()->createWithContent('tiny.pcap', "\xd4\xc3\xb2\xa1".str_repeat("\0", 20)),
            ],
        ]);

        $response->assertRedirect(route('projects.artifacts.index', $project));
        $response->assertSessionHas('status');
        $this->assertSame(2, ProjectArtifact::where('project_id', $project->id)->count());
        $this->assertSame(
            [ProjectArtifact::FORMAT_ELF, ProjectArtifact::FORMAT_PCAP],
            ProjectArtifact::where('project_id', $project->id)->orderBy('id')->pluck('format')->all()
        );
    }

    public function test_file_exactly_at_64mib_is_accepted(): void
    {
        Storage::fake('artifacts');
        $project = Project::factory()->create();

        $response = $this->actingAs($project->owner)->post(route('projects.artifacts.store', $project), [
            'files' => [$this->makeSparseUpload('exact.bin', "\x7fELF".str_repeat("\0", 60), self::LIMIT)],
        ]);

        $response->assertRedirect(route('projects.artifacts.index', $project));
        $response->assertSessionHas('status');
        $artifact = ProjectArtifact::where('project_id', $project->id)->sole();
        $this->assertSame(self::LIMIT, (int) $artifact->byte_size);
        $this->assertSame(ProjectArtifact::FORMAT_ELF, $artifact->format);
        $this->assertSame(64, strlen($artifact->sha256));
        $this->assertSame(self::LIMIT, Storage::disk('artifacts')->size($artifact->rawPath()));
    }

    public function test_file_one_byte_over_64mib_is_rejected_clearly(): void
    {
        Storage::fake('artifacts');
        $project = Project::factory()->create();

        $response = $this->actingAs($project->owner)->post(route('projects.artifacts.store', $project), [
            'files' => [$this->makeSparseUpload('over.bin', "\x7fELF".str_repeat("\0", 60), self::LIMIT + 1)],
        ]);

        $response->assertSessionHas('error');
        $error = session('error');
        $this->assertStringContainsString('the limit is 64.0 MiB', $error);
        $this->assertStringNotContainsString('must be a file', strtolower($error));
        $this->assertSame(0, ProjectArtifact::count());
        $this->assertSame([], Storage::disk('artifacts')->allFiles());
    }

    public function test_misleading_extension_uses_content_detection(): void
    {
        Storage::fake('artifacts');
        $project = Project::factory()->create();

        // ELF bytes in a .txt wrapper: contents decide, extension is a hint.
        $response = $this->actingAs($project->owner)->post(route('projects.artifacts.store', $project), [
            'files' => [UploadedFile::fake()->createWithContent('notes.txt', "\x7fELF".str_repeat("\0", 60))],
        ]);

        $response->assertSessionHas('status');
        $this->assertSame(ProjectArtifact::FORMAT_ELF, ProjectArtifact::where('project_id', $project->id)->sole()->format);
    }

    public function test_unsupported_input_rejected_without_orphans(): void
    {
        Storage::fake('artifacts');
        $project = Project::factory()->create();

        $response = $this->actingAs($project->owner)->post(route('projects.artifacts.store', $project), [
            'files' => [UploadedFile::fake()->createWithContent('random.bin', str_repeat('This is plain text, not a binary. ', 16))],
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('not recognized', session('error'));
        $this->assertSame(0, ProjectArtifact::count());
        $this->assertSame([], Storage::disk('artifacts')->allFiles());
    }

    public function test_mixed_batch_reports_per_file_results(): void
    {
        Storage::fake('artifacts');
        $project = Project::factory()->create();

        $response = $this->actingAs($project->owner)->post(route('projects.artifacts.store', $project), [
            'files' => [
                UploadedFile::fake()->createWithContent('good.elf', "\x7fELF".str_repeat("\0", 60)),
                UploadedFile::fake()->createWithContent('bad.bin', str_repeat('This is plain text, not a binary. ', 16)),
            ],
        ]);

        $response->assertSessionHas('status');
        $this->assertStringContainsString('1 new', session('status'));
        $this->assertStringContainsString('1 rejected', session('status'));
        $this->assertSame(1, ProjectArtifact::where('project_id', $project->id)->count());
    }

    public function test_oversized_request_body_fails_clearly_not_as_missing_file(): void
    {
        Storage::fake('artifacts');
        $project = Project::factory()->create();

        // Simulate PHP discarding a body larger than post_max_size: the
        // framework sees an empty request with a large Content-Length.
        $response = $this->actingAs($project->owner)
            ->from(route('projects.agent.index', $project))
            ->post(route('projects.artifacts.store', $project), [], ['Content-Length' => (string) (90 * 1024 * 1024)]);

        $response->assertSessionHas('error');
        $error = session('error');
        $this->assertStringContainsString('exceeds the server request limit', $error);
        $this->assertStringContainsString('one at a time', $error);
        $this->assertStringNotContainsString('required', strtolower($error));
        $this->assertSame(0, ProjectArtifact::count());
    }

    public function test_lower_configured_limit_is_respected(): void
    {
        config(['artifacts.max_upload_bytes' => 1024]);
        Storage::fake('artifacts');
        $project = Project::factory()->create();

        $ok = $this->actingAs($project->owner)->post(route('projects.artifacts.store', $project), [
            'files' => [UploadedFile::fake()->createWithContent('small.elf', "\x7fELF".str_repeat("\0", 60))],
        ]);
        $ok->assertSessionHas('status');

        $rejected = $this->actingAs($project->owner)->post(route('projects.artifacts.store', $project), [
            'files' => [UploadedFile::fake()->createWithContent('big.elf', "\x7fELF".str_repeat("\0", 2048))],
        ]);
        $rejected->assertSessionHas('error');
        $this->assertStringContainsString('the limit is 1 KiB', session('error'));
    }

    public function test_environment_override_locks_and_wins(): void
    {
        $_SERVER['ARTIFACTS_MAX_UPLOAD_BYTES'] = '2048';
        try {
            $this->assertTrue(AppSettings::isLocked('artifacts.max_upload_bytes'));
            $this->assertSame(2048, AppSettings::get('artifacts.max_upload_bytes'));
        } finally {
            unset($_SERVER['ARTIFACTS_MAX_UPLOAD_BYTES']);
        }
    }

    public function test_admin_setting_accepts_64mib_and_validates_bounds(): void
    {
        $admin = $this->makeAdmin();
        $url = route('admin.settings.update');

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->patch($url, ['settings' => ['artifacts.max_upload_bytes' => (string) self::LIMIT], 'reason' => 'Raise.'])
            ->assertSessionHas('status');
        $this->assertSame(self::LIMIT, AppSettings::get('artifacts.max_upload_bytes'));

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->patch($url, ['settings' => ['artifacts.max_upload_bytes' => '512'], 'reason' => 'Too low.'])
            ->assertSessionHas('error');
        $this->assertStringContainsString('below its minimum', session('error'));
        $this->assertSame(self::LIMIT, AppSettings::get('artifacts.max_upload_bytes'));

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->patch($url, ['settings' => ['artifacts.max_upload_bytes' => 'not-a-number'], 'reason' => 'NaN.'])
            ->assertSessionHas('error');
        $this->assertStringContainsString('must be a number', session('error'));
        $this->assertSame(self::LIMIT, AppSettings::get('artifacts.max_upload_bytes'));
    }

    public function test_upload_form_carries_limits_and_client_guard(): void
    {
        $project = Project::factory()->create();

        $page = $this->actingAs($project->owner)->get(route('projects.agent.index', $project))->assertOk();
        $page->assertSee('64 MiB per file', false);
        $page->assertSee('data-per-file-bytes="67108864"', false);
        $page->assertSee('data-batch-bytes="83886080"', false);
        $page->assertSee('data-upload-guard', false);
    }

    public function test_upload_form_shows_progress_indicator(): void
    {
        $project = Project::factory()->create();

        $page = $this->actingAs($project->owner)->get(route('projects.agent.index', $project))->assertOk();
        $page->assertSee('<progress', false);
        $page->assertSee('data-upload-progress-wrap', false);
        $page->assertSee('data-upload-progress-text', false);
        $page->assertSee('data-upload-submit', false);
        // XHR uploader with byte progress, 413 handling and a plain-POST
        // fallback when XMLHttpRequest upload events are unavailable.
        $page->assertSee('upload.onprogress', false);
        $page->assertSee('responseURL', false);
        $page->assertSee('degrades to a plain form POST', false);
    }

    public function test_php_upload_ini_is_wired_into_dev_and_prod_images(): void
    {
        $repo = base_path();
        // Regression guard: the ini existed but was never mounted into the
        // running dev containers, so PHP silently kept its 8M default.
        $compose = file_get_contents($repo.'/compose.dev.yaml');
        $this->assertGreaterThanOrEqual(2, substr_count($compose, './docker/php/conf.d-uploads.ini:/usr/local/etc/php/conf.d/uploads.ini:ro'));
        $this->assertStringContainsString('conf.d-uploads.ini', file_get_contents($repo.'/docker/php/Dockerfile.prod'));
    }

    public function test_infrastructure_configs_declare_matching_limits(): void
    {
        $repo = base_path();
        $this->assertStringContainsString('upload_max_filesize = 65M', file_get_contents($repo.'/docker/php/conf.d-uploads.ini'));
        $this->assertStringContainsString('post_max_size = 80M', file_get_contents($repo.'/docker/php/conf.d-uploads.ini'));
        $this->assertStringContainsString('client_max_body_size 80m;', file_get_contents($repo.'/docker/nginx/default.conf'));
        $this->assertStringContainsString('client_max_body_size 80m;', file_get_contents($repo.'/docker/nginx/default.prod.conf'));
        $this->assertStringContainsString('error_page 413 /413.html;', file_get_contents($repo.'/docker/nginx/default.conf'));
        $this->assertStringContainsString('error_page 413 /413.html;', file_get_contents($repo.'/docker/nginx/default.prod.conf'));
        $this->assertStringContainsString('one at a time', file_get_contents($repo.'/public/413.html'));
    }

    public function test_large_file_hashing_and_storage_stays_bounded(): void
    {
        Storage::fake('artifacts');
        $project = Project::factory()->create();
        $file = $this->makeSparseUpload('big.bin', "\x7fELF".str_repeat("\0", 60), self::LIMIT);

        $result = ArtifactUploadService::store($project, $project->owner, $file);

        $this->assertTrue($result['ok']);
        $this->assertSame(hash_file('sha256', $file->getRealPath()), $result['artifact']->sha256);
        // One necessary in-memory detection pass (~64 MiB) plus the
        // framework: absolute peak must stay well under two stacked
        // copies (~190 MiB) regardless of test execution order.
        $this->assertLessThan(170 * 1024 * 1024, memory_get_peak_usage(true));
    }

    public function test_human_bytes_uses_binary_units(): void
    {
        $this->assertSame('64.0 MiB', ArtifactUploadService::humanBytes(67108864));
        $this->assertSame('1 KiB', ArtifactUploadService::humanBytes(1024));
        $this->assertSame('512 bytes', ArtifactUploadService::humanBytes(512));
    }
}
