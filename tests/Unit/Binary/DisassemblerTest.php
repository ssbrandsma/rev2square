<?php

namespace Tests\Unit\Binary;

use App\Services\Binary\Disasm\I8051Decoder;
use App\Services\Binary\Disasm\LinearSweep;
use App\Services\Binary\Disasm\Mos6502Decoder;
use App\Services\Binary\Disasm\Z80Decoder;
use PHPUnit\Framework\TestCase;

/**
 * Hand-assembled golden vectors. Every byte sequence below was
 * written for this test (provenance: this file) against the
 * published instruction encodings — no downloaded fixtures.
 */
class DisassemblerTest extends TestCase
{
    public function test_mos6502_golden_vector(): void
    {
        $bytes = hex2bin('A9008D00024C0010D0FE20002060');
        $sweep = LinearSweep::sweep(new Mos6502Decoder, $bytes, 0x1000, 0, 100, 100);

        $texts = array_column($sweep['lines'], 'text');
        $this->assertSame(['LDA #$00', 'STA $0200', 'JMP $1000', 'BNE $1008', 'JSR $2000', 'RTS'], $texts);
        $this->assertSame([0x1000, 0x1002, 0x1005, 0x1008, 0x100A, 0x100D], array_column($sweep['lines'], 'address'));
        $this->assertSame(6, $sweep['instructions']);
        $this->assertSame(0, $sweep['data_bytes']);

        $this->assertSame([
            ['from' => 0x1005, 'to' => 0x1000, 'kind' => 'jump'],
            ['from' => 0x1008, 'to' => 0x1008, 'kind' => 'branch'],
            ['from' => 0x100A, 'to' => 0x2000, 'kind' => 'call'],
        ], $sweep['xrefs']);
    }

    public function test_mos6502_illegal_opcode_is_data(): void
    {
        $sweep = LinearSweep::sweep(new Mos6502Decoder, hex2bin('02A900'), 0, 0, 100, 100);

        $this->assertSame('.db $02', $sweep['lines'][0]['text']);
        $this->assertSame('LDA #$00', $sweep['lines'][1]['text']);
        $this->assertSame(1, $sweep['data_bytes']);
    }

    public function test_i8051_golden_vector(): void
    {
        $bytes = hex2bin('744290123480FE123456D530FC22');
        $sweep = LinearSweep::sweep(new I8051Decoder, $bytes, 0x0000, 0, 100, 100);

        $texts = array_column($sweep['lines'], 'text');
        $this->assertSame([
            'MOV A,#$42',
            'MOV DPTR,#$1234',
            'SJMP $0005',
            'LCALL $3456',
            'DJNZ $30,$0009',
            'RETI',
        ], $texts);
        $this->assertSame(6, $sweep['instructions']);
    }

    public function test_i8051_reserved_opcode_is_data(): void
    {
        $sweep = LinearSweep::sweep(new I8051Decoder, hex2bin('A500'), 0, 0, 100, 100);

        $this->assertSame('.db $A5', $sweep['lines'][0]['text']);
        $this->assertSame('NOP', $sweep['lines'][1]['text']);
    }

    public function test_z80_golden_vector(): void
    {
        $bytes = hex2bin('3E42C30010CD002018FEDD210040CB06ED4476C9');
        $sweep = LinearSweep::sweep(new Z80Decoder, $bytes, 0x0000, 0, 100, 100);

        $texts = array_column($sweep['lines'], 'text');
        $this->assertSame([
            'LD A,$42',
            'JP $1000',
            'CALL $2000',
            'JR $0008',
            'LD IX,$4000',
            'RLC (HL)',
            'NEG',
            'HALT',
            'RET',
        ], $texts);
        $this->assertSame(9, $sweep['instructions']);
        $this->assertSame([
            ['from' => 0x0002, 'to' => 0x1000, 'kind' => 'jump'],
            ['from' => 0x0005, 'to' => 0x2000, 'kind' => 'call'],
            ['from' => 0x0008, 'to' => 0x0008, 'kind' => 'jump'],
        ], $sweep['xrefs']);
    }

    public function test_z80_indexed_and_bit_instructions(): void
    {
        $bytes = hex2bin('DD8605FD3610AACB47DDCB058F');
        $sweep = LinearSweep::sweep(new Z80Decoder, $bytes, 0x1000, 0, 100, 100);

        $texts = array_column($sweep['lines'], 'text');
        $this->assertSame([
            'ADD A,(IX+$05)',
            'LD (IY+$10),$AA',
            'BIT 0,A',
            'RES 1,(IX+$05),A',
        ], $texts);
        $this->assertSame([3, 4, 2, 4], array_map(fn ($l) => strlen($l['bytes']) / 2, $sweep['lines']));
    }

    public function test_sweep_respects_bounds_and_truncation(): void
    {
        $sweep = LinearSweep::sweep(new Mos6502Decoder, hex2bin('A9'), 0, 0, 100, 100);
        $this->assertSame('.db $A9', $sweep['lines'][0]['text']);

        $sweep = LinearSweep::sweep(new Mos6502Decoder, hex2bin('EAEAEA'), 0, 0, 2, 100);
        $this->assertTrue($sweep['truncated']);
        $this->assertCount(2, $sweep['lines']);
    }
}
