<?php

namespace Tests\Unit\Binary\Sandbox;

use App\Models\BinaryAnalysis;
use App\Models\Project;
use App\Models\ProjectArtifact;
use App\Models\User;
use App\Services\Artifacts\ArtifactExtractor;
use App\Services\Binary\Adapters\AnalysisFailedException;
use App\Services\Binary\Adapters\ExtractAdapter;
use App\Services\Binary\Adapters\GhidraAdapter;
use App\Services\Binary\Adapters\IlspyAdapter;
use App\Services\Binary\Adapters\JadxAdapter;
use App\Services\Binary\Adapters\ObjdumpAdapter;
use App\Services\Binary\Sandbox\EvidenceValidator;
use App\Services\Binary\Sandbox\SpoolClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BinaryFixtures;
use Tests\Support\SpoolFake;
use Tests\TestCase;

/**
 * Sandboxed adapters against a spool fake: job-shape submission,
 * runner-evidence normalization, and rejection paths. The fake only
 * stands in for the runner process — objdump parsing is asserted
 * against byte-identical real tool output (see fixture provenance).
 */
class SpooledAdaptersTest extends TestCase
{
    use RefreshDatabase;

    private SpoolFake $spool;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('artifacts');
        $this->spool = new SpoolFake;
        app()->instance(SpoolClient::class, $this->spool);
    }

    private function seedArtifact(string $bytes, string $name, ?string $format = null): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->draft()->create(['owner_id' => $owner->id]);
        $artifact = ProjectArtifact::create([
            'project_id' => $project->id,
            'uploader_id' => $owner->id,
            'original_name' => $name,
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'storage_key' => str()->random(40),
            'format' => $format ?? ArtifactExtractor::detect($bytes, $name),
            'state' => ProjectArtifact::STATE_QUEUED,
        ]);
        Storage::disk('artifacts')->put($artifact->rawPath(), $bytes);
        ArtifactExtractor::extract($artifact->fresh());

        return [$artifact->fresh(), $owner];
    }

    private function analysis(ProjectArtifact $artifact, User $owner, string $adapter, ?string $target, array $config = []): BinaryAnalysis
    {
        return BinaryAnalysis::create([
            'project_artifact_id' => $artifact->id,
            'requested_by' => $owner->id,
            'adapter' => $adapter,
            'adapter_version' => 'test',
            'target' => $target,
            'config' => $config,
            'status' => BinaryAnalysis::STATUS_ANALYZING,
            'dedupe_key' => 'test-'.str()->random(32),
        ]);
    }

    public function test_objdump_parses_real_tool_output_from_spool(): void
    {
        // Fixture provenance: GNU objdump (Debian) 2.44,
        // `objdump -D -M intel -w -b binary -m i386
        // --adjust-vma=32768` over BinaryFixtures::codeX86().
        // Byte-identical to live tool output (verified at check-in).
        $this->spool->probes['objdump'] = SpoolFake::answer('objdump', '2.40', ['architectures' => ['i386', 'arm', 'aarch64']]);
        $this->spool->runs['objdump'] = [
            'evidence' => ['schema' => 1, 'tool' => 'objdump', 'exit_code' => 0, 'output_file' => 'tool.out', 'output_truncated' => false],
            'tool_output' => file_get_contents(base_path('tests/Fixtures/Binary/objdump-i386-raw.txt')),
        ];

        $adapter = new ObjdumpAdapter;
        $this->assertTrue($adapter->probe()['available']);
        $this->assertSame(['x86', 'x86-64'], $adapter->targets());
        // Probed-but-unverified architectures surface as candidates only.
        $candidateIds = array_column($adapter->candidateTargets(), 'id');
        $this->assertContains('arm32', $candidateIds);
        $this->assertNotContains('x86', $candidateIds);

        [$artifact, $owner] = $this->seedArtifact(BinaryFixtures::codeX86(), 'code.bin', ProjectArtifact::FORMAT_RAW);
        $analysis = $this->analysis($artifact, $owner, 'objdump', 'x86', ['base_address' => 0x8000]);
        $evidence = $adapter->analyze($analysis, BinaryFixtures::codeX86(), [], ['base_address' => 0x8000], fn () => true);

        // The submitted job shape is the allowlisted profile triple.
        $this->assertSame('binary', $this->spool->seen[0]['profile']);
        $this->assertSame(['machine' => 'i386', 'vma' => 0x8000], $this->spool->seen[0]['params']);

        // Known instructions at known addresses — not mere non-emptiness.
        $lines = $evidence['disassembly']['windows'][0]['lines'];
        $texts = [];
        foreach ($lines as $line) {
            $texts[sprintf('%x', $line['address'])] = $line['text'];
        }
        $this->assertSame('push ebp', $texts['8000']);
        $this->assertSame('mov ebp,esp', $texts['8001']);
        $this->assertSame('call 0x800a', $texts['8003']);
        $this->assertSame('ret', $texts['8009']);
        $this->assertSame('c3', $lines[4]['bytes']);

        // Call xref recovered from the branch mnemonic.
        $this->assertContains(['from' => 0x8003, 'to' => 0x800a, 'kind' => 'call'], $evidence['xrefs']);

        // The job-time validator accepts the normalized envelope.
        $validated = EvidenceValidator::validate('objdump', $evidence);
        $this->assertSame('objdump-section', $validated['disassembly']['windows'][0]['kind']);
    }

    public function test_objdump_refuses_unverified_machine(): void
    {
        [$artifact, $owner] = $this->seedArtifact(BinaryFixtures::codeX86(), 'code.bin', ProjectArtifact::FORMAT_RAW);
        $analysis = $this->analysis($artifact, $owner, 'objdump', 'arm32', ['base_address' => 0x8000]);

        try {
            (new ObjdumpAdapter)->analyze($analysis, 'x', [], ['base_address' => 0x8000], fn () => true);
            $this->fail('unverified machine routed');
        } catch (AnalysisFailedException $e) {
            $this->assertSame('target_unsupported', $e->errorCode);
        }
        $this->assertSame([], $this->spool->seen);
    }

    public function test_ghidra_normalizes_hex_evidence_and_rejects_bad_addresses(): void
    {
        $this->spool->probes['ghidra'] = SpoolFake::answer('ghidra', '12.2');
        $runnerEvidence = [
            'schema' => 1, 'tool' => 'ghidra', 'tool_version' => '12.2',
            'language' => 'ARM:LE:32:v8', 'compiler_spec' => 'default',
            'image_base' => '0x8000',
            'memory' => [['name' => 'ram', 'start' => '0x8000', 'size' => 16, 'execute' => true, 'initialized' => true]],
            'functions' => [
                ['name' => 'entry', 'entry' => '0x8000', 'size' => 12],
                ['name' => 'callee', 'entry' => '0x800c', 'size' => 4],
            ],
            'xrefs' => [['from' => '0x8000', 'to' => '0x800c', 'kind' => 'call']],
            'disassembly' => [
                'lines' => [
                    ['address' => '0x8000', 'bytes' => '0100a0e3', 'text' => 'MOV r0,#1'],
                    ['address' => '0x8004', 'bytes' => '000000eb', 'text' => 'BL 0x800c'],
                ],
                'truncated' => false,
            ],
            'decompilation' => [
                'functions' => [['name' => 'entry', 'entry' => '0x8000', 'c' => 'void entry(void)\n{\n}\n']],
                'truncated' => false,
            ],
            'warnings' => [],
        ];
        $this->spool->runs['ghidra'] = ['evidence' => $runnerEvidence];

        [$artifact, $owner] = $this->seedArtifact(BinaryFixtures::codeArm32(), 'fw.bin', ProjectArtifact::FORMAT_RAW);
        $analysis = $this->analysis($artifact, $owner, 'ghidra', 'arm32', ['base_address' => 0x8000]);
        $evidence = (new GhidraAdapter)->analyze($analysis, BinaryFixtures::codeArm32(), [], ['base_address' => 0x8000], fn () => true);

        $this->assertSame('headless', $this->spool->seen[0]['profile']);
        $this->assertSame('ARM:LE:32:v8', $this->spool->seen[0]['params']['language']);
        $this->assertSame(0x8000, $this->spool->seen[0]['params']['base']);

        $this->assertSame('f8000', $evidence['functions'][0]['id']);
        $this->assertSame(0x8000, $evidence['functions'][0]['address']);
        $this->assertSame('ghidra', $evidence['functions'][0]['source']);
        $this->assertSame(['from' => 0x8000, 'to' => 0x800c, 'kind' => 'call'], $evidence['xrefs'][0]);
        $this->assertSame('MOV r0,#1', $evidence['disassembly']['windows'][0]['lines'][0]['text']);
        $this->assertStringContainsString('void entry', $evidence['decompilation']['functions'][0]['c']);
        $this->assertSame(['f800c'], $evidence['calls']['f8000']['callees'] ?? null);

        EvidenceValidator::validate('ghidra', $evidence);

        // A malformed address fails the run — never becomes zero.
        $bad = $runnerEvidence;
        $bad['functions'][0]['entry'] = 'not-hex';
        $this->spool->runs['ghidra'] = ['evidence' => $bad];
        try {
            (new GhidraAdapter)->analyze($analysis, 'x', [], ['base_address' => 0x8000], fn () => true);
            $this->fail('malformed address accepted');
        } catch (AnalysisFailedException $e) {
            $this->assertSame('evidence_rejected', $e->errorCode);
        }
    }

    public function test_ghidra_refuses_documented_but_unverified_target(): void
    {
        [$artifact, $owner] = $this->seedArtifact('xxxx', 'fw.bin', ProjectArtifact::FORMAT_RAW);
        $analysis = $this->analysis($artifact, $owner, 'ghidra', 'mips32', ['base_address' => 0x8000]);

        try {
            (new GhidraAdapter)->analyze($analysis, 'xxxx', [], ['base_address' => 0x8000], fn () => true);
            $this->fail('unverified language routed');
        } catch (AnalysisFailedException $e) {
            $this->assertSame('target_unsupported', $e->errorCode);
        }
        $this->assertSame([], $this->spool->seen);
    }

    public function test_jadx_probe_requires_fixture_ok(): void
    {
        $this->spool->probes['jadx'] = SpoolFake::answer('jadx', '1.5.6', [
            'fixture' => ['name' => 'dex-smoke.dex', 'ok' => true],
        ]);

        $this->assertTrue((new JadxAdapter)->probe()['available']);
    }

    public function test_jadx_probe_without_fixture_stays_not_operational(): void
    {
        // Stale runner predating the fixture: version ping only.
        $this->spool->probes['jadx'] = SpoolFake::answer('jadx', '1.5.6');
        $probe = (new JadxAdapter)->probe();
        $this->assertFalse($probe['available']);
        $this->assertStringContainsString('not operational yet', $probe['reason']);

        // Runner up but decompile broken: still not ready.
        $this->spool->probes['jadx'] = SpoolFake::answer('jadx', '1.5.6', [
            'fixture' => ['name' => 'dex-smoke.dex', 'ok' => false],
        ]);
        $this->assertFalse((new JadxAdapter)->probe()['available']);
    }

    public function test_jadx_and_ilspy_normalize_reconstruction(): void
    {
        $this->spool->probes['jadx'] = SpoolFake::answer('jadx', '1.5.6');
        $tree = [
            'schema' => 1, 'tool' => 'jadx', 'exit_code' => 0, 'profile' => 'apk',
            'files' => [
                ['path' => 'sources/com/acme/Foo.java', 'size' => 20, 'sha256' => hash('sha256', 'a')],
                ['path' => 'resources/AndroidManifest.xml', 'size' => 30, 'sha256' => hash('sha256', 'b')],
            ],
            'inline' => ['sources/com/acme/Foo.java' => 'package com.acme; class Foo{}'],
            'truncated' => false,
        ];
        $this->spool->runs['jadx'] = ['evidence' => $tree];

        [$artifact, $owner] = $this->seedArtifact('PK fake apk', 'app.apk');
        // Force the dex/apk test double: detection of the fake fails, so
        // declare the format the way a real APK parse would have set it.
        $artifact->forceFill(['format' => ProjectArtifact::FORMAT_APK, 'state' => ProjectArtifact::STATE_READY])->save();
        $analysis = $this->analysis($artifact, $owner, 'jadx', 'dalvik', []);
        $evidence = (new JadxAdapter)->analyze($analysis, 'PK fake apk', [], [], fn () => true);

        $this->assertSame('apk', $this->spool->seen[0]['profile']);
        $recon = EvidenceValidator::validate('jadx', $evidence)['reconstruction'];
        $this->assertSame('com.acme.Foo', $recon['classes'][0]['name']);
        $this->assertSame('package com.acme; class Foo{}', $recon['inline']['sources/com/acme/Foo.java']);

        // Traversal in the manifest fails the run.
        $evil = $tree;
        $evil['files'][] = ['path' => '../evil.sh', 'size' => 1, 'sha256' => hash('sha256', 'c')];
        $this->spool->runs['jadx'] = ['evidence' => $evil];
        try {
            (new JadxAdapter)->analyze($analysis, 'x', [], [], fn () => true);
            $this->fail('traversal accepted');
        } catch (AnalysisFailedException $e) {
            $this->assertSame('evidence_rejected', $e->errorCode);
        }

        // ILSpy shares the reconstruction shape with .cs classes.
        $this->spool->probes['ilspy'] = SpoolFake::answer('ilspy', '11.2.0.10050');
        $this->spool->runs['ilspy'] = ['evidence' => [
            'schema' => 1, 'tool' => 'ilspy', 'exit_code' => 0, 'profile' => 'assembly',
            'files' => [['path' => 'Acme/Foo.cs', 'size' => 10, 'sha256' => hash('sha256', 'd')]],
            'inline' => ['Acme/Foo.cs' => 'class Foo{}'],
            'truncated' => false,
        ]];
        [$peArtifact, $peOwner] = $this->seedArtifact('MZ fake', 'app.exe');
        $peArtifact->forceFill(['format' => ProjectArtifact::FORMAT_PE, 'state' => ProjectArtifact::STATE_READY])->save();
        $peAnalysis = $this->analysis($peArtifact, $peOwner, 'ilspy', 'clr', []);
        $peEvidence = (new IlspyAdapter)->analyze($peAnalysis, 'MZ fake', [], [], fn () => true);
        $recon = EvidenceValidator::validate('ilspy', $peEvidence)['reconstruction'];
        $this->assertSame('Acme.Foo', $recon['classes'][0]['name']);

        // Empty manifest (native PE, no managed types) warns honestly.
        $this->spool->runs['ilspy'] = ['evidence' => [
            'schema' => 1, 'tool' => 'ilspy', 'exit_code' => 0, 'profile' => 'assembly',
            'files' => [], 'inline' => [], 'truncated' => false,
        ]];
        $empty = (new IlspyAdapter)->analyze($peAnalysis, 'MZ fake', [], [], fn () => true);
        $this->assertContains('no_types_reconstructed', $empty['warnings']);
    }

    public function test_extract_promotes_contained_executable_as_child(): void
    {
        Queue::fake();
        $this->spool->probes['extract'] = SpoolFake::answer('extract', '1.0');

        $elf = "\x7fELF".str_repeat("\0", 60);
        $elfTmp = tempnam(sys_get_temp_dir(), 'rev2-stored-');
        file_put_contents($elfTmp, $elf);
        $this->spool->runs['extract'] = ['evidence' => [
            'schema' => 1, 'tool' => 'extract', 'exit_code' => 0, 'profile' => '7zz-extract',
            'files' => [
                ['path' => 'bin/app', 'size' => strlen($elf), 'sha256' => hash('sha256', $elf)],
                ['path' => 'etc/config.txt', 'size' => 4, 'sha256' => hash('sha256', 'cfg!')],
            ],
            'inline' => ['etc/config.txt' => 'cfg!'],
            'truncated' => false,
        ], 'stored_files' => [
            hash('sha256', $elf) => ['path' => 'bin/app', 'size' => strlen($elf), 'tmp' => $elfTmp],
        ]];

        $image = "hsqs".str_repeat("\0", 100);
        [$artifact, $owner] = $this->seedArtifact($image, 'rootfs.squashfs');
        $this->assertSame(ProjectArtifact::FORMAT_FILESYSTEM, $artifact->format);
        $analysis = $this->analysis($artifact, $owner, 'extract', 'extract', []);

        $evidence = (new ExtractAdapter)->analyze($analysis, $image, [], [], fn () => true);
        $validated = EvidenceValidator::validate('extract', $evidence);

        $this->assertSame('7zz-extract', $this->spool->seen[0]['profile']);
        $this->assertCount(1, $validated['extraction']['promoted']);
        $childId = $validated['extraction']['promoted'][0]['artifact_id'];
        $child = ProjectArtifact::findOrFail($childId);
        // Same project, hence same authorization boundary.
        $this->assertSame($artifact->project_id, $child->project_id);
        $this->assertSame($artifact->id, $child->tech_attributes['extracted_from']['artifact_id']);
        $this->assertSame('bin/app', $child->tech_attributes['extracted_from']['member_path']);
        $this->assertSame(hash('sha256', $elf), $child->sha256);
        $this->assertSame(ProjectArtifact::FORMAT_ELF, $child->format);
        // The child goes through the normal extraction queue.
        Queue::assertPushed(\App\Jobs\ExtractArtifactJob::class, fn ($job) => $job->artifactId === $childId);

        // Re-running links the same row instead of duplicating it.
        $elfTmp2 = tempnam(sys_get_temp_dir(), 'rev2-stored-');
        file_put_contents($elfTmp2, $elf);
        $this->spool->runs['extract']['stored_files'] = [
            hash('sha256', $elf) => ['path' => 'bin/app', 'size' => strlen($elf), 'tmp' => $elfTmp2],
        ];
        $again = (new ExtractAdapter)->analyze($analysis, $image, [], [], fn () => true);
        $this->assertSame($childId, $again['extraction']['promoted'][0]['artifact_id']);
        $this->assertContains('member_already_present', $again['warnings']);
        $this->assertSame(1, ProjectArtifact::where('project_id', $artifact->project_id)->where('sha256', hash('sha256', $elf))->count());
    }

    public function test_extract_falls_back_and_reports_profile(): void
    {
        $this->assertSame(['7zz-extract', 'unsquashfs-extract'], ExtractAdapter::profilesFor('squashfs'));
        $this->assertSame(['ubireader-extract'], ExtractAdapter::profilesFor('ubi'));
        $this->assertSame(['7zz-extract'], ExtractAdapter::profilesFor('cramfs-le'));

        $this->spool->probes['extract'] = SpoolFake::answer('extract', '1.0');
        $this->spool->failProfiles = ['7zz-extract'];
        $this->spool->runs['extract'] = ['evidence' => [
            'schema' => 1, 'tool' => 'extract', 'exit_code' => 0, 'profile' => 'unsquashfs-extract',
            'files' => [], 'inline' => [], 'truncated' => false,
        ]];

        $image = "hsqs".str_repeat("\0", 100);
        [$artifact, $owner] = $this->seedArtifact($image, 'rootfs.squashfs');
        $analysis = $this->analysis($artifact, $owner, 'extract', 'extract', []);

        $evidence = (new ExtractAdapter)->analyze($analysis, $image, [], [], fn () => true);

        $this->assertSame(['7zz-extract', 'unsquashfs-extract'], array_column($this->spool->seen, 'profile'));
        $this->assertSame('unsquashfs-extract', $evidence['extraction']['profile']);
        $this->assertContains('extraction_fallback_used', $evidence['warnings']);
        $this->assertContains('no_files_extracted', $evidence['warnings']);
    }
}
