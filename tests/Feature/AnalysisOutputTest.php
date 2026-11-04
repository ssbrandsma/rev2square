<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeBinaryJob;
use App\Models\BinaryAnalysis;
use App\Models\ProjectArtifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BinaryFixtures;
use Tests\Support\SeedsProjects;
use Tests\TestCase;

/**
 * Retained tool-output end-to-end: archive download authorization,
 * source browser (archive + legacy evidence fallback), regeneration,
 * retention cleanup, APK native-library listing, and explicit .so
 * child analysis through the member flow. Binaries are synthetic.
 */
class AnalysisOutputTest extends TestCase
{
    use RefreshDatabase;
    use SeedsProjects;

    private function outputZipBytes(array $members): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rev2-featzip-');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE | \ZipArchive::CREATE);
        foreach ($members as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        unlink($tmp);

        return $bytes;
    }

    private function readyAnalysis(ProjectArtifact $artifact, User $owner, string $adapter, ?string $target, array $evidence, ?string $zipBytes = null, ?array $outputMeta = null): BinaryAnalysis
    {
        $analysis = BinaryAnalysis::create([
            'project_artifact_id' => $artifact->id,
            'requested_by' => $owner->id,
            'adapter' => $adapter,
            'adapter_version' => 'test',
            'target' => $target,
            'config' => [],
            'status' => BinaryAnalysis::STATUS_READY,
            'dedupe_key' => 'test-'.str()->random(32),
        ]);
        $key = str()->random(40);
        Storage::disk('artifacts')->put($artifact->project_id.'/'.$key.'.analysis.json', json_encode($evidence));
        $analysis->forceFill(['evidence_key' => $key])->save();
        if ($zipBytes !== null) {
            Storage::disk('artifacts')->put($analysis->outputPath(), $zipBytes);
            $analysis->forceFill(['output_meta' => $outputMeta ?? [
                'sha256' => hash('sha256', $zipBytes),
                'bytes' => strlen($zipBytes),
                'files' => 2,
                'files_seen' => 2,
                'truncated' => false,
                'reason' => null,
            ]])->save();
        }

        return $analysis->fresh();
    }

    private function reconEvidence(array $files, array $inline): array
    {
        return [
            'schema' => 'binary-analysis/1',
            'warnings' => [],
            'segments' => [],
            'functions' => [],
            'xrefs' => [],
            'calls' => [],
            'disassembly' => ['windows' => []],
            'reconstruction' => ['files' => $files, 'inline' => $inline, 'classes' => [], 'truncated' => false],
        ];
    }

    public function test_download_serves_retained_archive_to_project_viewer(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::apk(), 'app.apk');
        $zip = $this->outputZipBytes(['sources/com/acme/Foo.java' => 'class Foo{}', 'resources/a.xml' => '<a/>']);
        $analysis = $this->readyAnalysis($artifact, $owner, 'jadx', 'dalvik', $this->reconEvidence([], []), $zip);

        $response = $this->actingAs($owner)->get(route('projects.artifacts.analyses.download', [$project, $artifact, $analysis]));

        $response->assertOk();
        $response->assertDownload('analysis-'.$analysis->id.'-reconstructed-sources.zip');
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_download_rejects_guest_and_cross_project_users(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $outsider = User::factory()->create();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::apk(), 'app.apk');
        $zip = $this->outputZipBytes(['a.java' => 'class A{}']);
        $analysis = $this->readyAnalysis($artifact, $owner, 'jadx', 'dalvik', $this->reconEvidence([], []), $zip);

        // Guest assertions first: actingAs persists for the rest of
        // the test, so any later "guest" call would run authenticated.
        $this->get(route('projects.artifacts.analyses.download', [$project, $artifact, $analysis]))->assertRedirect();
        $this->get(route('projects.artifacts.analyses.source', [$project, $artifact, $analysis]))->assertRedirect();
        $this->actingAs($outsider)->get(route('projects.artifacts.analyses.download', [$project, $artifact, $analysis]))->assertForbidden();
        // Same boundary for the browser and regeneration.
        $this->actingAs($outsider)->get(route('projects.artifacts.analyses.source', [$project, $artifact, $analysis]))->assertForbidden();
        $this->actingAs($outsider)->post(route('projects.artifacts.analyses.regenerate', [$project, $artifact, $analysis]))->assertForbidden();
    }

    public function test_download_without_archive_points_to_regeneration(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::apk(), 'app.apk');
        $analysis = $this->readyAnalysis($artifact, $owner, 'jadx', 'dalvik', $this->reconEvidence([], []));

        $response = $this->actingAs($owner)->get(route('projects.artifacts.analyses.download', [$project, $artifact, $analysis]));

        $response->assertRedirect(route('projects.artifacts.analyses.show', [$project, $artifact, $analysis]));
        $response->assertSessionHas('error', 'No downloadable output is retained for this run. Queue a regeneration to produce one.');
    }

    public function test_source_browser_lists_filters_and_views_escaped_file(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::apk(), 'app.apk');
        $java = "class Evil {\n    String x = \"<script>alert(1)</script>\";\n}\n";
        $zip = $this->outputZipBytes(['sources/com/acme/Evil.java' => $java, 'resources/icon.png' => "\x89PNG-binary"]);
        $analysis = $this->readyAnalysis($artifact, $owner, 'jadx', 'dalvik', $this->reconEvidence([], []), $zip);

        $page = $this->actingAs($owner)->get(route('projects.artifacts.analyses.source', [$project, $artifact, $analysis]));
        $page->assertOk();
        $page->assertSee('sources/com/acme/Evil.java', false);
        $page->assertSee('resources/icon.png', false);

        // Kind filter keeps sources, drops resources.
        $filtered = $this->actingAs($owner)->get(route('projects.artifacts.analyses.source', [$project, $artifact, $analysis, 'kind' => 'source']));
        $filtered->assertOk();
        $filtered->assertSee('sources/com/acme/Evil.java', false);
        $filtered->assertDontSee('resources/icon.png', false);

        // Name search is a substring match.
        $searched = $this->actingAs($owner)->get(route('projects.artifacts.analyses.source', [$project, $artifact, $analysis, 'q' => 'Evil']));
        $searched->assertOk();
        $searched->assertSee('sources/com/acme/Evil.java', false);
        $searched->assertDontSee('resources/icon.png', false);

        // File view: escaped content, line numbers, stable anchors.
        $viewed = $this->actingAs($owner)->get(route('projects.artifacts.analyses.source', [$project, $artifact, $analysis, 'path' => 'sources/com/acme/Evil.java']));
        $viewed->assertOk();
        $viewed->assertDontSee('<script>alert(1)</script>', false);
        $viewed->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $viewed->assertSee('id="L2"', false);

        // Binary entries are listed but never rendered inline.
        $binary = $this->actingAs($owner)->get(route('projects.artifacts.analyses.source', [$project, $artifact, $analysis, 'path' => 'resources/icon.png']));
        $binary->assertOk();
        $binary->assertSee('Binary file', false);
    }

    public function test_source_browser_rejects_traversal_and_unknown_paths(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::apk(), 'app.apk');
        $zip = $this->outputZipBytes(['sources/A.java' => 'class A{}']);
        $analysis = $this->readyAnalysis($artifact, $owner, 'jadx', 'dalvik', $this->reconEvidence([], []), $zip);

        foreach (['../evil', '..\\evil', '/etc/passwd', 'sources/../evil.java', 'sources/Missing.java'] as $bad) {
            $response = $this->actingAs($owner)->get(route('projects.artifacts.analyses.source', [$project, $artifact, $analysis, 'path' => $bad]));
            $response->assertRedirect(route('projects.artifacts.analyses.source', [$project, $artifact, $analysis]));
            $response->assertSessionHas('error');
        }
    }

    public function test_source_browser_falls_back_to_evidence_for_legacy_runs(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::apk(), 'app.apk');
        $files = [
            ['path' => 'sources/com/acme/Inline.java', 'size' => 20, 'sha256' => hash('sha256', 'a')],
            ['path' => 'resources/big.png', 'size' => 90, 'sha256' => hash('sha256', 'b')],
        ];
        $analysis = $this->readyAnalysis($artifact, $owner, 'jadx', 'dalvik', $this->reconEvidence($files, ['sources/com/acme/Inline.java' => 'class Inline{}']));

        $page = $this->actingAs($owner)->get(route('projects.artifacts.analyses.source', [$project, $artifact, $analysis]));
        $page->assertOk();
        $page->assertSee('predates retained output', false);
        $page->assertSee('sources/com/acme/Inline.java', false);
        $page->assertSee('resources/big.png', false);

        $viewed = $this->actingAs($owner)->get(route('projects.artifacts.analyses.source', [$project, $artifact, $analysis, 'path' => 'sources/com/acme/Inline.java']));
        $viewed->assertOk();
        $viewed->assertSee('class Inline{}', false);

        $missing = $this->actingAs($owner)->get(route('projects.artifacts.analyses.source', [$project, $artifact, $analysis, 'path' => 'resources/big.png']));
        $missing->assertOk();
        $missing->assertSee('Regenerate the run', false);
    }

    public function test_regenerate_requeues_outputless_run_and_replaces_results(): void
    {
        Storage::fake('artifacts');
        Queue::fake();
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::apk(), 'app.apk');
        $analysis = $this->readyAnalysis($artifact, $owner, 'jadx', 'dalvik', $this->reconEvidence([], []));

        $response = $this->actingAs($owner)->post(route('projects.artifacts.analyses.regenerate', [$project, $artifact, $analysis]));

        $response->assertRedirect(route('projects.artifacts.analyses.show', [$project, $artifact, $analysis]));
        $response->assertSessionHas('status');
        Queue::assertPushed(AnalyzeBinaryJob::class);
        $fresh = $analysis->fresh();
        $this->assertSame(BinaryAnalysis::STATUS_QUEUED, $fresh->status);
        $this->assertNull($fresh->evidence_key);
        $this->assertNull($fresh->output_meta);
    }

    public function test_regenerate_refuses_runs_with_output_or_without_results(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::apk(), 'app.apk');
        $zip = $this->outputZipBytes(['a.java' => 'class A{}']);
        $withOutput = $this->readyAnalysis($artifact, $owner, 'jadx', 'dalvik', $this->reconEvidence([], []), $zip);

        $response = $this->actingAs($owner)->post(route('projects.artifacts.analyses.regenerate', [$project, $artifact, $withOutput]));
        $response->assertSessionHas('error', 'This run already retains downloadable output — nothing to regenerate.');
        $this->assertSame(BinaryAnalysis::STATUS_READY, $withOutput->fresh()->status);

        $failed = BinaryAnalysis::create([
            'project_artifact_id' => $artifact->id,
            'requested_by' => $owner->id,
            'adapter' => 'jadx',
            'adapter_version' => 'test',
            'target' => 'dalvik',
            'config' => [],
            'status' => BinaryAnalysis::STATUS_FAILED,
            'error_code' => 'environmental',
            'dedupe_key' => 'test-'.str()->random(32),
        ]);
        $response = $this->actingAs($owner)->post(route('projects.artifacts.analyses.regenerate', [$project, $artifact, $failed]));
        $response->assertSessionHas('error');
        $this->assertSame(BinaryAnalysis::STATUS_FAILED, $failed->fresh()->status);
    }

    public function test_artifact_deletion_removes_output_archive(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::apk(), 'app.apk');
        $zip = $this->outputZipBytes(['a.java' => 'class A{}']);
        $analysis = $this->readyAnalysis($artifact, $owner, 'jadx', 'dalvik', $this->reconEvidence([], []), $zip);
        $this->assertTrue(Storage::disk('artifacts')->exists($analysis->outputPath()));

        $this->actingAs($owner)->delete(route('projects.artifacts.destroy', [$project, $artifact]))->assertRedirect();

        $this->assertFalse(Storage::disk('artifacts')->exists($analysis->outputPath()));
    }

    public function test_arm_so_member_end_to_end_through_member_flow(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $so = BinaryFixtures::elf32(40, BinaryFixtures::codeArm32(), 0x10000);
        $apk = BinaryFixtures::apk(['so' => true, 'so_bytes' => $so, 'so_name' => 'lib/armeabi-v7a/libsynth.so']);
        $artifact = $this->storeBytes($project, $owner, $apk, 'app.apk');
        $this->assertSame(ProjectArtifact::FORMAT_APK, $artifact->format);

        Queue::fake();
        $response = $this->actingAs($owner)->post(route('projects.artifacts.analyses.store', [$project, $artifact]), [
            'member' => 'lib/armeabi-v7a/libsynth.so', 'target' => 'arm32',
        ]);
        $response->assertRedirect();
        $analysis = BinaryAnalysis::where('project_artifact_id', $artifact->id)->latest('id')->firstOrFail();
        $this->assertSame('php-native', $analysis->adapter);
        $this->assertSame('lib/armeabi-v7a/libsynth.so', $analysis->config['member']);

        (new AnalyzeBinaryJob($analysis->id))->handle();
        $analysis = $analysis->fresh();
        $this->assertTrue($analysis->isUsable(), 'run failed: '.$analysis->error_code.' '.$analysis->error_message);
        $evidence = $analysis->readEvidence();
        $this->assertSame('lib/armeabi-v7a/libsynth.so', $evidence['artifact']['member']['name']);
        $this->assertSame('elf', $evidence['artifact']['member']['format']);
        $this->assertSame(hash('sha256', $so), $evidence['artifact']['member']['sha256']);
    }

    public function test_apk_cpu_target_without_member_explains_member_flow(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::apk(), 'app.apk');

        $response = $this->actingAs($owner)->post(route('projects.artifacts.analyses.store', [$project, $artifact]), [
            'target' => 'arm32',
        ]);

        $response->assertSessionHas('error', 'Processor target “arm32” applies to a native library inside this APK, not the package itself. Open a JADX run and choose “Analyze” on one library.');
        $this->assertSame(0, BinaryAnalysis::where('project_artifact_id', $artifact->id)->count());
    }

    public function test_jadx_run_page_lists_native_libraries_and_empty_panel_notes(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::apk(['so' => true]), 'app.apk');
        $snapshot = $artifact->readSnapshot();
        $this->assertNotEmpty($snapshot['data']['native_libs'] ?? []);
        $files = [['path' => 'sources/com/synth/App.java', 'size' => 40, 'sha256' => hash('sha256', 'a')]];
        $analysis = $this->readyAnalysis($artifact, $owner, 'jadx', 'dalvik', $this->reconEvidence($files, []));

        $page = $this->actingAs($owner)->get(route('projects.artifacts.analyses.show', [$project, $artifact, $analysis]));

        $page->assertOk();
        $page->assertSee('Native libraries', false);
        $page->assertSee('arm64-v8a', false);
        $page->assertSee('lib/arm64-v8a/libsynth.so', false);
        $page->assertSee('Analyze this library', false);
        $page->assertSee('nothing here was analyzed automatically', false);
        $page->assertSee('No function table: JADX reconstructs Java sources', false);
        $page->assertSee('No call graph: JADX', false);
        $page->assertSee('Regenerate with downloadable output', false);
    }
}
