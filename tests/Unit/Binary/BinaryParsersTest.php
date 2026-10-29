<?php

namespace Tests\Unit\Binary;

use App\Services\Binary\ApkParser;
use App\Services\Binary\ClassParser;
use App\Services\Binary\DexParser;
use App\Services\Binary\HexRecords;
use App\Services\Binary\MachOParser;
use App\Services\Binary\PeParser;
use App\Services\Binary\PrgParser;
use PHPUnit\Framework\TestCase;
use Tests\Support\BinaryFixtures;

/** Parser coverage over synthetic fixtures (provenance: BinaryFixtures). */
class BinaryParsersTest extends TestCase
{
    public function test_pe_headers_sections_and_strings(): void
    {
        $parsed = PeParser::parse(BinaryFixtures::pe()['bytes']);

        $this->assertTrue($parsed['ok']);
        $this->assertSame('x86-64', $parsed['arch']);
        $this->assertSame('PE32+', $parsed['data']['pe_type']);
        $this->assertSame('x86-64', $parsed['data']['machine']['name']);
        $this->assertSame('native', $parsed['data']['runtime']);
        $this->assertSame(0x1000, $parsed['data']['entry_rva']);
        $this->assertSame(0x200, $parsed['data']['entry_file_offset']);
        $this->assertCount(1, $parsed['data']['sections']);
        $this->assertTrue($parsed['data']['sections'][0]['executable']);
        $this->assertNotEmpty($parsed['data']['strings']);
    }

    public function test_pe_imports_and_clr_routing(): void
    {
        $native = PeParser::parse(BinaryFixtures::pe(['imports' => true])['bytes']);
        $this->assertTrue($native['ok']);
        $this->assertSame('native', $native['data']['runtime']);
        $this->assertSame('SYNTHETIC32.DLL', $native['data']['imports'][0]['dll']);
        $this->assertContains('SyntheticFunction', $native['data']['imports'][0]['functions']);

        $managed = PeParser::parse(BinaryFixtures::pe(['clr' => true])['bytes']);
        $this->assertTrue($managed['ok']);
        $this->assertSame('clr', $managed['data']['runtime']);
        $this->assertSame(0x2060, $managed['data']['clr']['rva']);
    }

    public function test_pe32_and_truncation(): void
    {
        $parsed = PeParser::parse(BinaryFixtures::pe(['machine' => 0x014C])['bytes']);
        $this->assertTrue($parsed['ok']);
        $this->assertSame('PE32', $parsed['data']['pe_type']);
        $this->assertSame('x86', $parsed['arch']);
        $this->assertSame(0x400000, $parsed['data']['image_base']);

        $truncated = PeParser::parse(substr(BinaryFixtures::pe()['bytes'], 0, 100));
        $this->assertFalse($truncated['ok']);
    }

    public function test_macho_thin_and_fat(): void
    {
        $thin = MachOParser::parse(BinaryFixtures::machoThin());
        $this->assertTrue($thin['ok']);
        $this->assertSame('x86-64', $thin['arch']);
        $this->assertSame('thin', $thin['data']['kind']);
        $this->assertSame(64, $thin['data']['bits']);
        $this->assertSame('__TEXT', $thin['data']['segments'][0]['name']);
        $this->assertContains('/syn/libX', $thin['data']['dylibs']);

        $fat = MachOParser::parse(BinaryFixtures::machoFat());
        $this->assertTrue($fat['ok']);
        $this->assertSame('fat', $fat['data']['kind']);
        $this->assertSame(1, $fat['data']['member_count']);
        $this->assertSame('x86-64', $fat['data']['members'][0]['arch']);
        $this->assertNotNull($fat['data']['first_member']);
    }

    public function test_dex_tables(): void
    {
        $parsed = DexParser::parse(BinaryFixtures::dex());

        $this->assertTrue($parsed['ok']);
        $this->assertSame('dalvik', $parsed['arch']);
        $this->assertSame(1, $parsed['data']['class_count']);
        $this->assertSame('Lcom/synth/App;', $parsed['data']['classes'][0]['type']);
        $this->assertSame(['Lcom/synth/App;->doThing'], $parsed['data']['methods']);
        $this->assertSame(2, $parsed['data']['string_count']);
    }

    public function test_apk_members_dex_and_native(): void
    {
        $parsed = ApkParser::parse(BinaryFixtures::apk(['dexes' => 2, 'so' => true]));

        $this->assertTrue($parsed['ok']);
        $this->assertSame(2, $parsed['data']['dex_count']);
        $this->assertSame(1, $parsed['data']['native_lib_count']);
        $this->assertSame('binary_axml', $parsed['data']['manifest']['kind']);
        $this->assertContains('com.synthetic.app', $parsed['data']['manifest']['strings']);
        $this->assertSame('arm64-v8a', $parsed['data']['native_libs'][0]['abi']);
        $this->assertSame('x86-64', $parsed['data']['native_libs'][0]['arch']);
        $this->assertContains('resources_arsc_not_decoded', $parsed['data']['warnings']);
    }

    public function test_apk_rejects_missing_manifest_and_flags_splits(): void
    {
        $noManifest = BinaryFixtures::zip(['classes.dex' => BinaryFixtures::dex()]);
        $parsed = ApkParser::parse($noManifest);
        $this->assertFalse($parsed['ok']);
        $this->assertSame('no_manifest', $parsed['error']);

        $split = ApkParser::parse(BinaryFixtures::apk(['split' => true]));
        $this->assertTrue($split['ok']);
        $this->assertContains('possible_split_package', $split['data']['warnings']);
        $this->assertContains('bundletool_metadata_present', $split['data']['split_markers']);
    }

    public function test_class_metadata(): void
    {
        $parsed = ClassParser::parse(BinaryFixtures::classFile());

        $this->assertTrue($parsed['ok']);
        $this->assertSame('jvm', $parsed['arch']);
        $this->assertSame('Synth', $parsed['data']['class_name']);
        $this->assertSame('java.lang.Object', $parsed['data']['super_name']);
        $this->assertSame('Java 8', $parsed['data']['version']['java']);
    }

    public function test_hex_records_checksums_segments_and_entry(): void
    {
        $hex = HexRecords::parseIntelHex(BinaryFixtures::ihex([[0x1000, 'ABCD'], [0x2000, 'EFGH']], 0x1000));
        $this->assertTrue($hex['ok']);
        $this->assertSame(0, $hex['data']['bad_checksums']);
        $this->assertSame(0x1000, $hex['data']['entry']);
        $this->assertCount(2, $hex['data']['segments']);
        $this->assertSame(0x1000, $hex['data']['min_address']);

        $srec = HexRecords::parseSrec(BinaryFixtures::srec([[0x8000, 'WXYZ']], 0x8000));
        $this->assertTrue($srec['ok']);
        $this->assertSame(0, $srec['data']['bad_checksums']);
        $this->assertSame(0x8000, $srec['data']['entry']);

        $bad = HexRecords::parseIntelHex(":100000000102030405060708090A0B0C0D0E0F1000\n:00000001FF\n");
        $this->assertTrue($bad['ok']);
        $this->assertSame(1, $bad['data']['bad_checksums']);
        $this->assertContains('bad_checksums:1', $bad['data']['warnings']);
    }

    public function test_hex_materialize_rebuilds_address_map(): void
    {
        $map = HexRecords::materialize('ihex', BinaryFixtures::ihex([[0x1000, 'ABCD'], [0x1004, 'EF']]), 1024);

        $this->assertCount(1, $map['segments']);
        $this->assertSame(0x1000, $map['segments'][0]['base']);
        $this->assertSame('ABCDEF', $map['segments'][0]['bytes']);
        $this->assertFalse($map['truncated']);
    }

    public function test_prg_basic_sys_and_payload_split(): void
    {
        $parsed = PrgParser::parse(BinaryFixtures::prgBasic());

        $this->assertTrue($parsed['ok']);
        $this->assertSame('mos6502', $parsed['arch']);
        $this->assertSame(0x0801, $parsed['data']['load_address']);
        $this->assertCount(1, $parsed['data']['basic_lines']);
        $this->assertSame(10, $parsed['data']['basic_lines'][0]['number']);
        $this->assertStringContainsString('SYS', $parsed['data']['basic_lines'][0]['text']);
        $this->assertSame([2064], $parsed['data']['sys_targets']);
        // Payload window starts after BASIC — BASIC text is not code.
        // (Two alignment pad bytes precede the ML; the SYS target maps
        // through load + offset, so the address math stays exact.)
        $this->assertGreaterThan(2, $parsed['data']['payload_offset']);
        $payload = substr(
            BinaryFixtures::prgBasic(),
            $parsed['data']['payload_offset'],
            $parsed['data']['payload_bytes']
        );
        $this->assertTrue(str_ends_with($payload, "\xA9\x00\x8D\x00\x02\x60"));
        $this->assertSame(0x0810, $parsed['data']['load_address'] + (strlen(BinaryFixtures::prgBasic()) - strlen($payload) - 2) + (strlen($payload) - 6));
    }

    public function test_prg_machine_code_only(): void
    {
        $parsed = PrgParser::parse(BinaryFixtures::prgMachineCode());

        $this->assertTrue($parsed['ok']);
        $this->assertSame(0x1000, $parsed['data']['load_address']);
        $this->assertSame([], $parsed['data']['basic_lines']);
        $this->assertSame(2, $parsed['data']['payload_offset']);
        $this->assertContains('no_basic_structure', $parsed['data']['warnings']);
    }
}
