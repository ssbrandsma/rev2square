<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeBinaryJob;
use App\Models\BinaryAnalysis;
use App\Models\Project;
use App\Models\ProjectArtifact;
use App\Models\User;
use App\Services\Agent\BinaryTools;
use App\Services\Agent\ToolContext;
use App\Enums\GlobalRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Deployment-contract tests: runner isolation in both compose files,
 * coordinator env allowlist, Dockerfile pins, runner Python runtime,
 * local-image pull policy, queue routing, job timeouts, diagnostics,
 * admin page, and the new Agent evidence tools. Static where the
 * boundary is static (compose/docker), live where Laravel owns it
 * (routing/commands/tools).
 */
class AnalysisDeploymentTest extends TestCase
{
    use RefreshDatabase;

    private const RUNNERS = ['objdump-runner', 'ghidra-runner', 'jadx-runner', 'ilspy-runner', 'extract-runner'];

    private const COORDINATOR_ENV_KEYS = [
        'APP_NAME', 'APP_ENV', 'LOG_CHANNEL', 'LOG_LEVEL',
        'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
        'QUEUE_CONNECTION', 'CACHE_STORE', 'FILESYSTEM_DISK',
        'BINARY_SPOOL_DIR', 'BINARY_RESULTS_DIR',
    ];

    private function compose(string $file): string
    {
        return (string) file_get_contents(base_path($file));
    }

    private function serviceBlock(string $compose, string $service): string
    {
        $pattern = '/^  '.preg_quote($service, '/').':\n(.*?)(?=^  [a-z][a-z0-9-]*:\n|^volumes:\n|\z)/ms';
        $this->assertSame(1, preg_match($pattern, $compose, $m), "service block missing: {$service}");

        return $m[0];
    }

    #[DataProvider('composeFiles')]
    public function test_runners_are_isolated_in_compose(string $file): void
    {
        $compose = $this->compose($file);

        foreach (self::RUNNERS as $runner) {
            $block = $this->serviceBlock($compose, $runner);
            $this->assertMatchesRegularExpression('/network_mode:\s+[\"\']?none[\"\']?/', $block, "{$runner} has no network ({$file})");
            $this->assertMatchesRegularExpression('/read_only:\s*true/', $block, "{$runner} has a read-only root ({$file})");
            $this->assertMatchesRegularExpression('/user:\s+[\"\']?www-data[\"\']?/', $block, "{$runner} runs non-root ({$file})");
            $this->assertMatchesRegularExpression('/analysis-spool:\/spool:ro/', $block, "{$runner} mounts the spool read-only ({$file})");
            $this->assertMatchesRegularExpression('/no-new-privileges:true/', $block, "{$runner} sets no-new-privileges ({$file})");
            $this->assertMatchesRegularExpression('/cap_drop:\s*\n\s*-\s*ALL/', $block, "{$runner} drops all capabilities ({$file})");
            $this->assertDoesNotMatchRegularExpression('/env_file:/', $block, "{$runner} takes no env file ({$file})");
            $this->assertDoesNotMatchRegularExpression('/ports:/', $block, "{$runner} publishes no ports ({$file})");
            foreach (['APP_KEY', 'DB_PASSWORD', 'DB_ROOT_PASSWORD', 'MODEL_', 'META_', 'MAIL_', 'SECRET', 'TOKEN', 'SESSION_'] as $secret) {
                $this->assertStringNotContainsString($secret, $block, "{$runner} references no {$secret} ({$file})");
            }
        }
    }

    #[DataProvider('composeFiles')]
    public function test_coordinator_environment_is_an_allowlist(string $file): void
    {
        $block = $this->serviceBlock($this->compose($file), 'analysis-coordinator');
        $this->assertDoesNotMatchRegularExpression('/env_file:/', $block, "coordinator takes no env file ({$file})");

        preg_match_all('/^      ([A-Z][A-Z0-9_]*):/m', $block, $m);
        $keys = $m[1];
        sort($keys);
        $expected = self::COORDINATOR_ENV_KEYS;
        sort($expected);
        $this->assertSame($expected, $keys, "coordinator env allowlist ({$file})");

        foreach (['APP_KEY', 'MODEL_', 'META_', 'MAIL_', 'SECRET', 'TOKEN', 'SESSION_', 'DB_ROOT_PASSWORD'] as $secret) {
            $this->assertStringNotContainsString($secret, $block, "coordinator references no {$secret} ({$file})");
        }
    }

    #[DataProvider('composeFiles')]
    public function test_spool_volumes_are_shared_with_runners_only(string $file): void
    {
        $compose = $this->compose($file);
        $this->assertMatchesRegularExpression('/^volumes:\n(?:.*\n)*?  analysis-spool:\n/m', $compose, "analysis-spool declared ({$file})");
        $this->assertMatchesRegularExpression('/^volumes:\n(?:.*\n)*?  analysis-results:\n/m', $compose, "analysis-results declared ({$file})");

        // Every mount of the spool volumes must live inside the
        // coordinator or a runner service block.
        $services = strstr($compose, "\nvolumes:\n", true) ?: $compose;
        foreach (['analysis-spool', 'analysis-results'] as $volume) {
            preg_match_all('/^  ([a-z][a-z0-9-]*):\n(.*?)(?=^  [a-z][a-z0-9-]*:\n|\z)/ms', $services, $m, PREG_SET_ORDER);
            foreach ($m as $service) {
                if (str_contains($service[0], $volume.':')) {
                    $this->assertContains($service[1], array_merge(['analysis-coordinator'], self::RUNNERS), "{$volume} mounted outside the analysis boundary ({$file})");
                }
            }
        }
    }

    public static function composeFiles(): array
    {
        return [['compose.dev.yaml'], ['compose.virtualmin.yaml']];
    }

    public function test_tool_images_are_version_pinned(): void
    {
        $dir = base_path('docker/analysis');
        $ghidra = (string) file_get_contents($dir.'/Dockerfile.ghidra');
        $this->assertStringContainsString('12.2', $ghidra);
        $this->assertStringContainsString('2c2cd776db3c1f6a097ba418922378ac3649cadd5e844a6f5f088ae628a7be67', $ghidra);
        $this->assertStringContainsString('25.0.5', $ghidra);
        $this->assertStringContainsString('buildNatives', $ghidra);

        $jadx = (string) file_get_contents($dir.'/Dockerfile.jadx');
        $this->assertStringContainsString('1.5.6', $jadx);
        $this->assertStringContainsString('545ea2be9c242511bc145755cf4bda2485ade42966e096f8b4d3da2a230e8974', $jadx);
        $this->assertStringContainsString('17.0.21', $jadx);

        $ilspy = (string) file_get_contents($dir.'/Dockerfile.ilspy');
        $this->assertStringContainsString('11.2.0.10050', $ilspy);
        $this->assertStringContainsString('de4fe90a98845d75910b9769662465c84de1a934665c431279d1382b2bddc02b', $ilspy);

        $binutils = (string) file_get_contents($dir.'/Dockerfile.binutils');
        $this->assertStringContainsString('binutils-multiarch=2.40-2', $binutils);

        $extract = (string) file_get_contents($dir.'/Dockerfile.extract');
        $this->assertStringContainsString('squashfs-tools=1:4.5.1-1', $extract);
        $this->assertStringContainsString('64511f6ebc32d5257a535b33b21a5b6712c72aa91e28524f41e1ce92e803c909', $extract);
        $this->assertStringContainsString('d6d81b19c3ddd81e21d83a5aa19f9dfa1f6e61bf39d866cea4bd0581b243bd39', $extract);

        foreach (['Dockerfile.ghidra', 'Dockerfile.jadx', 'Dockerfile.ilspy', 'Dockerfile.binutils', 'Dockerfile.extract'] as $dockerfile) {
            $text = (string) file_get_contents($dir.'/'.$dockerfile);
            $this->assertStringContainsString('USER www-data', $text, "{$dockerfile} pins the non-root user");
            $this->assertDoesNotMatchRegularExpression('/COPY\s+(app|config|routes|resources|database)\//', $text, "{$dockerfile} copies no application code");
        }
        // Shared base image digest (Debian bookworm-slim manifest list).
        foreach (['Dockerfile.ghidra', 'Dockerfile.jadx', 'Dockerfile.binutils', 'Dockerfile.extract'] as $dockerfile) {
            $text = (string) file_get_contents($dir.'/'.$dockerfile);
            $this->assertStringContainsString('99c0b25c46d0214d5a2091092e7908907dcaecd6a962cde0ac700acee222b6f3', $text, "{$dockerfile} pins the base digest");
        }
    }

    public function test_runner_final_images_ship_full_python3_with_stdlib_smoke(): void
    {
        // Regression net for the ARM64 build failure: python3-minimal
        // omits stdlib modules (notably json) that runner.py imports.
        // The expected module set is derived from runner.py itself, so
        // adding an import there fails here until every Dockerfile's
        // smoke covers it.
        $runner = (string) file_get_contents(base_path('docker/analysis/runner.py'));
        preg_match_all('/^\s*(?:import\s+([a-z0-9_, ]+)|from\s+([a-z0-9_]+)\s+import\s+)/mi', $runner, $matches, PREG_SET_ORDER);
        $modules = [];
        foreach ($matches as $match) {
            if ($match[1] !== '') {
                foreach (explode(',', $match[1]) as $mod) {
                    $modules[] = trim($mod);
                }
            } else {
                $modules[] = $match[2];
            }
        }
        $modules = array_values(array_unique(array_filter($modules)));
        $this->assertContains('json', $modules, 'runner.py import extraction covers the incident module');
        $this->assertGreaterThan(5, count($modules), 'runner.py import extraction is non-trivial');

        $dir = base_path('docker/analysis');
        $tools = [
            'Dockerfile.binutils' => 'objdump',
            'Dockerfile.ghidra' => 'ghidra',
            'Dockerfile.jadx' => 'jadx',
            'Dockerfile.ilspy' => 'ilspy',
            'Dockerfile.extract' => 'extract',
        ];
        foreach ($tools as $dockerfile => $tool) {
            $text = (string) file_get_contents($dir.'/'.$dockerfile);
            // Final stage only: builder stages legitimately differ.
            $stripped = (string) preg_replace('/^\s*#.*$/m', '', $text);
            $stages = preg_split('/^FROM [^\n]*$/m', $stripped);
            $final = (string) end($stages);

            $this->assertStringNotContainsString('python3-minimal', $final, "{$dockerfile} final stage installs no python3-minimal");
            $flat = str_replace("\\\n", ' ', $final);
            $this->assertMatchesRegularExpression(
                '/apt-get install -y --no-install-recommends\b[^;&|]*\bpython3\b/',
                $flat,
                "{$dockerfile} final stage installs full python3"
            );

            $userPos = strpos($final, 'USER www-data');
            $this->assertNotFalse($userPos, "{$dockerfile} pins the non-root user");
            $this->assertSame(1, preg_match('/python3 -c "import ([^"]+)"/', $final, $smoke), "{$dockerfile} smokes stdlib imports");
            $smokePos = strpos($final, $smoke[0]);
            $this->assertGreaterThan($userPos, $smokePos, "{$dockerfile} smoke runs as the non-root user");
            foreach ($modules as $module) {
                $this->assertMatchesRegularExpression('/\b'.preg_quote($module, '/').'\b/', $smoke[1], "{$dockerfile} smoke covers import {$module}");
            }
            $this->assertStringContainsString("--dry-run --tool {$tool}", $final, "{$dockerfile} keeps the dry-run self-check");
        }
    }

    #[DataProvider('composeFiles')]
    public function test_locally_built_images_are_never_pulled(string $file): void
    {
        // After a failed build, `up` must fail fast locally — never
        // attempt a misleading Docker Hub pull of a project-local tag.
        $compose = $this->compose($file);
        $services = strstr($compose, "\nvolumes:\n", true) ?: $compose;
        preg_match_all('/^  ([a-z][a-z0-9-]*):$/m', $services, $names);
        $guarded = 0;
        foreach ($names[1] as $service) {
            $block = $this->serviceBlock($compose, (string) $service);
            if (! str_contains($block, 'image: rev2square/')) {
                continue;
            }
            $guarded++;
            $this->assertMatchesRegularExpression('/pull_policy:\s*[\'"]?never[\'"]?/', $block, "{$service} is built locally, never pulled ({$file})");
            // No Compose build.args on runners: an unset/empty
            // override would silently erase a pinned Dockerfile
            // default (the Dockerfiles preflight for empties, but no
            // override should exist to trip that in the first place).
            $this->assertDoesNotMatchRegularExpression('/^\s+args:/m', $block, "{$service} sets no build args ({$file})");
        }
        $this->assertGreaterThanOrEqual(5, $guarded, "runner images guarded ({$file})");

        // Registry images must stay retrievable: base-image pulls
        // during builds and third-party service pulls keep working.
        $db = $this->serviceBlock($compose, 'db');
        $this->assertStringNotContainsString('pull_policy', $db, "db keeps default pull behavior ({$file})");
    }

    public function test_runner_stages_redeclare_download_args_with_preflight(): void
    {
        // Regression net for the extract build failure: global ARGs
        // (declared before the first FROM) are visible in FROM lines
        // only. Any stage whose steps reference one must re-declare
        // it with a bare `ARG NAME`, or the variable silently expands
        // empty and curl fails with `URL using bad/illegal format`.
        $dir = base_path('docker/analysis');
        // Expected `: "${VAR:?missing build input ...}"` guards and
        // `must be an https URL` scheme checks per Dockerfile.
        $preflights = [
            'Dockerfile.binutils' => [0, 0],
            'Dockerfile.ghidra' => [5, 1],
            'Dockerfile.jadx' => [4, 1],
            'Dockerfile.ilspy' => [1, 0],
            'Dockerfile.extract' => [4, 2],
        ];
        foreach ($preflights as $dockerfile => [$guards, $schemes]) {
            $text = (string) file_get_contents($dir.'/'.$dockerfile);
            $stripped = (string) preg_replace('/^\s*#.*$/m', '', $text);
            $parts = preg_split('/^FROM [^\n]*$/m', $stripped);
            $global = (string) array_shift($parts);
            preg_match_all('/^ARG\s+([A-Z][A-Z0-9_]*)=/m', $global, $defs);
            $globals = $defs[1];

            foreach (array_values($parts) as $i => $stage) {
                $steps = (string) preg_replace('/^ARG\s+[^\n]*$/m', '', $stage);
                preg_match_all('/\$\{([A-Z][A-Z0-9_]+)[}:]/', $steps, $braced);
                preg_match_all('/\$([A-Z][A-Z0-9_]+)/', $steps, $plain);
                $refs = array_unique(array_merge($braced[1], $plain[1]));
                foreach ($refs as $ref) {
                    if (! in_array($ref, $globals, true)) {
                        continue;
                    }
                    $this->assertMatchesRegularExpression(
                        '/^ARG\s+'.preg_quote($ref, '/').'\s*$/m',
                        $stage,
                        "{$dockerfile} stage {$i} re-declares ARG {$ref}"
                    );
                }
            }

            $this->assertSame($guards, substr_count($text, 'missing build input'), "{$dockerfile} preflights its build inputs");
            $this->assertSame($schemes, substr_count($text, 'must be an https URL'), "{$dockerfile} pins https download schemes");
        }
    }

    public function test_jadx_uses_verified_flat_archive_layout(): void
    {
        // The published jadx-1.5.6.zip has bin/ + lib/ at the archive
        // root (verified by download); there is no jadx-1.5.6/ top
        // directory, so the install copy must use the dist root.
        $jadx = (string) file_get_contents(base_path('docker/analysis/Dockerfile.jadx'));
        $this->assertStringContainsString('cp -r /tmp/jadxdist/. /opt/jadx/', $jadx);
        $this->assertStringNotContainsString('jadxdist/jadx-', $jadx);
    }

    public function test_setup_docs_gate_startup_on_successful_build(): void
    {
        $setup = (string) file_get_contents(base_path('docs/binary-analysis-setup.md'));
        $this->assertStringContainsString(
            'build objdump-runner ghidra-runner jadx-runner ilspy-runner extract-runner &&',
            $setup,
            'runner provisioning chains up behind a successful build'
        );

        $deploy = (string) file_get_contents(base_path('docs/DEPLOYMENT_VIRTUALMIN_ARM64.md'));
        $this->assertSame(2, substr_count($deploy, 'build &&'), 'upgrade + rollback gate up on build');
    }

    public function test_analysis_jobs_route_to_the_coordinator_queue(): void
    {
        Queue::fake();
        config(['artifacts.binary_spool_wall_seconds' => 480]);

        $owner = User::factory()->create();
        $project = Project::factory()->draft()->create(['owner_id' => $owner->id]);
        $artifact = ProjectArtifact::create([
            'project_id' => $project->id, 'uploader_id' => $owner->id,
            'original_name' => 'fw.bin', 'byte_size' => 4, 'sha256' => hash('sha256', 'x'),
            'storage_key' => str()->random(40), 'format' => 'raw', 'state' => 'ready',
        ]);
        $analysis = BinaryAnalysis::create([
            'project_artifact_id' => $artifact->id, 'requested_by' => $owner->id,
            'adapter' => 'ghidra', 'target' => 'arm32', 'config' => [],
            'status' => 'queued', 'dedupe_key' => 'route-test-'.uniqid(),
        ]);

        AnalyzeBinaryJob::dispatch($analysis->id);

        Queue::assertPushedOn('analysis', AnalyzeBinaryJob::class);
        Queue::assertPushed(AnalyzeBinaryJob::class, fn ($job) => $job->connection === 'analysis-database');

        // Sandboxed runs get wall + grace; anything else keeps the
        // short budget; unknown rows never crash dispatch.
        $this->assertSame(540, AnalyzeBinaryJob::timeoutFor($analysis->id));
        $this->assertSame((int) config('artifacts.binary_timeout_seconds', 120), AnalyzeBinaryJob::timeoutFor(999999));
    }

    public function test_diagnostics_command_reports_without_secrets(): void
    {
        config([
            'artifacts.binary_spool_dir' => sys_get_temp_dir().'/rev2-no-spool-'.uniqid(),
            'artifacts.binary_results_dir' => sys_get_temp_dir().'/rev2-no-results-'.uniqid(),
        ]);
        $this->artisan('analysis:diagnostics')
            ->expectsOutputToContain('objdump')
            ->expectsOutputToContain('NOT READY')
            ->assertSuccessful();
    }

    public function test_admin_analysis_page_is_admin_only(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['global_role' => GlobalRole::Admin])->save();
        $user = User::factory()->create();

        $this->actingAs($admin)->get(route('admin.analysis.index'))
            ->assertOk()
            ->assertSee('Execution readiness')
            ->assertSee('Ghidra (isolated headless)');
        $this->actingAs($user)->get(route('admin.analysis.index'))->assertForbidden();
    }

    public function test_sandboxed_dispatch_runs_end_to_end_through_spool(): void
    {
        // Full stack for a sandboxed adapter: HTTP queue → snapshot
        // gate → job → spool → evidence validation → ready. The only
        // fake is the runner process; the parsed bytes are real
        // objdump 2.44 output (byte-identical fixture).
        Storage::fake('artifacts');
        Queue::fake();
        $spool = new \Tests\Support\SpoolFake;
        app()->instance(\App\Services\Binary\Sandbox\SpoolClient::class, $spool);
        $spool->probes['objdump'] = \Tests\Support\SpoolFake::answer('objdump', '2.40', ['architectures' => ['i386']]);
        $spool->runs['objdump'] = [
            'evidence' => ['schema' => 1, 'tool' => 'objdump', 'exit_code' => 0, 'output_file' => 'tool.out', 'output_truncated' => false],
            'tool_output' => file_get_contents(base_path('tests/Fixtures/Binary/objdump-i386-raw.txt')),
        ];

        $owner = User::factory()->create();
        $project = Project::factory()->draft()->create(['owner_id' => $owner->id]);
        $bytes = \Tests\Support\BinaryFixtures::codeX86();
        $artifact = ProjectArtifact::create([
            'project_id' => $project->id, 'uploader_id' => $owner->id,
            'original_name' => 'code.bin', 'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes), 'storage_key' => str()->random(40),
            'format' => ProjectArtifact::FORMAT_RAW, 'state' => ProjectArtifact::STATE_QUEUED,
        ]);
        Storage::disk('artifacts')->put($artifact->rawPath(), $bytes);
        \App\Services\Artifacts\ArtifactExtractor::extract($artifact->fresh());
        $artifact = $artifact->fresh();

        // Simulate a refreshed worker snapshot (dispatch gate).
        \App\Models\WorkerCapability::updateOrCreate(['adapter' => 'objdump'], [
            'available' => true, 'version' => '2.40', 'targets' => ['x86', 'x86-64'],
            'formats' => ['raw'], 'details' => ['reason' => 'test'],
            'captured_at' => now(), 'worker' => 'test',
        ]);

        $response = $this->actingAs($owner)->post(
            route('projects.artifacts.analyses.store', [$project, $artifact]),
            ['target' => 'x86', 'base_address' => '0x8000']
        );
        $response->assertRedirect();
        $analysis = BinaryAnalysis::where('project_artifact_id', $artifact->id)->latest('id')->firstOrFail();
        (new AnalyzeBinaryJob($analysis->id))->handle();

        $analysis = $analysis->fresh();
        $this->assertSame(BinaryAnalysis::STATUS_READY, $analysis->status);
        $this->assertSame('2.40', $analysis->adapter_version);
        $evidence = $analysis->readEvidence();
        $texts = [];
        foreach ($evidence['disassembly']['windows'][0]['lines'] as $line) {
            $texts[sprintf('%x', $line['address'])] = $line['text'];
        }
        $this->assertSame('call 0x800a', $texts['8003']);
        // The job ran on the coordinator queue/connection.
        Queue::assertPushedOn('analysis', AnalyzeBinaryJob::class);
    }

    public function test_agent_reads_decompilation_and_reconstruction(): void
    {
        Storage::fake('artifacts');
        $owner = User::factory()->create();
        $project = Project::factory()->draft()->create(['owner_id' => $owner->id]);
        $artifact = ProjectArtifact::create([
            'project_id' => $project->id, 'uploader_id' => $owner->id,
            'original_name' => 'fw.bin', 'byte_size' => 4, 'sha256' => hash('sha256', 'x'),
            'storage_key' => str()->random(40), 'format' => 'raw', 'state' => 'ready',
        ]);
        $analysis = BinaryAnalysis::create([
            'project_artifact_id' => $artifact->id, 'requested_by' => $owner->id,
            'adapter' => 'ghidra', 'adapter_version' => '12.2', 'target' => 'arm32',
            'config' => ['target' => 'arm32'], 'status' => BinaryAnalysis::STATUS_READY,
            'dedupe_key' => 'agent-decomp-'.uniqid(), 'evidence_key' => 'ev1',
        ]);
        Storage::disk('artifacts')->put($analysis->evidencePath(), json_encode([
            'schema' => 'binary-analysis/1',
            'adapter' => 'ghidra',
            'functions' => [['id' => 'f8000', 'address' => 0x8000, 'file_offset' => null, 'name' => 'entry', 'source' => 'ghidra', 'size' => 8, 'size_tentative' => true]],
            'decompilation' => ['functions' => [['name' => 'entry', 'address' => 0x8000, 'c' => 'void entry(void){}']], 'truncated' => false],
            'disassembly' => ['windows' => [], 'total_instructions' => 0, 'total_data_bytes' => 0, 'truncated' => false],
            'xrefs' => [],
        ]));

        $context = new ToolContext($owner, $project->fresh(), null, null, [$artifact->id], true);

        $decomp = BinaryTools::execute('read_decompilation', ['artifact_id' => $artifact->id, 'function' => 'f8000'], $context);
        $this->assertTrue($decomp->ok);
        $this->assertSame('void entry(void){}', $decomp->data['c']);
        $this->assertArrayHasKey('safety', $decomp->data);

        $missing = BinaryTools::execute('read_decompilation', ['artifact_id' => $artifact->id, 'function' => 'f9999'], $context);
        $this->assertFalse($missing->ok);

        $recon = BinaryTools::execute('list_reconstruction_files', ['artifact_id' => $artifact->id], $context);
        $this->assertFalse($recon->ok);
        $this->assertSame('no_reconstruction', $recon->data['code']);
    }
}
