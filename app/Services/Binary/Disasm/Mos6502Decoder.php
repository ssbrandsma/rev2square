<?php

namespace App\Services\Binary\Disasm;

/**
 * NMOS 6502 decoder (Commodore 64 / 6510 context). Undocumented
 * opcodes are NOT decoded — they emit as data bytes so output never
 * invents instructions the manual does not define.
 */
final class Mos6502Decoder implements InstructionDecoder
{
    public function id(): string
    {
        return 'mos6502';
    }

    public function name(): string
    {
        return 'MOS 6502 (NMOS)';
    }

    public function addressBits(): int
    {
        return 16;
    }

    /**
     * opcode => [mnemonic, mode]. Modes: i implied, a accumulator,
     * n immediate, z zero-page, x zp,X, y zp,Y, b absolute, c abs,X,
     * d abs,Y, e indirect, f (indirect,X), g (indirect),Y, r relative.
     *
     * @var array<int, array{string, string}>
     */
    private const TABLE = [
        0x00 => ['BRK', 'i'], 0x01 => ['ORA', 'f'], 0x05 => ['ORA', 'z'], 0x06 => ['ASL', 'z'],
        0x08 => ['PHP', 'i'], 0x09 => ['ORA', 'n'], 0x0A => ['ASL', 'a'], 0x0D => ['ORA', 'b'],
        0x0E => ['ASL', 'b'], 0x10 => ['BPL', 'r'], 0x11 => ['ORA', 'g'], 0x15 => ['ORA', 'x'],
        0x16 => ['ASL', 'x'], 0x18 => ['CLC', 'i'], 0x19 => ['ORA', 'd'], 0x1D => ['ORA', 'c'],
        0x1E => ['ASL', 'c'], 0x20 => ['JSR', 'b'], 0x21 => ['AND', 'f'], 0x24 => ['BIT', 'z'],
        0x25 => ['AND', 'z'], 0x26 => ['ROL', 'z'], 0x28 => ['PLP', 'i'], 0x29 => ['AND', 'n'],
        0x2A => ['ROL', 'a'], 0x2C => ['BIT', 'b'], 0x2D => ['AND', 'b'], 0x2E => ['ROL', 'b'],
        0x30 => ['BMI', 'r'], 0x31 => ['AND', 'g'], 0x35 => ['AND', 'x'], 0x36 => ['ROL', 'x'],
        0x38 => ['SEC', 'i'], 0x39 => ['AND', 'd'], 0x3D => ['AND', 'c'], 0x3E => ['ROL', 'c'],
        0x40 => ['RTI', 'i'], 0x41 => ['EOR', 'f'], 0x45 => ['EOR', 'z'], 0x46 => ['LSR', 'z'],
        0x48 => ['PHA', 'i'], 0x49 => ['EOR', 'n'], 0x4A => ['LSR', 'a'], 0x4C => ['JMP', 'b'],
        0x4D => ['EOR', 'b'], 0x4E => ['LSR', 'b'], 0x50 => ['BVC', 'r'], 0x51 => ['EOR', 'g'],
        0x55 => ['EOR', 'x'], 0x56 => ['LSR', 'x'], 0x58 => ['CLI', 'i'], 0x59 => ['EOR', 'd'],
        0x5D => ['EOR', 'c'], 0x5E => ['LSR', 'c'], 0x60 => ['RTS', 'i'], 0x61 => ['ADC', 'f'],
        0x65 => ['ADC', 'z'], 0x66 => ['ROR', 'z'], 0x68 => ['PLA', 'i'], 0x69 => ['ADC', 'n'],
        0x6A => ['ROR', 'a'], 0x6C => ['JMP', 'e'], 0x6D => ['ADC', 'b'], 0x6E => ['ROR', 'b'],
        0x70 => ['BVS', 'r'], 0x71 => ['ADC', 'g'], 0x75 => ['ADC', 'x'], 0x76 => ['ROR', 'x'],
        0x78 => ['SEI', 'i'], 0x79 => ['ADC', 'd'], 0x7D => ['ADC', 'c'], 0x7E => ['ROR', 'c'],
        0x81 => ['STA', 'f'], 0x84 => ['STY', 'z'], 0x85 => ['STA', 'z'], 0x86 => ['STX', 'z'],
        0x88 => ['DEY', 'i'], 0x8A => ['TXA', 'i'], 0x8C => ['STY', 'b'], 0x8D => ['STA', 'b'],
        0x8E => ['STX', 'b'], 0x90 => ['BCC', 'r'], 0x91 => ['STA', 'g'], 0x94 => ['STY', 'x'],
        0x95 => ['STA', 'x'], 0x96 => ['STX', 'y'], 0x98 => ['TYA', 'i'], 0x99 => ['STA', 'd'],
        0x9A => ['TXS', 'i'], 0x9D => ['STA', 'c'], 0xA0 => ['LDY', 'n'], 0xA1 => ['LDA', 'f'],
        0xA2 => ['LDX', 'n'], 0xA4 => ['LDY', 'z'], 0xA5 => ['LDA', 'z'], 0xA6 => ['LDX', 'z'],
        0xA8 => ['TAY', 'i'], 0xA9 => ['LDA', 'n'], 0xAA => ['TAX', 'i'], 0xAC => ['LDY', 'b'],
        0xAD => ['LDA', 'b'], 0xAE => ['LDX', 'b'], 0xB0 => ['BCS', 'r'], 0xB1 => ['LDA', 'g'],
        0xB4 => ['LDY', 'x'], 0xB5 => ['LDA', 'x'], 0xB6 => ['LDX', 'y'], 0xB8 => ['CLV', 'i'],
        0xB9 => ['LDA', 'd'], 0xBA => ['TSX', 'i'], 0xBC => ['LDY', 'c'], 0xBD => ['LDA', 'c'],
        0xBE => ['LDX', 'd'], 0xC0 => ['CPY', 'n'], 0xC1 => ['CMP', 'f'], 0xC4 => ['CPY', 'z'],
        0xC5 => ['CMP', 'z'], 0xC6 => ['DEC', 'z'], 0xC8 => ['INY', 'i'], 0xC9 => ['CMP', 'n'],
        0xCA => ['DEX', 'i'], 0xCC => ['CPY', 'b'], 0xCD => ['CMP', 'b'], 0xCE => ['DEC', 'b'],
        0xD0 => ['BNE', 'r'], 0xD1 => ['CMP', 'g'], 0xD5 => ['CMP', 'x'], 0xD6 => ['DEC', 'x'],
        0xD8 => ['CLD', 'i'], 0xD9 => ['CMP', 'd'], 0xDD => ['CMP', 'c'], 0xDE => ['DEC', 'c'],
        0xE0 => ['CPX', 'n'], 0xE1 => ['SBC', 'f'], 0xE4 => ['CPX', 'z'], 0xE5 => ['SBC', 'z'],
        0xE6 => ['INC', 'z'], 0xE8 => ['INX', 'i'], 0xE9 => ['SBC', 'n'], 0xEA => ['NOP', 'i'],
        0xEC => ['CPX', 'b'], 0xED => ['SBC', 'b'], 0xEE => ['INC', 'b'], 0xF0 => ['BEQ', 'r'],
        0xF1 => ['SBC', 'g'], 0xF5 => ['SBC', 'x'], 0xF6 => ['INC', 'x'], 0xF8 => ['SED', 'i'],
        0xF9 => ['SBC', 'd'], 0xFD => ['SBC', 'c'], 0xFE => ['INC', 'c'],
    ];

    public function decode(string $bytes, int $offset, int $address): ?array
    {
        if ($offset < 0 || $offset >= strlen($bytes)) {
            return null;
        }
        $op = ord($bytes[$offset]);
        $entry = self::TABLE[$op] ?? null;
        if ($entry === null) {
            return null;
        }

        [$mnem, $mode] = $entry;
        $b1 = $offset + 1 < strlen($bytes) ? ord($bytes[$offset + 1]) : null;
        $b2 = $offset + 2 < strlen($bytes) ? ord($bytes[$offset + 2]) : null;

        return match ($mode) {
            'i' => ['length' => 1, 'text' => $mnem, 'kind' => $this->flowKind($mnem)],
            'a' => ['length' => 1, 'text' => $mnem.' A', 'kind' => 'code'],
            'n' => $b1 === null ? null : ['length' => 2, 'text' => sprintf('%s #$%02X', $mnem, $b1), 'kind' => 'code'],
            'z' => $b1 === null ? null : ['length' => 2, 'text' => sprintf('%s $%02X', $mnem, $b1), 'kind' => 'code'],
            'x' => $b1 === null ? null : ['length' => 2, 'text' => sprintf('%s $%02X,X', $mnem, $b1), 'kind' => 'code'],
            'y' => $b1 === null ? null : ['length' => 2, 'text' => sprintf('%s $%02X,Y', $mnem, $b1), 'kind' => 'code'],
            'b' => $b2 === null ? null : $this->absolute($mnem, $b1 ?? 0, $b2),
            'c' => $b2 === null ? null : ['length' => 3, 'text' => sprintf('%s $%02X%02X,X', $mnem, $b2, $b1), 'kind' => 'code'],
            'd' => $b2 === null ? null : ['length' => 3, 'text' => sprintf('%s $%02X%02X,Y', $mnem, $b2, $b1), 'kind' => 'code'],
            'e' => $b2 === null ? null : ['length' => 3, 'text' => sprintf('%s ($%02X%02X)', $mnem, $b2, $b1), 'kind' => 'jump'],
            'f' => $b1 === null ? null : ['length' => 2, 'text' => sprintf('%s ($%02X,X)', $mnem, $b1), 'kind' => 'code'],
            'g' => $b1 === null ? null : ['length' => 2, 'text' => sprintf('%s ($%02X),Y', $mnem, $b1), 'kind' => 'code'],
            'r' => $b1 === null ? null : $this->branch($mnem, $b1, $address),
            default => null,
        };
    }

    /** @return array{length: int, text: string, kind: string, target?: int} */
    private function absolute(string $mnem, int $lo, int $hi): array
    {
        $target = ($hi << 8) | $lo;
        $kind = match ($mnem) {
            'JMP' => 'jump',
            'JSR' => 'call',
            default => 'code',
        };
        $row = ['length' => 3, 'text' => sprintf('%s $%04X', $mnem, $target), 'kind' => $kind];
        if ($kind !== 'code') {
            $row['target'] = $target;
        }

        return $row;
    }

    /** @return array{length: int, text: string, kind: string, target: int} */
    private function branch(string $mnem, int $offset, int $address): array
    {
        $rel = $offset < 0x80 ? $offset : $offset - 256;
        $target = ($address + 2 + $rel) & 0xFFFF;

        return ['length' => 2, 'text' => sprintf('%s $%04X', $mnem, $target), 'kind' => 'branch', 'target' => $target];
    }

    private function flowKind(string $mnem): string
    {
        return match ($mnem) {
            'RTS', 'RTI', 'BRK' => 'return',
            default => 'code',
        };
    }
}
