<?php

namespace Tests\Unit\Artifacts;

use App\Services\Artifacts\ElfParser;
use PHPUnit\Framework\TestCase;

class ElfParserTest extends TestCase
{
    private static function bytes(string $name): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/Artifacts/files/'.$name);
    }

    public function test_architectures_are_identified_by_machine_not_class(): void
    {
        $this->assertSame('arm32', ElfParser::parse(self::bytes('elf32-arm.elf'))['arch']);
        $this->assertSame('aarch64', ElfParser::parse(self::bytes('elf64-aarch64.elf'))['arch']);
        $this->assertSame('x86', ElfParser::parse(self::bytes('elf32-x86.elf'))['arch']);
        $this->assertSame('x86-64', ElfParser::parse(self::bytes('elf64-x86_64.elf'))['arch']);
    }

    public function test_big_endian_is_parsed(): void
    {
        $r = ElfParser::parse(self::bytes('elf32-arm-be.elf'));

        $this->assertTrue($r['ok']);
        $this->assertSame('arm32', $r['arch']);
        $this->assertSame('big', $r['data']['endian']);
        $this->assertSame('0x1000', $r['data']['entry_point']);
    }

    public function test_headers_sections_and_segments(): void
    {
        $r = ElfParser::parse(self::bytes('elf32-arm.elf'));

        $this->assertTrue($r['ok']);
        $this->assertSame('ELF32', $r['data']['class']);
        $this->assertSame('little', $r['data']['endian']);
        $this->assertSame('EM_ARM', $r['data']['machine']['name']);
        $this->assertSame('EXEC', $r['data']['object_type']);
        $this->assertSame('0x1000', $r['data']['entry_point']);

        $names = array_column($r['data']['sections'], 'name');
        $this->assertContains('.text', $names);
        $this->assertContains('.rodata', $names);
        $this->assertContains('.symtab', $names);

        $types = array_column($r['data']['segments'], 'type');
        $this->assertContains('LOAD', $types);
        $this->assertContains('DYNAMIC', $types);

        // File offsets and virtual addresses are distinguished.
        $this->assertArrayHasKey('file_offset', $r['data']['segments'][0]);
        $this->assertArrayHasKey('virtual_address', $r['data']['segments'][0]);
    }

    public function test_symbols_and_dynamic_dependencies(): void
    {
        $r = ElfParser::parse(self::bytes('elf64-x86_64.elf'));

        $names = array_column($r['data']['symbols'], 'name', 'name');
        $this->assertArrayHasKey('main', $names);
        $this->assertArrayHasKey('puts', $names);
        $this->assertTrue($r['data']['symbols'][array_search('puts', array_column($r['data']['symbols'], 'name'))]['undefined']);
        $this->assertTrue($r['data']['dynamic']['present']);
        $this->assertContains('libc.so.6', $r['data']['dynamic']['needed']);
        $this->assertSame('fixture.so.1', $r['data']['dynamic']['soname']);
    }

    public function test_static_stripped_is_honest(): void
    {
        $r = ElfParser::parse(self::bytes('elf64-static-stripped.elf'));

        $this->assertTrue($r['ok']);
        $this->assertFalse($r['data']['dynamic']['present']);
        $this->assertContains('no_dynamic_segment', $r['data']['warnings']);
        $this->assertContains('no_symbols', $r['data']['warnings']);
    }

    public function test_unsupported_architecture_is_explicit(): void
    {
        $r = ElfParser::parse(self::bytes('elf64-mips.elf'));

        $this->assertTrue($r['ok']);
        $this->assertNull($r['arch']);

        $joined = implode(',', $r['data']['warnings']);
        $this->assertTrue(
            str_contains($joined, 'unsupported_machine') || str_contains($joined, 'unsupported_arch'),
            'Expected an explicit unsupported-machine warning.'
        );
    }

    public function test_strings_carry_correct_file_offsets(): void
    {
        $raw = self::bytes('elf32-arm.elf');
        $r = ElfParser::parse($raw);

        $found = null;
        foreach ($r['data']['strings'] as $s) {
            if (str_contains($s['value'], 'updates.example.com')) {
                $found = $s;

                break;
            }
        }

        $this->assertNotNull($found);
        $this->assertSame(strpos($raw, 'https://updates.example.com/fw'), $found['offset']);
        $this->assertSame('ascii-printable', $found['encoding']);
    }

    public function test_endpoints_are_labeled_candidates(): void
    {
        $r = ElfParser::parse(self::bytes('elf32-arm.elf'));
        $kinds = array_column($r['data']['endpoints'], 'kind', 'value');

        $this->assertArrayHasKey('https://updates.example.com/fw', $kinds);
        $this->assertSame('url', $kinds['https://updates.example.com/fw']);
        $this->assertContains('protocol_url', $kinds);
    }

    public function test_truncated_and_malformed_files_fail_safely(): void
    {
        $truncated = ElfParser::parse(self::bytes('elf64-truncated.elf'));
        $this->assertFalse($truncated['ok']);
        $this->assertSame('truncated_header', $truncated['error']);

        $bad = ElfParser::parse(self::bytes('elf32-badsection.elf'));
        $this->assertTrue($bad['ok']);
        $this->assertContains('section_out_of_bounds:1', $bad['data']['warnings']);
        $this->assertFalse($bad['data']['sections'][1]['in_bounds']);

        $nope = ElfParser::parse(self::bytes('not-elf.bin'));
        $this->assertFalse($nope['ok']);
        $this->assertSame('not_elf', $nope['error']);
    }

    public function test_string_limits_are_reported(): void
    {
        $r = ElfParser::parse(self::bytes('elf32-arm.elf'), ['max_strings' => 2]);

        $this->assertTrue($r['ok']);
        $this->assertCount(2, $r['data']['strings']);
        $this->assertTrue($r['data']['string_stats']['truncated']);
    }
}
