<?php

namespace Tests\Unit\Binary;

use PHPUnit\Framework\TestCase;

/**
 * The committed DEX smoke fixture must stay a structurally valid
 * Dalvik file: the jadx runner decompiles it on every capability
 * probe, and a corrupt fixture would fail jadx closed everywhere.
 * Mirrors fixtures/gen_dex_smoke.py::verify without executing it.
 */
class DexSmokeFixtureTest extends TestCase
{
    private function fixture(): string
    {
        $path = __DIR__.'/../../../docker/analysis/fixtures/dex-smoke.dex';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function adler32(string $bytes): int
    {
        $a = 1;
        $b = 0;
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $a = ($a + ord($bytes[$i])) % 65521;
            $b = ($b + $a) % 65521;
        }

        return ($b << 16) | $a;
    }

    public function test_fixture_header_and_integrity(): void
    {
        $blob = $this->fixture();

        $this->assertSame("dex\n035\x00", substr($blob, 0, 8));
        $header = unpack('Vfile_size/Vheader_size/Vendian', substr($blob, 0x20, 12));
        $this->assertSame(strlen($blob), $header['file_size']);
        $this->assertSame(0x70, $header['header_size']);
        $this->assertSame(0x12345678, $header['endian']);
        $this->assertSame($this->adler32(substr($blob, 12)), unpack('V', substr($blob, 8, 4))[1]);
        $this->assertSame(sha1(substr($blob, 32), true), substr($blob, 12, 20));
    }

    public function test_fixture_structure(): void
    {
        $blob = $this->fixture();

        $sections = unpack('Vstring_count/Vstring_off/Vtype_count/Vtype_off', substr($blob, 0x38, 16));
        $this->assertSame(2, $sections['string_count']);
        $this->assertSame(2, $sections['type_count']);
        $coff = unpack('V', substr($blob, 0x64, 4))[1];
        $class = unpack('Vclass_idx/Vaccess/Vsuper/Vifaces/Vsource/Vannotations/Vclass_data/Vstatic', substr($blob, $coff, 32));
        $this->assertSame([0, 0x1, 1], [$class['class_idx'], $class['access'], $class['super']]);
        $this->assertSame("\0\0\0\0", substr($blob, $class['class_data'], 4));

        $s0 = unpack('V', substr($blob, $sections['string_off'], 4))[1];
        $len = ord($blob[$s0]);
        $this->assertSame('Lcom/rev2square/FixtureSmoketest;', substr($blob, $s0 + 1, $len));

        $mapOff = unpack('V', substr($blob, 0x34, 4))[1];
        $this->assertSame(7, unpack('V', substr($blob, $mapOff, 4))[1]);
        $this->assertSame(strlen($blob), $mapOff + 4 + 7 * 12);
    }
}
