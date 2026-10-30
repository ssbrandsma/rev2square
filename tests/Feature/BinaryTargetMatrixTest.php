<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeBinaryJob;
use App\Models\BinaryAnalysis;
use App\Models\Project;
use App\Models\ProjectArtifact;
use App\Models\User;
use App\Services\Artifacts\ArtifactExtractor;
use App\Services\Binary\Adapters\ObjdumpAdapter;
use App\Services\Binary\Sandbox\SpoolClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BinaryFixtures;
use Tests\Support\SpoolFake;
use Tests\TestCase;

/**
 * Target/backend completion matrix: every advertised target routes to
 * a verified backend or fails with a precise pre-queue explanation.
 * Real tools only (bundled PHP decoders, isolated objdump runner) — no mocked
 * adapters stand in for processor integration.
 */
class BinaryTargetMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function seedProject(): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->draft()->create(['owner_id' => $owner->id]);

        return [$project->fresh(), $owner];
    }

    private function storeBytes(Project $project, User $uploader, string $bytes, string $name, ?string $format = null): ProjectArtifact
    {
        $artifact = ProjectArtifact::create([
            'project_id' => $project->id,
            'uploader_id' => $uploader->id,
            'original_name' => $name,
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'storage_key' => str()->random(40),
            // Raw tests pass FORMAT_RAW explicitly: it mirrors the
            // upload form's "treat as raw" opt-in for unrecognized
            // bytes (unrecognized bytes without the opt-in fail).
            'format' => $format ?? ArtifactExtractor::detect($bytes, $name),
            'state' => ProjectArtifact::STATE_QUEUED,
        ]);
        Storage::disk('artifacts')->put($artifact->rawPath(), $bytes);
        ArtifactExtractor::extract($artifact->fresh());

        return $artifact->fresh();
    }

    private function requireObjdump(): void
    {
        $adapter = new ObjdumpAdapter;
        if (! $adapter->probe()['available']) {
            $this->markTestSkipped('isolated objdump runner is not available in this environment.');
        }
        // Dispatch is worker-gated: simulate a refreshed worker.
        \App\Models\WorkerCapability::capture($adapter, 'test');
    }

    /** Queue via HTTP, then run the job inline like a worker would. */
    private function queueAndRun(Project $project, User $owner, ProjectArtifact $artifact, array $params): BinaryAnalysis
    {
        Queue::fake();
        $response = $this->actingAs($owner)->post(
            route('projects.artifacts.analyses.store', [$project, $artifact]), $params
        );
        $response->assertRedirect();
        $analysis = BinaryAnalysis::where('project_artifact_id', $artifact->id)->latest('id')->firstOrFail();
        (new AnalyzeBinaryJob($analysis->id))->handle();

        return $analysis->fresh();
    }

    private function disassemblyText(array $evidence): string
    {
        $texts = [];
        foreach ($evidence['disassembly']['windows'] as $window) {
            foreach ($window['lines'] as $line) {
                $texts[] = sprintf('%x %s', $line['address'], $line['text']);
            }
        }

        return implode("\n", $texts);
    }

    public function test_objdump_disassembles_x86_64_raw_end_to_end(): void
    {
        $this->requireObjdump();
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::codeX86_64(), 'code.bin', ProjectArtifact::FORMAT_RAW);
        $this->assertSame(ProjectArtifact::FORMAT_RAW, $artifact->format);

        $analysis = $this->queueAndRun($project, $owner, $artifact, [
            'target' => 'x86-64', 'base_address' => '0x400000', 'entry' => '0x400000',
        ]);

        $this->assertSame('objdump', $analysis->adapter);
        $this->assertTrue($analysis->isUsable(), 'run failed: '.$analysis->error_code.' '.$analysis->error_message);
        $evidence = $analysis->readEvidence();
        $this->assertSame('GNU objdump', $evidence['tool']['name']);
        $listing = $this->disassemblyText($evidence);
        $this->assertStringContainsString('400000 push rbp', $listing);
        $this->assertStringContainsString('400004 call 0x40000b', $listing);
        $this->assertContains(['from' => 0x400004, 'to' => 0x40000B, 'kind' => 'call'], $evidence['xrefs']);
        $addresses = array_column($evidence['functions'], 'address');
        $this->assertContains(0x400000, $addresses);
        $this->assertContains(0x40000B, $addresses);
    }

    public function test_objdump_disassembles_x86_raw_end_to_end(): void
    {
        $this->requireObjdump();
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::codeX86(), 'code32.bin', ProjectArtifact::FORMAT_RAW);

        $analysis = $this->queueAndRun($project, $owner, $artifact, [
            'target' => 'x86', 'base_address' => '0x8000', 'entry' => '0x8000',
        ]);

        $this->assertSame('objdump', $analysis->adapter);
        $this->assertTrue($analysis->isUsable(), 'run failed: '.$analysis->error_code.' '.$analysis->error_message);
        $listing = $this->disassemblyText($analysis->readEvidence());
        $this->assertStringContainsString('8000 push ebp', $listing);
        $this->assertStringContainsString('8001 mov ebp,esp', $listing);
    }

    public function test_objdump_disassembles_elf64_structural_target(): void
    {
        $this->requireObjdump();
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::elf64(62, BinaryFixtures::codeX86_64()), 'tiny.elf');
        $this->assertSame(ProjectArtifact::FORMAT_ELF, $artifact->format);
        $this->assertSame('x86-64', $artifact->arch);

        // No target posted: the structural EM_X86_64 candidate applies.
        $analysis = $this->queueAndRun($project, $owner, $artifact, []);

        $this->assertSame('objdump', $analysis->adapter);
        $this->assertSame('x86-64', $analysis->target);
        $this->assertTrue($analysis->isUsable(), 'run failed: '.$analysis->error_code.' '.$analysis->error_message);
        $evidence = $analysis->readEvidence();
        $this->assertStringContainsString('400000 push rbp', $this->disassemblyText($evidence));
        $names = array_column($evidence['disassembly']['windows'], 'name');
        $this->assertContains('.text', $names);
        $this->assertContains(0x400000, array_column($evidence['functions'], 'address'));
    }

    public function test_objdump_disassembles_ihex_records(): void
    {
        $this->requireObjdump();
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $hex = BinaryFixtures::ihex([[0x1000, BinaryFixtures::codeX86()]], null);
        $artifact = $this->storeBytes($project, $owner, $hex, 'code.hex');
        $this->assertSame(ProjectArtifact::FORMAT_IHEX, $artifact->format);

        $analysis = $this->queueAndRun($project, $owner, $artifact, ['target' => 'x86']);

        $this->assertSame('objdump', $analysis->adapter);
        $this->assertTrue($analysis->isUsable(), 'run failed: '.$analysis->error_code.' '.$analysis->error_message);
        $listing = $this->disassemblyText($analysis->readEvidence());
        $this->assertStringContainsString('1000 push ebp', $listing);
    }

    public function test_arm32_raw_end_to_end(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::codeArm32(), 'firmware.bin', ProjectArtifact::FORMAT_RAW);
        $this->assertSame(ProjectArtifact::FORMAT_RAW, $artifact->format);

        $analysis = $this->queueAndRun($project, $owner, $artifact, [
            'target' => 'arm32', 'base_address' => '0x8000', 'entry' => '0x8000',
        ]);

        $this->assertSame('php-native', $analysis->adapter);
        $this->assertTrue($analysis->isUsable(), 'run failed: '.$analysis->error_code.' '.$analysis->error_message);
        $evidence = $analysis->readEvidence();
        $listing = $this->disassemblyText($evidence);
        $this->assertStringContainsString('8000 mov r0, #1', $listing);
        $this->assertStringContainsString('8004 bl 0x800c', $listing);
        $this->assertStringContainsString('800c mov r0, #0x1000000', $listing);
        $this->assertContains(['from' => 0x8004, 'to' => 0x800C, 'kind' => 'call'], $evidence['xrefs']);
        $addresses = array_column($evidence['functions'], 'address');
        $this->assertContains(0x8000, $addresses);
        $this->assertContains(0x800C, $addresses);
        // Vector-table head is reported unmasked, as candidates.
        $this->assertCount(2, $evidence['vectors']);
        $this->assertSame(0xE3A00001, $evidence['vectors'][0]['points_to']);
    }

    public function test_arm32_thumb_raw_end_to_end(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::codeThumb(), 'thumb.bin', ProjectArtifact::FORMAT_RAW);

        $analysis = $this->queueAndRun($project, $owner, $artifact, [
            'target' => 'arm32-thumb', 'base_address' => '0x8000', 'entry' => '0x8000',
        ]);

        $this->assertSame('php-native', $analysis->adapter);
        $this->assertTrue($analysis->isUsable(), 'run failed: '.$analysis->error_code.' '.$analysis->error_message);
        $evidence = $analysis->readEvidence();
        $listing = $this->disassemblyText($evidence);
        $this->assertStringContainsString('8000 movs r0, #1', $listing);
        $this->assertStringContainsString('8002 bl 0x8008', $listing);
        $this->assertStringContainsString('8008 movs r0, #2', $listing);
        $this->assertContains(['from' => 0x8002, 'to' => 0x8008, 'kind' => 'call'], $evidence['xrefs']);
    }

    public function test_arm32_elf_end_to_end(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::elf32(40, BinaryFixtures::codeArm32(), 0x10000), 'arm.elf');
        $this->assertSame(ProjectArtifact::FORMAT_ELF, $artifact->format);
        $this->assertSame('arm32', $artifact->arch);

        $analysis = $this->queueAndRun($project, $owner, $artifact, []);

        $this->assertSame('php-native', $analysis->adapter);
        $this->assertSame('arm32', $analysis->target);
        $this->assertTrue($analysis->isUsable(), 'run failed: '.$analysis->error_code.' '.$analysis->error_message);
        $listing = $this->disassemblyText($analysis->readEvidence());
        $this->assertStringContainsString('10000 mov r0, #1', $listing);
        $this->assertStringContainsString('10008 bx lr', $listing);
    }

    public function test_arm_and_thumb_are_distinct_targets(): void
    {
        // The same bytes decode differently per state: decoding Thumb
        // bytes as ARM state must not produce the Thumb listing.
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::codeThumb(), 'thumb-as-arm.bin', ProjectArtifact::FORMAT_RAW);

        $analysis = $this->queueAndRun($project, $owner, $artifact, [
            'target' => 'arm32', 'base_address' => '0x8000',
        ]);

        $this->assertTrue($analysis->isUsable());
        $listing = $this->disassemblyText($analysis->readEvidence());
        $this->assertStringNotContainsString('movs r0, #1', $listing);
    }

    public static function routingProvider(): array
    {
        return [
            'arm32 raw' => ['arm32', 'php-native', 'codeArm32'],
            'thumb raw' => ['arm32-thumb', 'php-native', 'codeThumb'],
            '6502 raw' => ['mos6502', 'php-native', 'code6502'],
            'z80 raw' => ['z80', 'php-native', 'codeZ80'],
            '8051 raw' => ['i8051', 'php-native', 'code8051'],
            'x86 raw' => ['x86', 'objdump', 'codeX86'],
            'x86-64 raw' => ['x86-64', 'objdump', 'codeX86_64'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routingProvider')]
    public function test_routing_matrix_raw_targets(string $target, string $adapter, string $fixture): void
    {
        if ($adapter === 'objdump') {
            $this->requireObjdump();
        }
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::$fixture(), 'matrix.bin', ProjectArtifact::FORMAT_RAW);

        $response = $this->actingAs($owner)->post(
            route('projects.artifacts.analyses.store', [$project, $artifact]),
            ['target' => $target, 'base_address' => '0x8000']
        );
        $response->assertRedirect();

        $analysis = BinaryAnalysis::where('project_artifact_id', $artifact->id)->latest('id')->firstOrFail();
        $this->assertSame($adapter, $analysis->adapter);
        $this->assertSame($target, $analysis->target);
    }

    public function test_contradicting_override_is_rejected_before_queue(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::elf64(62, BinaryFixtures::codeX86_64()), 'x64.elf');

        $response = $this->actingAs($owner)
            ->from(route('projects.artifacts.show', [$project, $artifact]))
            ->post(route('projects.artifacts.analyses.store', [$project, $artifact]), ['target' => 'arm32']);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('contradicts', (string) session('error'));
        $this->assertSame(0, BinaryAnalysis::where('project_artifact_id', $artifact->id)->count());
    }

    public function test_raw_without_base_address_is_rejected(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::codeArm32(), 'firmware.bin', ProjectArtifact::FORMAT_RAW);

        $response = $this->actingAs($owner)
            ->from(route('projects.artifacts.show', [$project, $artifact]))
            ->post(route('projects.artifacts.analyses.store', [$project, $artifact]), ['target' => 'arm32']);

        $response->assertRedirect();
        $this->assertStringContainsString('base address', (string) session('error'));
        $this->assertSame(0, BinaryAnalysis::where('project_artifact_id', $artifact->id)->count());
    }

    public function test_unavailable_target_fails_clearly_without_dispatch(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::codeArm32(), 'firmware.bin', ProjectArtifact::FORMAT_RAW);

        $response = $this->actingAs($owner)
            ->from(route('projects.artifacts.show', [$project, $artifact]))
            ->post(route('projects.artifacts.analyses.store', [$project, $artifact]), [
                'target' => 'pic18', 'base_address' => '0x8000',
            ]);

        $response->assertRedirect();
        $this->assertStringContainsString('No available backend', (string) session('error'));
        $this->assertSame(0, BinaryAnalysis::where('project_artifact_id', $artifact->id)->count());
    }

    public function test_backend_timeout_is_retryable_but_output_cap_is_not(): void
    {
        $failed = new BinaryAnalysis(['status' => BinaryAnalysis::STATUS_FAILED, 'error_code' => 'backend_timeout']);
        $this->assertTrue($failed->isRetryable());

        $capped = new BinaryAnalysis(['status' => BinaryAnalysis::STATUS_FAILED, 'error_code' => 'output_too_large']);
        $this->assertFalse($capped->isRetryable());
    }

    public function test_objdump_argv_builder_rejects_bad_input(): void
    {
        // Documents the runner-side argv shape (runner paths, verified
        // machines only). Path confinement moved into the runner,
        // which builds all paths itself and takes none from jobs.
        $adapter = new ObjdumpAdapter;
        $dir = sys_get_temp_dir();
        $input = $dir.'/objdump-input-'.uniqid().'.bin';

        $this->assertNull($adapter->buildCommand(['job_dir' => $dir, 'input' => $input, 'mode' => 'nope', 'machine' => 'i386']));
        $this->assertNull($adapter->buildCommand(['job_dir' => $dir, 'input' => $input, 'mode' => 'binary', 'machine' => 'arm']));
        $this->assertNull($adapter->buildCommand(['job_dir' => $dir, 'input' => $input, 'mode' => 'binary', 'machine' => 'i386']));
        $this->assertNull($adapter->buildCommand(['job_dir' => $dir, 'input' => $input, 'mode' => 'binary', 'machine' => 'i386', 'vma' => -1]));

        $auto = $adapter->buildCommand(['job_dir' => $dir, 'input' => $input, 'mode' => 'auto']);
        $this->assertSame(['/usr/bin/objdump', '-d', '-M', 'intel', '-w', $input], $auto);

        $raw = $adapter->buildCommand(['job_dir' => $dir, 'input' => $input, 'mode' => 'binary', 'machine' => 'i386:x86-64', 'vma' => 0x400000]);
        $this->assertSame(['/usr/bin/objdump', '-D', '-M', 'intel', '-w', '-b', 'binary', '-m', 'i386:x86-64', '--adjust-vma=4194304', $input], $raw);

        $srec = $adapter->buildCommand(['job_dir' => $dir, 'input' => $input, 'mode' => 'srec', 'machine' => 'i386']);
        $this->assertSame(['/usr/bin/objdump', '-D', '-M', 'intel', '-w', '-b', 'srec', '-m', 'i386', $input], $srec);
    }

    public function test_parse_architectures_reads_objdump_table(): void
    {
        $output = "BFD header file version (GNU Binutils for Debian) 2.44\n"
            ."         elf64-x86-64 elf32-i386\n"
            ."    i386 elf64-x86-64 elf32-i386\n"
            ."   iamcu ---------------- -------\n";

        $this->assertSame(['i386', 'iamcu'], ObjdumpAdapter::parseArchitectures($output));
    }

    // ---------- Archive members ----------

    public function test_arm_member_of_deflated_zip_end_to_end(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $zip = BinaryFixtures::zip(['docs/readme.txt' => 'hello', 'fw/image.bin' => BinaryFixtures::codeArm32()]);
        $artifact = $this->storeBytes($project, $owner, $zip, 'firmware.zip');
        $this->assertSame(ProjectArtifact::FORMAT_ARCHIVE, $artifact->format);

        $analysis = $this->queueAndRun($project, $owner, $artifact, [
            'member' => 'fw/image.bin', 'target' => 'arm32', 'base_address' => '0x8000',
        ]);

        $this->assertSame('php-native', $analysis->adapter);
        $this->assertTrue($analysis->isUsable(), 'run failed: '.$analysis->error_code.' '.$analysis->error_message);
        $evidence = $analysis->readEvidence();
        $this->assertSame('fw/image.bin', $evidence['artifact']['member']['name']);
        $this->assertSame('raw', $evidence['artifact']['member']['format']);
        $this->assertSame(hash('sha256', BinaryFixtures::codeArm32()), $evidence['artifact']['member']['sha256']);
        $listing = $this->disassemblyText($evidence);
        $this->assertStringContainsString('8000 mov r0, #1', $listing);
        $this->assertStringContainsString('8004 bl 0x800c', $listing);
    }

    public function test_x86_member_of_stored_zip_end_to_end(): void
    {
        $this->requireObjdump();
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $zip = BinaryFixtures::zipStored(['code.bin' => BinaryFixtures::codeX86()]);
        $artifact = $this->storeBytes($project, $owner, $zip, 'code.zip');

        $analysis = $this->queueAndRun($project, $owner, $artifact, [
            'member' => 'code.bin', 'target' => 'x86', 'base_address' => '0x8000',
        ]);

        $this->assertSame('objdump', $analysis->adapter);
        $this->assertTrue($analysis->isUsable(), 'run failed: '.$analysis->error_code.' '.$analysis->error_message);
        $this->assertStringContainsString('8000 push ebp', $this->disassemblyText($analysis->readEvidence()));
    }

    public function test_elf_member_validates_target_against_member_arch(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $zip = BinaryFixtures::zip(['app.elf' => BinaryFixtures::elf32(40, BinaryFixtures::codeArm32(), 0x10000)]);
        $artifact = $this->storeBytes($project, $owner, $zip, 'bundle.zip');

        // Matching target: structural member evidence, no base needed.
        $analysis = $this->queueAndRun($project, $owner, $artifact, [
            'member' => 'app.elf', 'target' => 'arm32',
        ]);
        $this->assertSame('php-native', $analysis->adapter);
        $this->assertTrue($analysis->isUsable(), 'run failed: '.$analysis->error_code.' '.$analysis->error_message);
        $evidence = $analysis->readEvidence();
        $this->assertSame('elf', $evidence['artifact']['member']['format']);
        $this->assertSame('arm32', $evidence['artifact']['member']['arch']);
        $this->assertStringContainsString('10000 mov r0, #1', $this->disassemblyText($evidence));

        // Contradicting target: refused by the job with the member reason.
        // (z80 routes to the bundled adapter, so no worker snapshot is
        // needed to exercise the member/arch comparison itself.)
        Queue::fake();
        $bad = $this->actingAs($owner)->post(
            route('projects.artifacts.analyses.store', [$project, $artifact]),
            ['member' => 'app.elf', 'target' => 'z80']
        );
        $bad->assertRedirect();
        $mismatch = BinaryAnalysis::where('project_artifact_id', $artifact->id)->latest('id')->firstOrFail();
        (new AnalyzeBinaryJob($mismatch->id))->handle();
        $mismatch->refresh();
        $this->assertSame(BinaryAnalysis::STATUS_FAILED, $mismatch->status);
        $this->assertSame('member_target_mismatch', $mismatch->error_code);
    }

    public function test_archive_without_member_is_rejected_with_guidance(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::zip(), 'plain.zip');

        $response = $this->actingAs($owner)
            ->from(route('projects.artifacts.show', [$project, $artifact]))
            ->post(route('projects.artifacts.analyses.store', [$project, $artifact]), ['target' => 'arm32-thumb']);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('member', (string) session('error'));
        $this->assertSame(0, BinaryAnalysis::where('project_artifact_id', $artifact->id)->count());
    }

    public function test_unknown_and_empty_members_rejected_at_dispatch(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes(
            $project, $owner,
            BinaryFixtures::zip(['fw/image.bin' => BinaryFixtures::codeArm32(), 'empty.bin' => '']),
            'bundle.zip'
        );

        $missing = $this->actingAs($owner)
            ->from(route('projects.artifacts.show', [$project, $artifact]))
            ->post(route('projects.artifacts.analyses.store', [$project, $artifact]), [
                'member' => 'nope.bin', 'target' => 'arm32', 'base_address' => '0x8000',
            ]);
        $missing->assertSessionHas('error');
        $this->assertStringContainsString('not listed', (string) session('error'));

        $empty = $this->actingAs($owner)
            ->from(route('projects.artifacts.show', [$project, $artifact]))
            ->post(route('projects.artifacts.analyses.store', [$project, $artifact]), [
                'member' => 'empty.bin', 'target' => 'arm32', 'base_address' => '0x8000',
            ]);
        $empty->assertSessionHas('error');
        $this->assertStringContainsString('empty', (string) session('error'));

        $this->assertSame(0, BinaryAnalysis::where('project_artifact_id', $artifact->id)->count());
    }

    public function test_raw_member_without_base_fails_clearly_in_job(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes(
            $project, $owner,
            BinaryFixtures::zip(['fw/image.bin' => BinaryFixtures::codeArm32()]),
            'bundle.zip'
        );

        // Dispatch cannot know the member is raw (bytes are job-side),
        // so it queues; the job refuses with the exact requirement.
        Queue::fake();
        $response = $this->actingAs($owner)->post(
            route('projects.artifacts.analyses.store', [$project, $artifact]),
            ['member' => 'fw/image.bin', 'target' => 'arm32']
        );
        $response->assertRedirect();
        $analysis = BinaryAnalysis::where('project_artifact_id', $artifact->id)->latest('id')->firstOrFail();
        (new AnalyzeBinaryJob($analysis->id))->handle();
        $analysis->refresh();

        $this->assertSame(BinaryAnalysis::STATUS_FAILED, $analysis->status);
        $this->assertSame('member_base_required', $analysis->error_code);
        $this->assertStringContainsString('base address', $analysis->error_message);
    }

    public function test_distinct_members_produce_distinct_runs(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes(
            $project, $owner,
            BinaryFixtures::zip(['a.bin' => BinaryFixtures::codeArm32(), 'b.bin' => BinaryFixtures::codeArm32()]),
            'two.zip'
        );

        foreach (['a.bin', 'b.bin'] as $member) {
            $this->actingAs($owner)->post(
                route('projects.artifacts.analyses.store', [$project, $artifact]),
                ['member' => $member, 'target' => 'arm32', 'base_address' => '0x8000']
            )->assertRedirect();
        }

        $this->assertSame(2, BinaryAnalysis::where('project_artifact_id', $artifact->id)->count());
    }

    public function test_member_staging_rejects_tampered_archives(): void
    {
        // The job re-validates against live bytes: flip the encryption
        // flag in the central directory after extraction-time.
        $zip = BinaryFixtures::zip(['fw/image.bin' => BinaryFixtures::codeArm32()]);
        $at = strpos($zip, "PK\x01\x02");
        $this->assertNotFalse($at);
        $tampered = substr_replace($zip, chr(ord($zip[$at + 8]) | 0x01), $at + 8, 1);

        $result = \App\Services\Binary\ArchiveMember::prepare($tampered, 'fw/image.bin', 'arm32', ['base_address' => 0x8000]);

        $this->assertFalse($result['ok']);
        $this->assertSame('member_encrypted', $result['code']);
    }
    // ---------- Worker capability gating ----------

    public function test_objdump_dispatch_requires_fresh_worker_snapshot(): void
    {
        $adapter = new ObjdumpAdapter;
        if (! $adapter->probe()['available']) {
            $this->markTestSkipped('isolated objdump runner is not available in this environment.');
        }
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::codeX86(), 'code.bin', ProjectArtifact::FORMAT_RAW);

        // No snapshot: refused with the actionable worker story.
        $refused = $this->actingAs($owner)
            ->from(route('projects.artifacts.show', [$project, $artifact]))
            ->post(route('projects.artifacts.analyses.store', [$project, $artifact]), [
                'target' => 'x86', 'base_address' => '0x8000',
            ]);
        $refused->assertSessionHas('error');
        $this->assertStringContainsString('not execution-ready', (string) session('error'));
        $this->assertSame(0, BinaryAnalysis::where('project_artifact_id', $artifact->id)->count());

        // Fresh snapshot: the identical request proceeds.
        \App\Models\WorkerCapability::capture($adapter, 'test');
        $this->actingAs($owner)->post(
            route('projects.artifacts.analyses.store', [$project, $artifact]),
            ['target' => 'x86', 'base_address' => '0x8000']
        )->assertRedirect();
        $this->assertSame(1, BinaryAnalysis::where('project_artifact_id', $artifact->id)->count());
    }

    public function test_stale_worker_snapshot_refuses_dispatch(): void
    {
        $adapter = new ObjdumpAdapter;
        if (! $adapter->probe()['available']) {
            $this->markTestSkipped('isolated objdump runner is not available in this environment.');
        }
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::codeX86(), 'code.bin', ProjectArtifact::FORMAT_RAW);

        $row = \App\Models\WorkerCapability::capture($adapter, 'test');
        $row->forceFill(['captured_at' => now()->subHours(25)])->save();

        $response = $this->actingAs($owner)
            ->from(route('projects.artifacts.show', [$project, $artifact]))
            ->post(route('projects.artifacts.analyses.store', [$project, $artifact]), [
                'target' => 'x86', 'base_address' => '0x8000',
            ]);
        $response->assertSessionHas('error');
        $this->assertStringContainsString('stale', (string) session('error'));
    }

    public function test_job_fails_on_backend_version_drift(): void
    {
        $this->requireObjdump();
        Queue::fake();
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::codeX86(), 'code.bin', ProjectArtifact::FORMAT_RAW);

        $this->actingAs($owner)->post(
            route('projects.artifacts.analyses.store', [$project, $artifact]),
            ['target' => 'x86', 'base_address' => '0x8000']
        )->assertRedirect();
        $analysis = BinaryAnalysis::where('project_artifact_id', $artifact->id)->latest('id')->firstOrFail();
        $analysis->forceFill(['adapter_version' => '0.0-drift-test'])->save();

        (new AnalyzeBinaryJob($analysis->id))->handle();
        $analysis->refresh();

        $this->assertSame(BinaryAnalysis::STATUS_FAILED, $analysis->status);
        $this->assertSame('worker_mismatch', $analysis->error_code);
        $this->assertFalse($analysis->isRetryable());
    }

    public function test_worker_capabilities_command_reports_without_capturing(): void
    {
        $this->artisan('binary:worker-capabilities')->assertSuccessful();
        $this->assertSame(0, \App\Models\WorkerCapability::count());
    }

    public function test_worker_capabilities_sync_refuses_without_local_spool(): void
    {
        // Inline capture measures THIS container: without the spool
        // mounts it must refuse instead of recording bogus snapshots
        // that would shadow the real runners until the next refresh.
        config([
            'artifacts.binary_spool_dir' => sys_get_temp_dir().'/rev2-no-spool-'.uniqid(),
            'artifacts.binary_results_dir' => sys_get_temp_dir().'/rev2-no-results-'.uniqid(),
        ]);

        $this->artisan('binary:worker-capabilities', ['--sync' => true])
            ->expectsOutputToContain('No analysis spool is mounted in this container')
            ->assertFailed();
        $this->assertSame(0, \App\Models\WorkerCapability::count());
    }

    public function test_worker_capabilities_sync_captures_through_bound_spool(): void
    {
        // Spool dirs exist here (as on the coordinator) and a bound
        // fake answers instantly, so capture stays fast: one verified
        // bundled backend plus five unavailable sandboxed ones.
        $spool = new SpoolFake;
        app()->instance(SpoolClient::class, $spool);
        config([
            'artifacts.binary_spool_dir' => $spool->spoolDir(),
            'artifacts.binary_results_dir' => $spool->resultsDir(),
        ]);

        $this->artisan('binary:worker-capabilities', ['--sync' => true])->assertSuccessful();
        $this->assertSame(6, \App\Models\WorkerCapability::count());
        $native = \App\Models\WorkerCapability::where('adapter', 'php-native')->firstOrFail();
        $this->assertTrue($native->available);
        $sandboxed = \App\Models\WorkerCapability::where('adapter', 'objdump')->firstOrFail();
        $this->assertFalse($sandboxed->available);
    }

    // ---------- Selector/registry consistency ----------

    public function test_selector_options_match_routing_for_raw(): void
    {
        $this->requireObjdump();
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::codeArm32(), 'firmware.bin', ProjectArtifact::FORMAT_RAW);

        $options = \App\Services\Binary\CapabilityMatrix::targetOptions($artifact, $artifact->readSnapshot());
        $byId = array_column($options, null, 'id');

        foreach (['arm32', 'arm32-thumb', 'mos6502', 'z80', 'i8051', 'x86', 'x86-64'] as $id) {
            $this->assertTrue($byId[$id]['requestable'], $id.' should be requestable');
        }
        foreach (['aarch64', 'mips32', 'riscv32', 'riscv64', 'ppc32', 'avr', 'pic18', 'msp430', 'dalvik', 'jvm', 'clr'] as $id) {
            $this->assertFalse($byId[$id]['requestable'], $id.' should be disabled');
            $this->assertNotSame('', $byId[$id]['reason'], $id.' needs a reason');
        }

        // Every requestable option must actually dispatch.
        foreach ($options as $option) {
            if (! $option['requestable']) {
                continue;
            }
            $response = $this->actingAs($owner)->post(
                route('projects.artifacts.analyses.store', [$project, $artifact]),
                ['target' => $option['id'], 'base_address' => '0x8000']
            );
            $response->assertRedirect();
            $response->assertSessionMissing('error');
        }
        $this->assertSame(7, BinaryAnalysis::where('project_artifact_id', $artifact->id)->count());
    }

    public function test_artifact_page_disables_unavailable_targets_with_reasons(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::codeArm32(), 'firmware.bin', ProjectArtifact::FORMAT_RAW);

        $page = $this->actingAs($owner)->get(route('projects.artifacts.show', [$project, $artifact]))->assertOk();
        $html = (string) preg_replace('/\s+/', ' ', $page->getContent());
        $this->assertStringContainsString('value="arm32"', $html);
        $this->assertStringNotContainsString('value="arm32" disabled', $html);
        $this->assertStringContainsString('value="arm32-thumb"', $html);
        $this->assertStringNotContainsString('value="arm32-thumb" disabled', $html);
        $this->assertStringContainsString('value="pic18" disabled', $html);
        $this->assertStringContainsString('value="mips32" disabled', $html);
        // Decompilation honesty is visible, not buried.
        $this->assertStringContainsString('decompilation', strtolower($html));
    }

    public function test_archive_page_offers_member_picker(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $zip = BinaryFixtures::zip(['fw/image.bin' => BinaryFixtures::codeArm32(), 'docs/readme.txt' => 'hello']);
        $artifact = $this->storeBytes($project, $owner, $zip, 'bundle.zip');

        $page = $this->actingAs($owner)->get(route('projects.artifacts.show', [$project, $artifact]))->assertOk();
        $page->assertSee('name="member"', false);
        $page->assertSee('fw/image.bin', false);
        $page->assertSee('docs/readme.txt', false);
    }

    public function test_archive_page_disables_encrypted_member(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $zip = BinaryFixtures::zip(['fw/image.bin' => BinaryFixtures::codeArm32()]);
        $at = strpos($zip, "PK\x01\x02");
        $tampered = substr_replace($zip, chr(ord($zip[$at + 8]) | 0x01), $at + 8, 1);
        $artifact = $this->storeBytes($project, $owner, $tampered, 'locked.zip');
        $this->assertSame(ProjectArtifact::FORMAT_ARCHIVE, $artifact->format);

        $html = (string) preg_replace('/\s+/', ' ', $this->actingAs($owner)->get(route('projects.artifacts.show', [$project, $artifact]))->assertOk()->getContent());
        $this->assertStringContainsString('value="fw/image.bin" disabled', $html);
        $this->assertStringContainsString('encrypted', $html);
    }

    public function test_objdump_decompilation_reported_unsupported(): void
    {
        $caps = (new ObjdumpAdapter)->capabilities(ProjectArtifact::FORMAT_RAW, 'x86-64');

        $this->assertSame(
            \App\Services\Binary\Adapters\Capability::SUPPORTED,
            $caps[\App\Services\Binary\Adapters\Capability::DISASSEMBLY]
        );
        $this->assertSame(
            \App\Services\Binary\Adapters\Capability::UNSUPPORTED,
            $caps[\App\Services\Binary\Adapters\Capability::DECOMPILATION]
        );
    }

    // ---------- Aliases, invalid input, authorization ----------

    public static function archAliasProvider(): array
    {
        return [
            ['x86', 'x86'], ['x86-64', 'x86-64'], ['aarch64', 'aarch64'],
            ['mips', 'mips32'], ['riscv32', 'riscv32'], ['riscv64', 'riscv64'],
            ['ppc', 'ppc32'], ['arm32', 'arm32'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('archAliasProvider')]
    public function test_arch_aliases_normalize_to_targets(string $arch, string $target): void
    {
        $candidates = \App\Services\Binary\Targets::candidatesFor(ProjectArtifact::FORMAT_ELF, $arch, ['data' => []]);

        $this->assertNotSame([], $candidates);
        $this->assertSame($target, $candidates[0]['id']);
        $this->assertSame('structural', $candidates[0]['source']);
    }

    public function test_unknown_target_and_backend_rejected(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::codeArm32(), 'firmware.bin', ProjectArtifact::FORMAT_RAW);

        $badTarget = $this->actingAs($owner)
            ->from(route('projects.artifacts.show', [$project, $artifact]))
            ->post(route('projects.artifacts.analyses.store', [$project, $artifact]), [
                'target' => 'z80-aliens', 'base_address' => '0x8000',
            ]);
        $badTarget->assertSessionHas('error');
        $this->assertStringContainsString('Unknown processor target', (string) session('error'));

        $badBackend = $this->actingAs($owner)
            ->from(route('projects.artifacts.show', [$project, $artifact]))
            ->post(route('projects.artifacts.analyses.store', [$project, $artifact]), [
                'target' => 'arm32', 'base_address' => '0x8000', 'adapter' => 'nope',
            ]);
        $badBackend->assertSessionHas('error');
        $this->assertStringContainsString('Unknown analysis backend', (string) session('error'));

        $this->assertSame(0, BinaryAnalysis::where('project_artifact_id', $artifact->id)->count());
    }

    public function test_analysis_endpoints_reject_unauthorized_requests(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $artifact = $this->storeBytes($project, $owner, BinaryFixtures::codeArm32(), 'firmware.bin', ProjectArtifact::FORMAT_RAW);
        $stranger = User::factory()->create();
        [$otherProject, $otherOwner] = $this->seedProject();

        // Guests are sent to login.
        $this->post(route('projects.artifacts.analyses.store', [$project, $artifact]), [])
            ->assertRedirect(route('login'));

        // Non-members get 403, not a queue.
        $this->actingAs($stranger)->post(
            route('projects.artifacts.analyses.store', [$project, $artifact]),
            ['target' => 'arm32', 'base_address' => '0x8000']
        )->assertForbidden();

        // Cross-project artifact ids 404 (after project auth passes).
        $this->actingAs($otherOwner)->post(
            route('projects.artifacts.analyses.store', [$otherProject, $artifact]),
            ['target' => 'arm32', 'base_address' => '0x8000']
        )->assertNotFound();

        $this->assertSame(0, BinaryAnalysis::where('project_artifact_id', $artifact->id)->count());
    }

    public function test_objdump_disassembles_pe64_structural_target(): void
    {
        $this->requireObjdump();
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $pe = BinaryFixtures::pe(['code' => BinaryFixtures::codeX86_64()]);
        $artifact = $this->storeBytes($project, $owner, $pe['bytes'], 'tiny.exe');
        $this->assertSame(ProjectArtifact::FORMAT_PE, $artifact->format);
        $this->assertSame('x86-64', $artifact->arch);

        // No target posted: the structural PE machine type applies.
        $analysis = $this->queueAndRun($project, $owner, $artifact, []);

        $this->assertSame('objdump', $analysis->adapter);
        $this->assertSame('x86-64', $analysis->target);
        $this->assertTrue($analysis->isUsable(), 'run failed: '.$analysis->error_code.' '.$analysis->error_message);
        $evidence = $analysis->readEvidence();
        $this->assertStringContainsString('140001000 push rbp', $this->disassemblyText($evidence));
        $names = array_column($evidence['disassembly']['windows'], 'name');
        $this->assertContains('.text', $names);
    }

    public function test_arm_pe_pairing_structural_and_thumb_override(): void
    {
        Storage::fake('artifacts');
        [$project, $owner] = $this->seedProject();
        $pe = BinaryFixtures::pe(['machine' => 0x1C0, 'code' => BinaryFixtures::codeArm32()]);
        $artifact = $this->storeBytes($project, $owner, $pe['bytes'], 'arm-app.exe');
        $this->assertSame(ProjectArtifact::FORMAT_PE, $artifact->format);
        $this->assertSame('arm32', $artifact->arch);

        $analysis = $this->queueAndRun($project, $owner, $artifact, []);
        $this->assertSame('php-native', $analysis->adapter);
        $this->assertSame('arm32', $analysis->target);
        $this->assertTrue($analysis->isUsable(), 'run failed: '.$analysis->error_code.' '.$analysis->error_message);
        $this->assertStringContainsString('40001000 mov r0, #1', $this->disassemblyText($analysis->readEvidence()));

        // ARM/Thumb override stays within the family and runs.
        $thumb = $this->queueAndRun($project, $owner, $artifact, ['target' => 'arm32-thumb']);
        $this->assertSame('arm32-thumb', $thumb->target);
        $this->assertTrue($thumb->isUsable(), 'run failed: '.$thumb->error_code.' '.$thumb->error_message);
    }
}
