<?php

namespace Tests\Unit\Binary;

use App\Models\ProjectArtifact;
use App\Services\Binary\Adapters\ExtractAdapter;
use App\Services\Binary\FormatDetector;
use App\Services\Binary\Targets;
use Tests\TestCase;

/** Filesystem-image detection, flavors, and extraction routing. */
class FilesystemDetectionTest extends TestCase
{
    public function test_magic_maps_to_filesystem_flavors(): void
    {
        $this->assertSame('cramfs-le', FormatDetector::filesystemFlavor("\x45\x3d\xcd\x28"));
        $this->assertSame('cramfs-be', FormatDetector::filesystemFlavor("\x28\xcd\x3d\x45"));
        $this->assertSame('squashfs', FormatDetector::filesystemFlavor('hsqs'));
        $this->assertSame('squashfs', FormatDetector::filesystemFlavor('sqsh'));
        $this->assertSame('ubi', FormatDetector::filesystemFlavor('UBI#'));
        $this->assertSame('ubifs', FormatDetector::filesystemFlavor("\x31\x18\x10\x06"));
        $this->assertNull(FormatDetector::filesystemFlavor('PK'.chr(3).chr(4)));
        $this->assertNull(FormatDetector::filesystemFlavor("\x7fELF"));
    }

    public function test_detect_reports_filesystem_with_magic_evidence(): void
    {
        $found = FormatDetector::detect("hsqs".str_repeat("\0", 64), 'rootfs.bin');
        $this->assertSame(ProjectArtifact::FORMAT_FILESYSTEM, $found['format']);
        $this->assertStringContainsString('squashfs', json_encode($found['evidence']));

        $cramfs = FormatDetector::detect("\x45\x3d\xcd\x28".str_repeat("\0", 64), 'fw.bin');
        $this->assertSame(ProjectArtifact::FORMAT_FILESYSTEM, $cramfs['format']);

        // Declared-raw firmware with fs magic is classified as what it
        // is (never forced into a CPU target).
        $declaredRaw = FormatDetector::detect("\x45\x3d\xcd\x28".str_repeat("\0", 64), 'fw.bin', ['declared' => 'raw']);
        $this->assertSame(ProjectArtifact::FORMAT_FILESYSTEM, $declaredRaw['format']);
    }

    public function test_extract_target_resolves_structurally_for_filesystems(): void
    {
        $candidates = Targets::candidatesFor(ProjectArtifact::FORMAT_FILESYSTEM, null, []);
        $this->assertSame('extract', $candidates[0]['id']);
        $this->assertSame('structural', $candidates[0]['source']);
        $this->assertTrue(Targets::exists('extract'));
        $this->assertFalse(Targets::get('extract')['native']);
    }

    public function test_extract_adapter_only_serves_filesystem_extract_pair(): void
    {
        $adapter = new ExtractAdapter;
        $this->assertSame(['filesystem'], $adapter->formats());
        $caps = $adapter->capabilities(ProjectArtifact::FORMAT_FILESYSTEM, 'extract');
        $this->assertSame(\App\Services\Binary\Adapters\Capability::SUPPORTED, $caps[\App\Services\Binary\Adapters\Capability::EXTRACTION]);
        $this->assertTrue(\App\Services\Binary\Adapters\Capability::requestable($caps));
        // CPU targets never apply to the image itself.
        $this->assertSame(
            \App\Services\Binary\Adapters\Capability::UNSUPPORTED,
            $adapter->capabilities(ProjectArtifact::FORMAT_FILESYSTEM, 'arm32')[\App\Services\Binary\Adapters\Capability::EXTRACTION]
        );
    }

    public function test_extract_build_command_covers_all_profiles(): void
    {
        $adapter = new ExtractAdapter;
        $this->assertSame(
            ['/opt/7zz/7zz', 'x', '-y', '-oOUT', 'IN'],
            $adapter->buildCommand(['job_dir' => 'J', 'input' => 'IN', 'output' => 'OUT', 'profile' => '7zz-extract'])
        );
        $this->assertSame(
            ['/usr/bin/unsquashfs', '-d', 'OUT', '-f', 'IN'],
            $adapter->buildCommand(['job_dir' => 'J', 'input' => 'IN', 'output' => 'OUT', 'profile' => 'unsquashfs-extract'])
        );
        $this->assertSame(
            ['/usr/local/bin/ubireader_extract_files', '-o', 'OUT', 'IN'],
            $adapter->buildCommand(['job_dir' => 'J', 'input' => 'IN', 'output' => 'OUT', 'profile' => 'ubireader-extract'])
        );
        $this->assertNull($adapter->buildCommand(['job_dir' => 'J', 'input' => 'IN', 'output' => 'OUT', 'profile' => 'nope']));
    }
}
