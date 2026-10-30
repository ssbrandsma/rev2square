<?php

namespace Tests\Unit\Binary;

use App\Models\ProjectArtifact;
use App\Services\Binary\Adapters\AdapterRegistry;
use App\Services\Binary\Adapters\Capability;
use App\Services\Binary\Adapters\ExtractAdapter;
use App\Services\Binary\Adapters\GhidraAdapter;
use App\Services\Binary\Adapters\IlspyAdapter;
use App\Services\Binary\Adapters\JadxAdapter;
use App\Services\Binary\Adapters\ObjdumpAdapter;
use App\Services\Binary\Adapters\PhpNativeAdapter;
use Tests\TestCase;

/** Adapter registry, capability honesty, and subprocess argv safety. */
class AdaptersTest extends TestCase
{
    public function test_registry_lists_all_backends(): void
    {
        $ids = array_map(fn ($a) => $a->id(), AdapterRegistry::all());

        $this->assertSame(['php-native', 'objdump', 'ghidra', 'jadx', 'ilspy', 'extract'], $ids);
        $this->assertInstanceOf(PhpNativeAdapter::class, AdapterRegistry::get('php-native'));
        $this->assertNull(AdapterRegistry::get('nope'));
    }

    public function test_native_capabilities_by_target(): void
    {
        $adapter = new PhpNativeAdapter;

        $native = $adapter->capabilities(ProjectArtifact::FORMAT_RAW, 'z80');
        $this->assertSame(Capability::SUPPORTED, $native[Capability::DISASSEMBLY]);
        $this->assertSame(Capability::PARTIAL, $native[Capability::FUNCTIONS]);
        $this->assertSame(Capability::UNSUPPORTED, $native[Capability::DECOMPILATION]);
        $this->assertSame(Capability::SUPPORTED, $native[Capability::AI_EXPLAIN]);

        $foreign = $adapter->capabilities(ProjectArtifact::FORMAT_ELF, 'x86-64');
        $this->assertSame(Capability::DEPENDENCY_UNAVAILABLE, $foreign[Capability::DISASSEMBLY]);
        $this->assertSame(Capability::SUPPORTED, $foreign[Capability::METADATA]);

        $unconfigured = $adapter->capabilities(ProjectArtifact::FORMAT_RAW, null);
        $this->assertSame(Capability::CONFIGURATION_REQUIRED, $unconfigured[Capability::DISASSEMBLY]);

        $capture = $adapter->capabilities(ProjectArtifact::FORMAT_PCAP, null);
        $this->assertSame(Capability::UNSUPPORTED, $capture[Capability::DISASSEMBLY]);
    }

    public function test_sandboxed_backends_report_unavailable_without_spool(): void
    {
        // No spool volumes are mounted in the test container: every
        // sandboxed backend reports unavailable (fast, no round-trip).
        config([
            'artifacts.binary_spool_dir' => sys_get_temp_dir().'/rev2-no-spool-'.uniqid(),
            'artifacts.binary_results_dir' => sys_get_temp_dir().'/rev2-no-results-'.uniqid(),
        ]);
        foreach ([new GhidraAdapter, new JadxAdapter, new IlspyAdapter, new ObjdumpAdapter, new ExtractAdapter] as $adapter) {
            $probe = $adapter->probe();
            $this->assertFalse($probe['available']);
            $this->assertNotSame('', $probe['reason']);
            $this->assertSame([], $adapter->targets());
            $this->assertNull($adapter->version());
        }

        // Capability maps describe the pair, not availability: a
        // verified pair reads SUPPORTED even while the runner is down
        // (the matrix ANDs this with worker verification).
        $ghidra = new GhidraAdapter;
        $caps = $ghidra->capabilities(ProjectArtifact::FORMAT_ELF, 'x86-64');
        $this->assertSame(Capability::SUPPORTED, $caps[Capability::DISASSEMBLY]);
        $this->assertSame(Capability::SUPPORTED, $caps[Capability::DECOMPILATION]);
        $this->assertSame(Capability::UNSUPPORTED, $ghidra->capabilities(ProjectArtifact::FORMAT_APK, 'dalvik')[Capability::DISASSEMBLY]);
        // Documented-but-unverified language: not routable.
        $this->assertSame(Capability::DEPENDENCY_UNAVAILABLE, $ghidra->capabilities(ProjectArtifact::FORMAT_ELF, 'mips32')[Capability::DISASSEMBLY]);

        $jadx = new JadxAdapter;
        $jadxCaps = $jadx->capabilities(ProjectArtifact::FORMAT_APK, 'dalvik');
        $this->assertSame(Capability::UNSUPPORTED, $jadxCaps[Capability::DISASSEMBLY]);
        $this->assertSame(Capability::SUPPORTED, $jadxCaps[Capability::DECOMPILATION]);
        $this->assertTrue(Capability::requestable($jadxCaps));

        $ilspy = new IlspyAdapter;
        $this->assertSame(Capability::UNSUPPORTED, $ilspy->capabilities(ProjectArtifact::FORMAT_PE, 'clr')[Capability::DISASSEMBLY]);
        $this->assertSame(Capability::SUPPORTED, $ilspy->capabilities(ProjectArtifact::FORMAT_PE, 'clr')[Capability::DECOMPILATION]);
        $this->assertSame(Capability::UNSUPPORTED, $ilspy->capabilities(ProjectArtifact::FORMAT_PE, 'x86-64')[Capability::DISASSEMBLY]);
    }

    public function test_ghidra_command_documents_runner_contract(): void
    {
        // buildCommand documents the runner-side argv shape (runner
        // paths, verified languages only). Enforcement lives in the
        // runner, which takes no paths from the job spec at all; the
        // conformance tests assert both sides emit the same argv.
        $adapter = new GhidraAdapter;
        $jobDir = sys_get_temp_dir().'/ghidra-cmd-'.uniqid();

        $valid = [
            'job_dir' => $jobDir, 'input' => $jobDir.'/input.bin',
            'language' => 'x86:LE:32:default', 'project' => $jobDir.'/proj',
            'script' => 'ExportEvidence.java', 'output' => $jobDir.'/out',
            'base' => 0x8000,
        ];
        $command = $adapter->buildCommand($valid);
        $this->assertIsArray($command);
        $this->assertSame('/opt/ghidra/support/analyzeHeadless', $command[0]);
        $this->assertContains('-processor', $command);
        $this->assertContains('x86:LE:32:default', $command);
        $this->assertContains('ExportEvidence.java', $command);
        $this->assertStringNotContainsString(';', implode(' ', $command));

        // Unknown language id rejected (incl. documented-but-unverified).
        $this->assertNull($adapter->buildCommand([...$valid, 'language' => 'x86:LE:32:default; rm -rf /']));
        $this->assertNull($adapter->buildCommand([...$valid, 'language' => 'MIPS:LE:32:default']));
        // AVR has no built-in Ghidra language and must never route.
        $this->assertNull($adapter->buildCommand([...$valid, 'language' => 'AVR:LE:16:default']));
        // Non-fixed script rejected.
        $this->assertNull($adapter->buildCommand([...$valid, 'script' => 'Evil.java']));
        // Missing keys rejected.
        $this->assertNull($adapter->buildCommand(['job_dir' => $jobDir]));
    }

    public function test_jadx_and_ilspy_command_validation(): void
    {
        $jobDir = sys_get_temp_dir().'/jadx-cmd-'.uniqid();

        $jadx = new JadxAdapter;
        $command = $jadx->buildCommand(['job_dir' => $jobDir, 'input' => $jobDir.'/input.apk', 'output' => $jobDir.'/out', 'threads' => 2]);
        $this->assertSame(
            ['/opt/jadx/bin/jadx', '--no-src-save-cache', '-j', '2', '-d', $jobDir.'/out', $jobDir.'/input.apk'],
            $command
        );
        $this->assertNull($jadx->buildCommand(['job_dir' => $jobDir, 'input' => $jobDir.'/input.apk', 'output' => $jobDir.'/out', 'threads' => 99]));
        $this->assertNull($jadx->buildCommand(['job_dir' => $jobDir, 'input' => $jobDir.'/input.apk']));

        $ilspy = new IlspyAdapter;
        $command = $ilspy->buildCommand(['job_dir' => $jobDir, 'input' => $jobDir.'/input.exe', 'output' => $jobDir.'/out']);
        $this->assertSame(
            ['/opt/ilspy/ilspycmd', '-p', '-o', $jobDir.'/out', $jobDir.'/input.exe'],
            $command
        );
        $this->assertNull($ilspy->buildCommand(['job_dir' => $jobDir, 'input' => $jobDir.'/input.exe']));
    }
}
