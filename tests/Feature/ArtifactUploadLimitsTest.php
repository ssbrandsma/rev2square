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
        // Production: shared conf.d path scanned by BOTH php-fpm (HTTP)
        // and php CLI (workers) in the official php:8.4-fpm image. Any
        // SAPI-specific destination (cli/conf.d, fpm/conf.d) would leave
        // HTTP uploads on the 2M/8M defaults.
        $dockerfile = file_get_contents($repo.'/docker/php/Dockerfile.prod');
        $this->assertStringContainsString(
            'COPY docker/php/conf.d-uploads.ini /usr/local/etc/php/conf.d/uploads.ini',
            $dockerfile
        );
        $this->assertStringNotContainsString('cli/conf.d', $dockerfile);
        // Both production variants build the FPM app image from that
        // Dockerfile and must not mask conf.d with a volume.
        foreach (['compose.prod.yaml', 'compose.virtualmin.yaml'] as $composeFile) {
            $prod = file_get_contents($repo.'/'.$composeFile);
            $this->assertStringContainsString('dockerfile: docker/php/Dockerfile.prod', $prod, $composeFile);
            $this->assertStringNotContainsString('/usr/local/etc/php/conf.d', $prod, $composeFile);
        }
    }

    public function test_php_fpm_inherits_upload_limits_no_pool_overrides(): void
    {
        $repo = base_path();
        // INI precedence: conf.d/uploads.ini overrides php.ini-production
        // (2M/8M). An FPM pool override directive would supersede conf.d,
        // so the repo must ship none; stock www.conf sets none. Match
        // directive lines only, so documentation mentions do not trip this.
        foreach (glob($repo.'/docker/php/*') ?: [] as $file) {
            $this->assertSame(
                0,
                preg_match('/^\s*php_(admin_)?value\b/mi', file_get_contents($file)),
                basename($file).' must not set FPM pool overrides'
            );
        }
        $this->assertSame([], glob($repo.'/docker/php/*.conf') ?: []);
        $this->assertSame([], glob($repo.'/docker/php/pool.d/*') ?: []);
        // Alphabetical conf.d load (opcache-prod.ini before uploads.ini)
        // must not overlap on upload keys.
        $opcache = file_get_contents($repo.'/docker/php/conf.d-opcache-prod.ini');
        foreach (['upload_max_filesize', 'post_max_size', 'max_input_time', 'memory_limit'] as $key) {
            $this->assertStringNotContainsString($key, $opcache);
        }
        // Sanity: the whole request body cap must cover a max-size file.
        $this->assertGreaterThanOrEqual(64 * 1024 * 1024, 80 * 1024 * 1024);
        $ini = file_get_contents($repo.'/docker/php/conf.d-uploads.ini');
        $this->assertStringContainsString('upload_max_filesize = 64M', $ini);
        $this->assertStringContainsString('post_max_size = 80M', $ini);
    }

    public function test_prod_image_pins_readable_ini_modes_despite_umask_077_checkout(): void
    {
        $repo = base_path();

        // Model the hostile build host: sources checked out under umask 077
        // arrive root-only (0600), unreadable by the www-data runtime user.
        // A COPY without --chmod bakes those exact bits into the image, and
        // static COPY-path assertions alone did not catch that defect.
        $previous = umask(077);
        try {
            $tmp = sys_get_temp_dir().'/umask077-'.uniqid().'.ini';
            file_put_contents($tmp, file_get_contents($repo.'/docker/php/conf.d-uploads.ini'));
            clearstatcache(true, $tmp);
            $this->assertSame(0, fileperms($tmp) & 0o077, 'fixture must model a umask-077 checkout');
        } finally {
            umask($previous);
            if (isset($tmp)) {
                @unlink($tmp);
            }
        }

        // Every app-owned conf.d ini COPY must therefore pin a
        // deterministic, runtime-readable, runtime-nonwritable mode.
        $dockerfile = file_get_contents($repo.'/docker/php/Dockerfile.prod');
        $lines = explode("\n", $dockerfile);
        $iniCopies = array_values(array_filter(
            $lines,
            fn ($line) => str_contains($line, 'docker/php/conf.d-')
                && str_contains($line, '/usr/local/etc/php/conf.d/')
        ));
        $this->assertCount(2, $iniCopies);
        foreach ($iniCopies as $copy) {
            $this->assertMatchesRegularExpression(
                '/^COPY\s+--chmod=0?[0-7]{3}\s/',
                $copy,
                $copy.' must pin an explicit mode'
            );
            preg_match('/^COPY\s+--chmod=0?([0-7]{3})\s/', $copy, $m);
            $mode = octdec($m[1]);
            $this->assertSame(0, $mode & 0o022, $copy.' must stay non-writable by the runtime user');
            $this->assertNotSame(0, $mode & 0o004, $copy.' must stay world-readable');
            $this->assertNotSame(0, $mode & 0o400, $copy.' must stay owner-readable');
        }

        // The final-image gate must run AFTER USER www-data, as the actual
        // runtime user: readable file, scanned ini, effective 64M/80M under
        // both SAPIs, with php-fpm inheriting the intended limits.
        $userLine = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^USER\s+www-data\b/', $line)) {
                $userLine = $i;
            }
        }
        $this->assertNotNull($userLine);
        $tail = implode("\n", array_slice($lines, $userLine));
        $this->assertStringContainsString('test -r /usr/local/etc/php/conf.d/uploads.ini', $tail);
        $this->assertStringContainsString('php_ini_scanned_files', $tail);
        $this->assertStringContainsString('ini_get("upload_max_filesize")', $tail);
        $this->assertStringContainsString('ini_get("post_max_size")', $tail);
        $this->assertStringContainsString('php-fpm -i', $tail);
    }

    public function test_production_memory_and_media_caps_preserved(): void
    {
        $repo = base_path();
        // memory_limit stays 256M: headroom for one detection pass, not
        // raised merely to match upload size.
        $this->assertStringContainsString(
            'memory_limit = 256M', file_get_contents($repo.'/docker/php/conf.d-uploads.ini')
        );
        // Public presentation-media caps are a separate workflow and stay.
        $this->assertSame(12 * 1024 * 1024, \App\Services\Media\MediaUploadService::MAX_IMAGE_BYTES);
        $this->assertSame(25 * 1024 * 1024, \App\Services\Media\MediaUploadService::MAX_FILE_BYTES);
        // Research-artifact app cap stays 64 MiB with a bounded batch.
        $this->assertSame(67108864, \App\Services\Admin\AppSettings::allowlist()['artifacts.max_upload_bytes']['default']);
        $this->assertStringContainsString(
            "'files' => 'required|array|min:1|max:5'",
            file_get_contents($repo.'/app/Http/Controllers/ArtifactController.php')
        );
    }

    public function test_infrastructure_configs_declare_matching_limits(): void
    {
        $repo = base_path();
        $this->assertStringContainsString('upload_max_filesize = 64M', file_get_contents($repo.'/docker/php/conf.d-uploads.ini'));
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
