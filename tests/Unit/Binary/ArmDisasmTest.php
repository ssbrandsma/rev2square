<?php

namespace Tests\Unit\Binary;

use App\Services\Binary\Disasm\Arm32Decoder;
use App\Services\Binary\Disasm\LinearSweep;
use App\Services\Binary\Disasm\ThumbDecoder;
use Tests\TestCase;

/**
 * ARM A32 + Thumb T32 decoder unit tests. Every expectation is a
 * hand-verified encoding (canonical opcodes, computed branch targets):
 * the suite pins exact text, lengths, kinds and xref targets, plus
 * honest .word/.short/data fallbacks for uncovered encodings.
 */
class ArmDisasmTest extends TestCase
{
    public function test_arm32_data_processing_immediate(): void
    {
        $decoder = new Arm32Decoder;

        $mov = $decoder->decode("\x01\x00\xA0\xE3", 0, 0x8000);
        $this->assertSame(4, $mov['length']);
        $this->assertSame('mov r0, #1', $mov['text']);
        $this->assertSame('code', $mov['kind']);

        // 0x01 rotated right by 8 = 0x01000000 (rotate field 0x4).
        $rot = $decoder->decode("\x01\x04\xA0\xE3", 0, 0x8000);
        $this->assertSame('mov r0, #0x1000000', $rot['text']);

        $subne = $decoder->decode("\x02\x20\x42\x10", 0, 0x8000);
        $this->assertSame('subne r2, r2, r2', $subne['text']);
    }

    public function test_arm32_data_processing_register_shifted(): void
    {
        $decoder = new Arm32Decoder;

        $mov = $decoder->decode("\x02\x12\xA0\xE1", 0, 0x8000);
        $this->assertSame('mov r1, r2, lsl #4', $mov['text']);

        // LSL #0 prints as a plain register move.
        $plain = $decoder->decode("\x02\x00\xA0\xE1", 0, 0x8000);
        $this->assertSame('mov r0, r2', $plain['text']);
    }

    public function test_arm32_branches_carry_absolute_targets(): void
    {
        $decoder = new Arm32Decoder;

        $b = $decoder->decode("\x00\x00\x00\xEA", 0, 0x8000);
        $this->assertSame('b 0x8008', $b['text']);
        $this->assertSame('jump', $b['kind']);
        $this->assertSame(0x8008, $b['target']);

        $bl = $decoder->decode("\x00\x00\x00\xEB", 0, 0x8000);
        $this->assertSame('bl 0x8008', $bl['text']);
        $this->assertSame('call', $bl['kind']);
        $this->assertSame(0x8008, $bl['target']);

        $back = $decoder->decode("\xFF\xFF\xFF\xEA", 0, 0x8000);
        $this->assertSame('b 0x8004', $back['text']);
        $this->assertSame(0x8004, $back['target']);

        $blx = $decoder->decode("\x00\x00\x00\xFA", 0, 0x8000);
        $this->assertSame('blx 0x8008', $blx['text']);
        $this->assertSame('call', $blx['kind']);

        $bx = $decoder->decode("\x1E\xFF\x2F\xE1", 0, 0x8000);
        $this->assertSame('bx lr', $bx['text']);
        $this->assertSame('return', $bx['kind']);
        $this->assertArrayNotHasKey('target', $bx);
    }

    public function test_arm32_load_store_and_block_transfer(): void
    {
        $decoder = new Arm32Decoder;

        $ldr = $decoder->decode("\x00\x00\x9F\xE5", 0, 0x8000);
        $this->assertSame('ldr r0, [pc, #0] ; 0x8008', $ldr['text']);

        $ldrb = $decoder->decode("\x00\x10\xD0\xE5", 0, 0x8000);
        $this->assertSame('ldrb r1, [r0]', $ldrb['text']);

        $stm = $decoder->decode("\x03\x00\x80\xE8", 0, 0x8000);
        $this->assertSame('stmia r0, {r0, r1}', $stm['text']);

        $svc = $decoder->decode("\x00\x00\x00\xEF", 0, 0x8000);
        $this->assertSame('svc #0', $svc['text']);

        $mrs = $decoder->decode("\x00\x00\x0F\xE1", 0, 0x8000);
        $this->assertSame('mrs r0, cpsr', $mrs['text']);

        // LDR writing PC is a computed branch.
        $ldrpc = $decoder->decode("\x00\xF0\x9F\xE5", 0, 0x8000);
        $this->assertSame('ldr pc, [pc, #0] ; 0x8008', $ldrpc['text']);
        $this->assertSame('jump', $ldrpc['kind']);
    }

    public function test_arm32_uncovered_encodings_emit_word_data(): void
    {
        $decoder = new Arm32Decoder;

        // Coprocessor (MCR class): not covered, never mis-decoded.
        $word = $decoder->decode("\x00\x00\x00\xEE", 0, 0x8000);
        $this->assertSame(4, $word['length']);
        $this->assertSame('.word 0xEE000000', $word['text']);
        $this->assertSame('data', $word['kind']);

        // Misaligned and truncated inputs refuse (sweep steps bytes).
        $this->assertNull($decoder->decode("\x01\x00\xA0\xE3", 0, 0x8001));
        $this->assertNull($decoder->decode("\x01\x00\xA0", 0, 0x8000));
    }

    public function test_thumb_core_16bit_instructions(): void
    {
        $decoder = new ThumbDecoder;

        $movs = $decoder->decode("\x01\x20", 0, 0x8000);
        $this->assertSame(2, $movs['length']);
        $this->assertSame('movs r0, #1', $movs['text']);

        $bx = $decoder->decode("\x70\x47", 0, 0x8000);
        $this->assertSame('bx lr', $bx['text']);
        $this->assertSame('return', $bx['kind']);

        $beq = $decoder->decode("\x00\xD0", 0, 0x8000);
        $this->assertSame('beq 0x8004', $beq['text']);
        $this->assertSame('branch', $beq['kind']);
        $this->assertSame(0x8004, $beq['target']);

        $b = $decoder->decode("\x06\xE0", 0, 0x8000);
        $this->assertSame('b 0x8010', $b['text']);
        $this->assertSame('jump', $b['kind']);

        $ldr = $decoder->decode("\x01\x48", 0, 0x8000);
        $this->assertSame('ldr r0, [pc, #4] ; 0x8008', $ldr['text']);

        $push = $decoder->decode("\x00\xB5", 0, 0x8000);
        $this->assertSame('push {lr}', $push['text']);

        $pop = $decoder->decode("\x00\xBD", 0, 0x8000);
        $this->assertSame('pop {pc}', $pop['text']);
        $this->assertSame('return', $pop['kind']);

        $nop = $decoder->decode("\x00\xBF", 0, 0x8000);
        $this->assertSame('nop', $nop['text']);

        // CBZ: i:imm5:0 = 1:00011:0 = 70 -> 0x8000 + 4 + 70.
        $cbz = $decoder->decode("\x18\xB1", 0, 0x8000);
        $this->assertSame('cbz r0, 0x804a', $cbz['text']);
        $this->assertSame('branch', $cbz['kind']);
        $this->assertSame(0x804A, $cbz['target']);
    }

    public function test_thumb32_branches_and_wide_moves(): void
    {
        $decoder = new ThumbDecoder;

        // BL with imm11=1: target = 0x8000 + 4 + 2.
        $bl = $decoder->decode("\x00\xF0\x01\xF8", 0, 0x8000);
        $this->assertSame(4, $bl['length']);
        $this->assertSame('bl 0x8006', $bl['text']);
        $this->assertSame('call', $bl['kind']);
        $this->assertSame(0x8006, $bl['target']);

        // B.W zero offset: S=0, J1=J2=1 (I1=I2=0), imm10=imm11=0.
        $bw = $decoder->decode("\x00\xF0\x00\xA8", 0, 0x8000);
        $this->assertSame('b.w 0x8004', $bw['text']);
        $this->assertSame('jump', $bw['kind']);

        $movw = $decoder->decode("\x40\xF2\x01\x00", 0, 0x8000);
        $this->assertSame('movw r0, #1', $movw['text']);

        $movt = $decoder->decode("\xC0\xF2\x01\x00", 0, 0x8000);
        $this->assertSame('movt r0, #1', $movt['text']);

        $addw = $decoder->decode("\x00\xF2\x05\x01", 0, 0x8000);
        $this->assertSame('add.w r1, r0, #5', $addw['text']);

        $adr = $decoder->decode("\x0F\xF2\x05\x01", 0, 0x8000);
        $this->assertSame('adr r1, 0x8009', $adr['text']);
    }

    public function test_thumb_system_and_fallbacks(): void
    {
        $decoder = new ThumbDecoder;

        $mrs = $decoder->decode("\xEF\xF3\x00\x80", 0, 0x8000);
        $this->assertSame('mrs r0, apsr', $mrs['text']);

        $dmb = $decoder->decode("\xBF\xF3\x5F\x8F", 0, 0x8000);
        $this->assertSame('dmb sy', $dmb['text']);

        // Coprocessor class: 32-bit is certain, decoding is not.
        // Little-endian halfwords FC00:0000 read as u32 0x0000FC00.
        $word = $decoder->decode("\x00\xFC\x00\x00", 0, 0x8000);
        $this->assertSame(4, $word['length']);
        $this->assertSame('.word 0x0000FC00', $word['text']);
        $this->assertSame('data', $word['kind']);

        // Truncated 32-bit prefix refuses.
        $this->assertNull($decoder->decode("\x00\xF0", 0, 0x8000));
    }

    public function test_linear_sweep_counts_data_words_as_data(): void
    {
        $sweep = LinearSweep::sweep(new Arm32Decoder, "\x01\x00\xA0\xE3\x00\x00\x00\xEE", 0x8000, 0, 100, 100);

        $this->assertSame(1, $sweep['instructions']);
        $this->assertSame(1, $sweep['data_bytes']);
        $this->assertSame('mov r0, #1', $sweep['lines'][0]['text']);
        $this->assertSame('.word 0xEE000000', $sweep['lines'][1]['text']);
    }
}
