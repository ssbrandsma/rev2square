<?php

namespace Tests\Unit\Binary;

use App\Models\ProjectArtifact;
use App\Services\Binary\Adapters\AdapterRegistry;
use App\Services\Binary\Adapters\Capability;
use App\Services\Binary\Adapters\GhidraAdapter;
use App\Services\Binary\Adapters\IlspyAdapter;
use App\Services\Binary\Adapters\JadxAdapter;
use App\Services\Binary\Adapters\PhpNativeAdapter;
use Tests\TestCase;

/** Adapter registry, capability honesty, and subprocess argv safety. */
class AdaptersTest extends TestCase
{
    public function test_registry_lists_all_backends(): void
    {
        $ids = array_map(fn ($a) => $a->id(), AdapterRegistry::all());

        $this->assertSame(['php-native', 'ghidra', 'jadx', 'ilspy'], $ids);
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

    public function test_external_backends_report_unavailable_without_binaries(): void
    {
        foreach ([new GhidraAdapter, new JadxAdapter, new IlspyAdapter] as $adapter) {
            $probe = $adapter->probe();
            $this->assertFalse($probe['available']);
            $this->assertNotSame('', $probe['reason']);
            $this->assertSame([], $adapter->targets());
            $this->assertNotEmpty($adapter->candidateTargets());
        }

        $ghidra = new GhidraAdapter;
        $caps = $ghidra->capabilities(ProjectArtifact::FORMAT_ELF, 'x86-64');
        $this->assertSame(Capability::DEPENDENCY_UNAVAILABLE, $caps[Capability::DISASSEMBLY]);
        $this->assertSame(Capability::UNSUPPORTED, $ghidra->capabilities(ProjectArtifact::FORMAT_APK, 'dalvik')[Capability::DISASSEMBLY]);

        $jadx = new JadxAdapter;
        $this->assertSame(Capability::DEPENDENCY_UNAVAILABLE, $jadx->capabilities(ProjectArtifact::FORMAT_APK, 'dalvik')[Capability::DISASSEMBLY]);

        $ilspy = new IlspyAdapter;
        $this->assertSame(Capability::DEPENDENCY_UNAVAILABLE, $ilspy->capabilities(ProjectArtifact::FORMAT_PE, 'clr')[Capability::DISASSEMBLY]);
        $this->assertSame(Capability::UNSUPPORTED, $ilspy->capabilities(ProjectArtifact::FORMAT_PE, 'x86-64')[Capability::DISASSEMBLY]);
    }

    public function test_ghidra_command_rejects_unvalidated_inputs(): void
    {
        $adapter = new GhidraAdapter;
        $jobDir = sys_get_temp_dir().'/ghidra-cmd-'.uniqid();
        mkdir($jobDir);
        file_put_contents($jobDir.'/input.bin', 'x');
        mkdir($jobDir.'/proj');
        mkdir($jobDir.'/out');

        // No binary configured → no command at all.
        $this->assertNull($adapter->buildCommand([
            'job_dir' => $jobDir, 'input' => $jobDir.'/input.bin',
            'language' => 'x86:LE:32:default', 'project' => $jobDir.'/proj',
            'script' => 'ExportEvidence.java', 'output' => $jobDir.'/out',
        ]));

        // Point at a fake executable to test validation layers.
        $fake = $jobDir.'/analyzeHeadless';
        file_put_contents($fake, "#!/bin/sh\necho 11.0\n");
        chmod($fake, 0755);
        config(['artifacts.ghidra_headless' => $fake]);

        $valid = [
            'job_dir' => $jobDir, 'input' => $jobDir.'/input.bin',
            'language' => 'x86:LE:32:default', 'project' => $jobDir.'/proj',
            'script' => 'ExportEvidence.java', 'output' => $jobDir.'/out',
        ];
        $command = $adapter->buildCommand($valid);
        $this->assertIsArray($command);
        $this->assertSame($fake, $command[0]);
        $this->assertContains('-processor', $command);
        $this->assertContains('x86:LE:32:default', $command);
        $this->assertStringNotContainsString(';', implode(' ', $command));

        // Unknown language id rejected.
        $this->assertNull($adapter->buildCommand([...$valid, 'language' => 'x86:LE:32:default; rm -rf /']));
        // Non-fixed script rejected.
        $this->assertNull($adapter->buildCommand([...$valid, 'script' => 'Evil.java']));
        // Path escape rejected.
        $this->assertNull($adapter->buildCommand([...$valid, 'input' => '/etc/passwd']));
        // Thread of missing keys rejected.
        $this->assertNull($adapter->buildCommand(['job_dir' => $jobDir]));
    }

    public function test_jadx_and_ilspy_command_validation(): void
    {
        $jobDir = sys_get_temp_dir().'/jadx-cmd-'.uniqid();
        mkdir($jobDir);
        file_put_contents($jobDir.'/input.apk', 'x');
        mkdir($jobDir.'/out');

        $jadx = new JadxAdapter;
        $this->assertNull($jadx->buildCommand(['job_dir' => $jobDir, 'input' => $jobDir.'/input.apk', 'output' => $jobDir.'/out']));

        $fake = $jobDir.'/jadx';
        file_put_contents($fake, "#!/bin/sh\necho 1.5.0\n");
        chmod($fake, 0755);
        config(['artifacts.jadx_bin' => $fake]);

        $command = $jadx->buildCommand(['job_dir' => $jobDir, 'input' => $jobDir.'/input.apk', 'output' => $jobDir.'/out', 'threads' => 2]);
        $this->assertIsArray($command);
        $this->assertNull($jadx->buildCommand(['job_dir' => $jobDir, 'input' => $jobDir.'/input.apk', 'output' => $jobDir.'/out', 'threads' => 99]));
        $this->assertNull($jadx->buildCommand(['job_dir' => $jobDir, 'input' => '/etc/passwd', 'output' => $jobDir.'/out']));

        $ilspy = new IlspyAdapter;
        $fakeIl = $jobDir.'/ilspycmd';
        file_put_contents($fakeIl, "#!/bin/sh\necho 9.0\n");
        chmod($fakeIl, 0755);
        config(['artifacts.ilspy_bin' => $fakeIl]);

        $command = $ilspy->buildCommand(['job_dir' => $jobDir, 'input' => $jobDir.'/input.apk', 'output' => $jobDir.'/out']);
        $this->assertIsArray($command);
        $this->assertNull($ilspy->buildCommand(['job_dir' => $jobDir, 'input' => $jobDir.'/../escape', 'output' => $jobDir.'/out']));
    }
}
