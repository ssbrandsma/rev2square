<?php

namespace Tests\Unit\Binary;

use App\Models\ProjectArtifact;
use App\Services\Binary\FormatDetector;
use PHPUnit\Framework\TestCase;
use Tests\Support\BinaryFixtures;

/** Content-based detection: magic wins, extensions are hints at most. */
class FormatDetectionTest extends TestCase
{
    public function test_pe_detection_needs_signature(): void
    {
        $pe = BinaryFixtures::pe()['bytes'];
        $this->assertSame(ProjectArtifact::FORMAT_PE, FormatDetector::format($pe, 'prog.bin'));

        $report = FormatDetector::detect($pe);
        $claims = array_column($report['evidence'], 'claim');
        $this->assertContains('magic', $claims);
        $this->assertContains('pe_signature', $claims);
    }

    public function test_bare_mz_is_not_pe(): void
    {
        $report = FormatDetector::detect('MZ'.str_repeat("\x00", 100), 'dos.exe');

        $this->assertSame(ProjectArtifact::FORMAT_UNKNOWN, $report['format']);
        $this->assertContains('mz_without_pe_signature', $report['warnings']);
    }

    public function test_cafebabe_disambiguation(): void
    {
        $this->assertSame(ProjectArtifact::FORMAT_MACHO, FormatDetector::format(BinaryFixtures::machoFat()));
        $this->assertSame(ProjectArtifact::FORMAT_CLASS, FormatDetector::format(BinaryFixtures::classFile()));

        // CAFEBABE with neither a valid FAT entry nor a class version.
        $report = FormatDetector::detect("\xCA\xFE\xBA\xBE\xFF\xFF\xFF\xFF".str_repeat("\x00", 64));
        $this->assertSame(ProjectArtifact::FORMAT_UNKNOWN, $report['format']);
        $this->assertContains('ambiguous_cafebabe', $report['warnings']);
    }

    public function test_macho_thin_and_dex(): void
    {
        $this->assertSame(ProjectArtifact::FORMAT_MACHO, FormatDetector::format(BinaryFixtures::machoThin()));
        $this->assertSame(ProjectArtifact::FORMAT_DEX, FormatDetector::format(BinaryFixtures::dex()));
    }

    public function test_apk_jar_and_zip_kinds(): void
    {
        $apk = BinaryFixtures::apk();
        $this->assertSame(ProjectArtifact::FORMAT_APK, FormatDetector::format($apk, 'app.renamed'));

        $report = FormatDetector::detect($apk);
        $claims = array_column($report['evidence'], 'claim');
        $this->assertContains('android_manifest', $claims);
        $this->assertContains('dex_members', $claims);

        $jar = BinaryFixtures::zip(['META-INF/MANIFEST.MF' => 'x', 'com/a/B.class' => 'y']);
        $report = FormatDetector::detect($jar, 'lib.zip');
        $this->assertSame(ProjectArtifact::FORMAT_ARCHIVE, $report['format']);
        $this->assertStringContainsString('JAR-style', $report['evidence'][0]['detail']);

        $zip = BinaryFixtures::zip();
        $this->assertSame(ProjectArtifact::FORMAT_ARCHIVE, FormatDetector::format($zip));
    }

    public function test_hex_formats_and_malformed_lookalikes(): void
    {
        $this->assertSame(ProjectArtifact::FORMAT_IHEX, FormatDetector::format(BinaryFixtures::ihex()));
        $this->assertSame(ProjectArtifact::FORMAT_SREC, FormatDetector::format(BinaryFixtures::srec()));

        $report = FormatDetector::detect(":ZZZ-not-hex\n:GGG\n");
        $this->assertSame(ProjectArtifact::FORMAT_UNKNOWN, $report['format']);

        $report = FormatDetector::detect("S9GARBAGE\n");
        $this->assertSame(ProjectArtifact::FORMAT_UNKNOWN, $report['format']);
    }

    public function test_prg_basic_structure_vs_filename_hint(): void
    {
        // BASIC structure: recognized regardless of filename.
        $basic = BinaryFixtures::prgBasic();
        $report = FormatDetector::detect($basic, 'game.bin');
        $this->assertSame(ProjectArtifact::FORMAT_PRG, $report['format']);
        $this->assertContains('basic_structure', array_column($report['evidence'], 'claim'));

        // ML-only: filename hint required, and labelled as a hint.
        $ml = BinaryFixtures::prgMachineCode();
        $this->assertSame(ProjectArtifact::FORMAT_UNKNOWN, FormatDetector::format($ml, 'dump.bin'));
        $report = FormatDetector::detect($ml, 'demo.prg');
        $this->assertSame(ProjectArtifact::FORMAT_PRG, $report['format']);
        $this->assertContains('filename_hint', array_column($report['evidence'], 'claim'));
    }

    public function test_prg_rejects_implausible_load_address(): void
    {
        $this->assertSame(ProjectArtifact::FORMAT_UNKNOWN, FormatDetector::format("\x00\x00A9\x00\x60", 'x.prg'));
    }

    public function test_class_detection(): void
    {
        $report = FormatDetector::detect(BinaryFixtures::classFile());
        $this->assertSame(ProjectArtifact::FORMAT_CLASS, $report['format']);
        $this->assertStringContainsString('52.0', $report['evidence'][0]['detail']);
    }

    public function test_elf_and_captures_still_detect(): void
    {
        $elf = (string) file_get_contents(__DIR__.'/../../Fixtures/Artifacts/files/elf64-x86_64.elf');
        $this->assertSame(ProjectArtifact::FORMAT_ELF, FormatDetector::format($elf));

        $pcap = (string) file_get_contents(__DIR__.'/../../Fixtures/Artifacts/files/capture-mixed.pcap');
        $this->assertSame(ProjectArtifact::FORMAT_PCAP, FormatDetector::format($pcap));

        $text = (string) file_get_contents(__DIR__.'/../../Fixtures/Artifacts/files/not-elf.bin');
        $this->assertSame(ProjectArtifact::FORMAT_UNKNOWN, FormatDetector::format($text, 'notes.txt'));
    }
}
