<?php

namespace App\Services\Binary\Disasm;

/**
 * Intel 8051 decoder (classic core, 64K code space). Opcode 0xA5 is
 * reserved on the classic core and decodes as data.
 */
final class I8051Decoder implements InstructionDecoder
{
    public function id(): string
    {
        return 'i8051';
    }

    public function name(): string
    {
        return 'Intel 8051';
    }

    public function addressBits(): int
    {
        return 16;
    }

    /**
     * Explicit entries for columns 0-7 of each row; columns 8-F are
     * register blocks handled structurally. Format: [mnemonic, kind]
     * where kind: 1 = 1 byte, D = +direct, B = +bit, I = +immediate,
     * R = +relative, plus composite formats below.
     *
     * @var array<int, array{string, string}>
     */
    private const HEAD = [
        0x00 => ['NOP', '1'], 0x01 => ['AJMP', 'J'], 0x02 => ['LJMP', 'A'], 0x03 => ['RR A', '1'],
        0x04 => ['INC A', '1'], 0x05 => ['INC', 'D'], 0x06 => ['INC @R0', '1'], 0x07 => ['INC @R1', '1'],
        0x10 => ['JBC', 'b'], 0x11 => ['ACALL', 'J'], 0x12 => ['LCALL', 'A'], 0x13 => ['RRC A', '1'],
        0x14 => ['DEC A', '1'], 0x15 => ['DEC', 'D'], 0x16 => ['DEC @R0', '1'], 0x17 => ['DEC @R1', '1'],
        0x20 => ['JB', 'b'], 0x21 => ['AJMP', 'J'], 0x22 => ['RETI', '1'], 0x23 => ['RL A', '1'],
        0x24 => ['ADD A,', 'I'], 0x25 => ['ADD A,', 'D'], 0x26 => ['ADD A,@R0', '1'], 0x27 => ['ADD A,@R1', '1'],
        0x30 => ['JNB', 'b'], 0x31 => ['ACALL', 'J'], 0x32 => ['RET', '1'], 0x33 => ['RLC A', '1'],
        0x34 => ['ADDC A,', 'I'], 0x35 => ['ADDC A,', 'D'], 0x36 => ['ADDC A,@R0', '1'], 0x37 => ['ADDC A,@R1', '1'],
        0x40 => ['JC', 'R'], 0x41 => ['AJMP', 'J'], 0x42 => ['ORL', 'a'], 0x43 => ['ORL', 'e'],
        0x44 => ['ORL A,', 'I'], 0x45 => ['ORL A,', 'D'], 0x46 => ['ORL A,@R0', '1'], 0x47 => ['ORL A,@R1', '1'],
        0x50 => ['JNC', 'R'], 0x51 => ['ACALL', 'J'], 0x52 => ['ANL', 'a'], 0x53 => ['ANL', 'e'],
        0x54 => ['ANL A,', 'I'], 0x55 => ['ANL A,', 'D'], 0x56 => ['ANL A,@R0', '1'], 0x57 => ['ANL A,@R1', '1'],
        0x60 => ['JZ', 'R'], 0x61 => ['AJMP', 'J'], 0x62 => ['XRL', 'a'], 0x63 => ['XRL', 'e'],
        0x64 => ['XRL A,', 'I'], 0x65 => ['XRL A,', 'D'], 0x66 => ['XRL A,@R0', '1'], 0x67 => ['XRL A,@R1', '1'],
        0x70 => ['JNZ', 'R'], 0x71 => ['ACALL', 'J'], 0x72 => ['ORL C,', 'B'], 0x73 => ['JMP @A+DPTR', '1'],
        0x74 => ['MOV A,', 'I'], 0x75 => ['MOV', 'e'], 0x76 => ['MOV @R0,', 'I'], 0x77 => ['MOV @R1,', 'I'],
        0x80 => ['SJMP', 'R'], 0x81 => ['AJMP', 'J'], 0x82 => ['ANL C,', 'B'], 0x83 => ['MOVC A,@A+PC', '1'],
        0x84 => ['DIV AB', '1'], 0x85 => ['MOV', 'f'], 0x86 => ['MOV', 'g'], 0x87 => ['MOV', 'h'],
        0x90 => ['MOV DPTR,#', 'W'], 0x91 => ['ACALL', 'J'], 0x92 => ['MOV', 'c'], 0x93 => ['MOVC A,@A+DPTR', '1'],
        0x94 => ['SUBB A,', 'I'], 0x95 => ['SUBB A,', 'D'], 0x96 => ['SUBB A,@R0', '1'], 0x97 => ['SUBB A,@R1', '1'],
        0xA0 => ['ORL C,/', 'B'], 0xA1 => ['AJMP', 'J'], 0xA2 => ['MOV C,', 'B'], 0xA3 => ['INC DPTR', '1'],
        0xA4 => ['MUL AB', '1'], 0xA6 => ['MOV @R0,', 'D'], 0xA7 => ['MOV @R1,', 'D'],
        0xB0 => ['ANL C,/', 'B'], 0xB1 => ['ACALL', 'J'], 0xB2 => ['CPL', 'B'], 0xB3 => ['CPL C', '1'],
        0xB4 => ['CJNE A,', 'j'], 0xB5 => ['CJNE A,', 'k'], 0xB6 => ['CJNE @R0,', 'j'], 0xB7 => ['CJNE @R1,', 'j'],
        0xC0 => ['PUSH', 'D'], 0xC1 => ['AJMP', 'J'], 0xC2 => ['CLR', 'B'], 0xC3 => ['CLR C', '1'],
        0xC4 => ['SWAP A', '1'], 0xC5 => ['XCH A,', 'D'], 0xC6 => ['XCH A,@R0', '1'], 0xC7 => ['XCH A,@R1', '1'],
        0xD0 => ['POP', 'D'], 0xD1 => ['ACALL', 'J'], 0xD2 => ['SETB', 'B'], 0xD3 => ['SETB C', '1'],
        0xD4 => ['DA A', '1'], 0xD5 => ['DJNZ', 'm'], 0xD6 => ['XCHD A,@R0', '1'], 0xD7 => ['XCHD A,@R1', '1'],
        0xE0 => ['MOVX A,@DPTR', '1'], 0xE1 => ['AJMP', 'J'], 0xE2 => ['MOVX A,@R0', '1'], 0xE3 => ['MOVX A,@R1', '1'],
        0xE4 => ['CLR A', '1'], 0xE5 => ['MOV A,', 'D'], 0xE6 => ['MOV A,@R0', '1'], 0xE7 => ['MOV A,@R1', '1'],
        0xF0 => ['MOVX @DPTR,A', '1'], 0xF1 => ['ACALL', 'J'], 0xF2 => ['MOVX @R0,A', '1'], 0xF3 => ['MOVX @R1,A', '1'],
        0xF4 => ['CPL A', '1'], 0xF5 => ['MOV', 'q'], 0xF6 => ['MOV @R0,A', '1'], 0xF7 => ['MOV @R1,A', '1'],
    ];

    public function decode(string $bytes, int $offset, int $address): ?array
    {
        $length = strlen($bytes);
        if ($offset < 0 || $offset >= $length) {
            return null;
        }
        $op = ord($bytes[$offset]);
        $b1 = $offset + 1 < $length ? ord($bytes[$offset + 1]) : null;
        $b2 = $offset + 2 < $length ? ord($bytes[$offset + 2]) : null;

        // Register blocks: columns 8-F.
        $col = $op & 0x0F;
        if ($col >= 8) {
            return $this->registerBlock($op, $b1, $b2, $address);
        }

        $entry = self::HEAD[$op] ?? null;
        if ($entry === null) {
            return null; // 0xA5 reserved
        }
        [$mnem, $kind] = $entry;

        switch ($kind) {
            case '1':
                $flow = in_array($mnem, ['RET', 'RETI'], true) ? 'return' : 'code';

                return ['length' => 1, 'text' => $mnem, 'kind' => $flow];
            case 'D':
                return $b1 === null ? null : ['length' => 2, 'text' => sprintf('%s $%02X', $mnem, $b1), 'kind' => 'code'];
            case 'B':
                return $b1 === null ? null : ['length' => 2, 'text' => sprintf('%s$%02X', $mnem, $b1), 'kind' => 'code'];
            case 'I':
                return $b1 === null ? null : ['length' => 2, 'text' => sprintf('%s#$%02X', $mnem, $b1), 'kind' => 'code'];
            case 'W':
                return $b2 === null ? null : ['length' => 3, 'text' => sprintf('%s$%02X%02X', $mnem, $b1, $b2), 'kind' => 'code'];
            case 'A': // LJMP / LCALL absolute
                if ($b2 === null) {
                    return null;
                }
                $target = ($b1 << 8) | $b2;
                $flow = str_starts_with($mnem, 'LCALL') ? 'call' : 'jump';

                return ['length' => 3, 'text' => sprintf('%s $%04X', $mnem, $target), 'kind' => $flow, 'target' => $target];
            case 'J': // AJMP / ACALL 11-bit
                if ($b1 === null) {
                    return null;
                }
                $target = (($address + 2) & 0xF800) | (($op & 0xE0) << 3) | $b1;
                $flow = str_starts_with($mnem, 'ACALL') ? 'call' : 'jump';

                return ['length' => 2, 'text' => sprintf('%s $%04X', $mnem, $target), 'kind' => $flow, 'target' => $target];
            case 'R': // relative branch
                if ($b1 === null) {
                    return null;
                }
                $target = ($address + 2 + ($b1 < 0x80 ? $b1 : $b1 - 256)) & 0xFFFF;
                $flow = $mnem === 'SJMP' ? 'jump' : 'branch';

                return ['length' => 2, 'text' => sprintf('%s $%04X', $mnem, $target), 'kind' => $flow, 'target' => $target];
            case 'a': // ORL/ANL/XRL direct,A
                return $b1 === null ? null : ['length' => 2, 'text' => sprintf('%s $%02X,A', $mnem, $b1), 'kind' => 'code'];
            case 'b': // JB/JNB/JBC bit,rel
                if ($b2 === null) {
                    return null;
                }
                $target = ($address + 3 + ($b2 < 0x80 ? $b2 : $b2 - 256)) & 0xFFFF;

                return ['length' => 3, 'text' => sprintf('%s $%02X,$%04X', $mnem, $b1, $target), 'kind' => 'branch', 'target' => $target];
            case 'c': // MOV bit,C
                return $b1 === null ? null : ['length' => 2, 'text' => sprintf('%s$%02X,C', $mnem, $b1), 'kind' => 'code'];
            case 'e': // MOV/ORL/ANL/XRL direct,#imm
                return $b2 === null ? null : ['length' => 3, 'text' => sprintf('%s $%02X,#$%02X', $mnem, $b1, $b2), 'kind' => 'code'];
            case 'f': // MOV direct,direct
                return $b2 === null ? null : ['length' => 3, 'text' => sprintf('%s $%02X,$%02X', $mnem, $b1, $b2), 'kind' => 'code'];
            case 'g': // MOV direct,@R0
                return $b1 === null ? null : ['length' => 2, 'text' => sprintf('%s $%02X,@R0', $mnem, $b1), 'kind' => 'code'];
            case 'h': // MOV direct,@R1
                return $b1 === null ? null : ['length' => 2, 'text' => sprintf('%s $%02X,@R1', $mnem, $b1), 'kind' => 'code'];
            case 'j': // CJNE A/@R#,imm,rel
                if ($b2 === null) {
                    return null;
                }
                $target = ($address + 3 + ($b2 < 0x80 ? $b2 : $b2 - 256)) & 0xFFFF;

                return ['length' => 3, 'text' => sprintf('%s#$%02X,$%04X', $mnem, $b1, $target), 'kind' => 'branch', 'target' => $target];
            case 'k': // CJNE A,direct,rel
                if ($b2 === null) {
                    return null;
                }
                $target = ($address + 3 + ($b2 < 0x80 ? $b2 : $b2 - 256)) & 0xFFFF;

                return ['length' => 3, 'text' => sprintf('CJNE A,$%02X,$%04X', $b1, $target), 'kind' => 'branch', 'target' => $target];
            case 'm': // DJNZ direct,rel
                if ($b2 === null) {
                    return null;
                }
                $target = ($address + 3 + ($b2 < 0x80 ? $b2 : $b2 - 256)) & 0xFFFF;

                return ['length' => 3, 'text' => sprintf('%s $%02X,$%04X', $mnem, $b1, $target), 'kind' => 'branch', 'target' => $target];
            case 'q': // MOV direct,A
                return $b1 === null ? null : ['length' => 2, 'text' => sprintf('%s $%02X,A', $mnem, $b1), 'kind' => 'code'];
            default:
                return null;
        }
    }

    /** @return array{length: int, text: string, kind: string, target?: int}|null */
    private function registerBlock(int $op, ?int $b1, ?int $b2, int $address): ?array
    {
        $row = $op >> 4;
        $reg = $op & 0x07;

        switch ($row) {
            case 0x0: return ['length' => 1, 'text' => "INC R{$reg}", 'kind' => 'code'];
            case 0x1: return ['length' => 1, 'text' => "DEC R{$reg}", 'kind' => 'code'];
            case 0x2: return ['length' => 1, 'text' => "ADD A,R{$reg}", 'kind' => 'code'];
            case 0x3: return ['length' => 1, 'text' => "ADDC A,R{$reg}", 'kind' => 'code'];
            case 0x4: return ['length' => 1, 'text' => "ORL A,R{$reg}", 'kind' => 'code'];
            case 0x5: return ['length' => 1, 'text' => "ANL A,R{$reg}", 'kind' => 'code'];
            case 0x6: return ['length' => 1, 'text' => "XRL A,R{$reg}", 'kind' => 'code'];
            case 0x7:
                return $b1 === null ? null : ['length' => 2, 'text' => sprintf('MOV R%d,#\$%02X', $reg, $b1), 'kind' => 'code'];
            case 0x8:
                return $b1 === null ? null : ['length' => 2, 'text' => sprintf('MOV \$%02X,R%d', $b1, $reg), 'kind' => 'code'];
            case 0x9: return ['length' => 1, 'text' => "SUBB A,R{$reg}", 'kind' => 'code'];
            case 0xA:
                return $b1 === null ? null : ['length' => 2, 'text' => sprintf('MOV R%d,\$%02X', $reg, $b1), 'kind' => 'code'];
            case 0xB: // CJNE Rn,#imm,rel
                if ($b2 === null) {
                    return null;
                }
                $target = ($address + 3 + ($b2 < 0x80 ? $b2 : $b2 - 256)) & 0xFFFF;

                return ['length' => 3, 'text' => sprintf('CJNE R%d,#\$%02X,\$%04X', $reg, $b1, $target), 'kind' => 'branch', 'target' => $target];
            case 0xC: return ['length' => 1, 'text' => "XCH A,R{$reg}", 'kind' => 'code'];
            case 0xD: // DJNZ Rn,rel
                if ($b1 === null) {
                    return null;
                }
                $target = ($address + 2 + ($b1 < 0x80 ? $b1 : $b1 - 256)) & 0xFFFF;

                return ['length' => 2, 'text' => sprintf('DJNZ R%d,\$%04X', $reg, $target), 'kind' => 'branch', 'target' => $target];
            case 0xE: return ['length' => 1, 'text' => "MOV A,R{$reg}", 'kind' => 'code'];
            case 0xF: return ['length' => 1, 'text' => "MOV R{$reg},A", 'kind' => 'code'];
            default: return null;
        }
    }
}
