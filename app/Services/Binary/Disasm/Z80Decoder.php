<?php

namespace App\Services\Binary\Disasm;

/**
 * Z80 decoder: main map, CB bit operations, ED extended map, and
 * DD/FD index prefixes (including DDCB/FDCB). Undocumented
 * SLL is included (universally implemented); other undocumented
 * behavior decodes as data rather than invented instructions.
 */
final class Z80Decoder implements InstructionDecoder
{
    public function id(): string
    {
        return 'z80';
    }

    public function name(): string
    {
        return 'Zilog Z80';
    }

    public function addressBits(): int
    {
        return 16;
    }

    private const REG = ['B', 'C', 'D', 'E', 'H', 'L', '(HL)', 'A'];

    private const REG_IX = ['B', 'C', 'D', 'E', 'IXH', 'IXL', '(IX+d)', 'A'];

    private const REG_IY = ['B', 'C', 'D', 'E', 'IYH', 'IYL', '(IY+d)', 'A'];

    private const DD = ['BC', 'DE', 'HL', 'SP'];

    private const QQ = ['BC', 'DE', 'HL', 'AF'];

    private const CC = ['NZ', 'Z', 'NC', 'C', 'PO', 'PE', 'P', 'M'];

    private const ALU = ['ADD A,', 'ADC A,', 'SUB', 'SBC A,', 'AND', 'XOR', 'OR', 'CP'];

    public function decode(string $bytes, int $offset, int $address): ?array
    {
        $length = strlen($bytes);
        if ($offset < 0 || $offset >= $length) {
            return null;
        }
        $op = ord($bytes[$offset]);

        if ($op === 0xCB) {
            if ($offset + 1 >= $length) {
                return null;
            }

            return $this->decodeCB($bytes, $offset, $address, 0, null);
        }

        if ($op === 0xED) {
            if ($offset + 1 >= $length) {
                return null;
            }

            return $this->decodeED(ord($bytes[$offset + 1]), $bytes, $offset, $address);
        }

        if ($op === 0xDD || $op === 0xFD) {
            return $this->decodeIndexed($bytes, $offset, $address, $op === 0xDD ? 1 : 2);
        }

        return $this->decodeMain($op, $bytes, $offset, $address, 0);
    }

    /** @return array{length: int, text: string, kind: string, target?: int}|null */
    private function decodeIndexed(string $bytes, int $offset, int $address, int $mode): ?array
    {
        // Collapse DD/FD chains: the last prefix wins; skipped bytes
        // count toward the instruction length.
        $length = strlen($bytes);
        $pos = $offset;
        while ($pos < $length && (ord($bytes[$pos]) === 0xDD || ord($bytes[$pos]) === 0xFD)) {
            $mode = ord($bytes[$pos]) === 0xDD ? 1 : 2;
            $pos++;
        }
        if ($pos >= $length) {
            return null;
        }
        $extra = $pos - $offset;
        $op = ord($bytes[$pos]);

        if ($op === 0xCB) {
            if ($pos + 2 >= $length) {
                return null;
            }
            $disp = ord($bytes[$pos + 1]);
            $row = $this->decodeCB($bytes, $pos, $address + $extra, $mode, $disp);
            if ($row === null) {
                return null;
            }
            $row['length'] += $extra;

            return $row;
        }

        if ($op === 0xED) {
            // Prefix dropped before ED: decode the ED sequence.
            $row = $this->decode($bytes, $pos, $address + $extra);
            if ($row === null) {
                return null;
            }
            $row['length'] += $extra;

            return $row;
        }

        $row = $this->decodeMain($op, $bytes, $pos, $address + $extra, $mode);
        if ($row === null) {
            return null;
        }
        $row['length'] += $extra;

        return $row;
    }

    /** @return array{length: int, text: string, kind: string, target?: int}|null */
    private function decodeMain(int $op, string $bytes, int $offset, int $address, int $mode): array
    {
        $x = $op >> 6;
        $y = ($op >> 3) & 7;
        $z = $op & 7;
        $p = $y >> 1;
        $q = $y & 1;

        $regs = [self::REG, self::REG_IX, self::REG_IY][$mode] ?? self::REG;
        $idx = $mode === 1 ? 'IX' : ($mode === 2 ? 'IY' : 'HL');

        // Displacement for (HL)/(IX+d)/(IY+d) operands in indexed mode.
        $memOp = ($x === 1 && ($y === 6 || $z === 6) && ! ($y === 6 && $z === 6))
            || ($x === 2 && $z === 6)
            || ($x === 0 && $z === 4 && $y === 6)
            || ($x === 0 && $z === 5 && $y === 6)
            || ($x === 0 && $z === 6 && $y === 6);
        $disp = 0;
        $dispLen = 0;
        if ($mode !== 0 && $memOp) {
            if ($offset + 1 >= strlen($bytes)) {
                return null;
            }
            $disp = ord($bytes[$offset + 1]);
            $dispLen = 1;
        }
        $memText = $mode === 0 ? '(HL)' : sprintf('(%s%s)', $idx, $this->signedDisp($disp));

        $regY = $y === 6 && $memOp ? $memText : $regs[$y];
        $regZ = $z === 6 && $memOp ? $memText : $regs[$z];

        $imm8 = fn (): ?int => $offset + 1 + $dispLen < strlen($bytes) ? ord($bytes[$offset + 1 + $dispLen]) : null;
        $imm16 = function () use ($bytes, $offset, $dispLen): ?int {
            if ($offset + 2 + $dispLen >= strlen($bytes)) {
                return null;
            }

            return ord($bytes[$offset + 1 + $dispLen]) | (ord($bytes[$offset + 2 + $dispLen]) << 8);
        };
        $rel = function (int $baseLen) use ($bytes, $offset, $address): ?int {
            if ($offset + $baseLen >= strlen($bytes)) {
                return null;
            }
            $d = ord($bytes[$offset + $baseLen]);

            return ($address + $baseLen + 1 + ($d < 0x80 ? $d : $d - 256)) & 0xFFFF;
        };

        if ($x === 0) {
            if ($z === 0) {
                if ($y === 0) {
                    return ['length' => 1, 'text' => 'NOP', 'kind' => 'code'];
                }
                if ($y === 1) {
                    return ['length' => 1, 'text' => "EX AF,AF'", 'kind' => 'code'];
                }
                if ($y === 2) {
                    $t = $rel(1);
                    if ($t === null) {
                        return null;
                    }

                    return ['length' => 2, 'text' => sprintf('DJNZ $%04X', $t), 'kind' => 'branch', 'target' => $t];
                }
                if ($y === 3) {
                    $t = $rel(1);
                    if ($t === null) {
                        return null;
                    }

                    return ['length' => 2, 'text' => sprintf('JR $%04X', $t), 'kind' => 'jump', 'target' => $t];
                }
                $t = $rel(1);
                if ($t === null) {
                    return null;
                }

                return ['length' => 2, 'text' => sprintf('JR %s,$%04X', self::CC[$y - 4], $t), 'kind' => 'branch', 'target' => $t];
            }
            if ($z === 1) {
                $dd = self::DD[$p];
                if ($mode !== 0 && $p === 2) {
                    $dd = $idx;
                }
                if ($q === 0) {
                    $nn = $imm16();
                    if ($nn === null) {
                        return null;
                    }

                    return ['length' => 3, 'text' => sprintf('LD %s,$%04X', $dd, $nn), 'kind' => 'code'];
                }

                return ['length' => 1, 'text' => "ADD {$idx},{$dd}", 'kind' => 'code'];
            }
            if ($z === 2) {
                if ($q === 0) {
                    return match ($p) {
                        0 => ['length' => 1, 'text' => 'LD (BC),A', 'kind' => 'code'],
                        1 => ['length' => 1, 'text' => 'LD A,(BC)', 'kind' => 'code'],
                        2 => ['length' => 1, 'text' => 'LD (DE),A', 'kind' => 'code'],
                        3 => ['length' => 1, 'text' => 'LD A,(DE)', 'kind' => 'code'],
                    };
                }
                $nn = $imm16();
                if ($nn === null) {
                    return null;
                }

                return match ($p) {
                    0 => ['length' => 3, 'text' => sprintf('LD ($%04X),%s', $nn, $idx), 'kind' => 'code'],
                    1 => ['length' => 3, 'text' => sprintf('LD %s,($%04X)', $idx, $nn), 'kind' => 'code'],
                    2 => ['length' => 3, 'text' => sprintf('LD ($%04X),A', $nn), 'kind' => 'code'],
                    3 => ['length' => 3, 'text' => sprintf('LD A,($%04X)', $nn), 'kind' => 'code'],
                };
            }
            if ($z === 3) {
                $dd = self::DD[$p];
                if ($mode !== 0 && $p === 2) {
                    $dd = $idx;
                }

                return ['length' => 1, 'text' => ($q === 0 ? "INC {$dd}" : "DEC {$dd}"), 'kind' => 'code'];
            }
            if ($z === 4) {
                return ['length' => 1 + $dispLen, 'text' => "INC {$regY}", 'kind' => 'code'];
            }
            if ($z === 5) {
                return ['length' => 1 + $dispLen, 'text' => "DEC {$regY}", 'kind' => 'code'];
            }
            if ($z === 6) {
                $n = $imm8();
                if ($n === null) {
                    return null;
                }

                return ['length' => 2 + $dispLen, 'text' => sprintf('LD %s,$%02X', $regY, $n), 'kind' => 'code'];
            }

            return ['length' => 1, 'text' => ['RLCA', 'RRCA', 'RLA', 'RRA', 'DAA', 'CPL', 'SCF', 'CCF'][$y], 'kind' => 'code'];
        }

        if ($x === 1) {
            if ($y === 6 && $z === 6) {
                return ['length' => 1, 'text' => 'HALT', 'kind' => 'code'];
            }

            return ['length' => 1 + $dispLen, 'text' => "LD {$regY},{$regZ}", 'kind' => 'code'];
        }

        if ($x === 2) {
            return ['length' => 1 + $dispLen, 'text' => self::ALU[$y].$regZ, 'kind' => 'code'];
        }

        // x === 3
        if ($z === 0) {
            return ['length' => 1, 'text' => 'RET '.self::CC[$y], 'kind' => 'branch'];
        }
        if ($z === 1) {
            if ($q === 0) {
                $qq = self::QQ[$p];
                if ($mode !== 0 && $p === 2) {
                    $qq = $idx;
                }

                return ['length' => 1, 'text' => "POP {$qq}", 'kind' => 'code'];
            }

            return match ($p) {
                0 => ['length' => 1, 'text' => 'RET', 'kind' => 'return'],
                1 => ['length' => 1, 'text' => 'EXX', 'kind' => 'code'],
                2 => ['length' => 1, 'text' => "JP ({$idx})", 'kind' => 'jump'],
                3 => ['length' => 1, 'text' => "LD SP,{$idx}", 'kind' => 'code'],
            };
        }
        if ($z === 2) {
            $nn = $imm16();
            if ($nn === null) {
                return null;
            }

            return ['length' => 3, 'text' => sprintf('JP %s,$%04X', self::CC[$y], $nn), 'kind' => 'branch', 'target' => $nn];
        }
        if ($z === 3) {
            if ($y === 0) {
                $nn = $imm16();
                if ($nn === null) {
                    return null;
                }

                return ['length' => 3, 'text' => sprintf('JP $%04X', $nn), 'kind' => 'jump', 'target' => $nn];
            }
            if ($y === 2) {
                $n = $imm8();
                if ($n === null) {
                    return null;
                }

                return ['length' => 2, 'text' => sprintf('OUT ($%02X),A', $n), 'kind' => 'code'];
            }
            if ($y === 3) {
                $n = $imm8();
                if ($n === null) {
                    return null;
                }

                return ['length' => 2, 'text' => sprintf('IN A,($%02X)', $n), 'kind' => 'code'];
            }
            if ($y === 4) {
                return ['length' => 1, 'text' => "EX (SP),{$idx}", 'kind' => 'code'];
            }
            if ($y === 5) {
                return ['length' => 1, 'text' => 'EX DE,HL', 'kind' => 'code'];
            }
            if ($y === 6) {
                return ['length' => 1, 'text' => 'DI', 'kind' => 'code'];
            }
            if ($y === 7) {
                return ['length' => 1, 'text' => 'EI', 'kind' => 'code'];
            }

            return null; // prefix slots (CB/DD/ED/FD)
        }
        if ($z === 4) {
            $nn = $imm16();
            if ($nn === null) {
                return null;
            }

            return ['length' => 3, 'text' => sprintf('CALL %s,$%04X', self::CC[$y], $nn), 'kind' => 'call', 'target' => $nn];
        }
        if ($z === 5) {
            if ($q === 0) {
                $qq = self::QQ[$p];
                if ($mode !== 0 && $p === 2) {
                    $qq = $idx;
                }

                return ['length' => 1, 'text' => "PUSH {$qq}", 'kind' => 'code'];
            }
            if ($p === 0) {
                $nn = $imm16();
                if ($nn === null) {
                    return null;
                }

                return ['length' => 3, 'text' => sprintf('CALL $%04X', $nn), 'kind' => 'call', 'target' => $nn];
            }

            return null; // DD/FD/CB/ED slots
        }
        if ($z === 6) {
            $n = $imm8();
            if ($n === null) {
                return null;
            }

            return ['length' => 2, 'text' => sprintf(self::ALU[$y].'$%02X', $n), 'kind' => 'code'];
        }

        return ['length' => 1, 'text' => sprintf('RST $%02X', $y * 8), 'kind' => 'call', 'target' => $y * 8];
    }

    /** @return array{length: int, text: string, kind: string, target?: int}|null */
    private function decodeCB(string $bytes, int $offset, int $address, int $mode, ?int $disp): ?array
    {
        $length = strlen($bytes);
        // $offset points at CB (plain) or at CB after prefix (indexed).
        $opPos = $mode === 0 ? $offset + 1 : $offset + 2;
        if ($opPos >= $length) {
            return null;
        }
        $op = ord($bytes[$opPos]);
        $x = $op >> 6;
        $y = ($op >> 3) & 7;
        $z = $op & 7;

        $idx = $mode === 1 ? 'IX' : ($mode === 2 ? 'IY' : null);
        if ($mode === 0) {
            $target = self::REG[$z];
            $len = 2;
        } else {
            if ($disp === null) {
                return null;
            }
            // CB + displacement + operand; the DD/FD prefix bytes are
            // added by the caller.
            $target = sprintf('(%s%s)', $idx, $this->signedDisp($disp));
            $len = 3;
        }

        if ($x === 0) {
            $mnem = ['RLC', 'RRC', 'RL', 'RR', 'SLA', 'SRA', 'SLL', 'SRL'][$y];
            $text = "{$mnem} {$target}";
            // DDCB/FDCB with z != 6 also stores into r[z].
            if ($mode !== 0 && $z !== 6) {
                $regs = $mode === 1 ? self::REG_IX : self::REG_IY;
                $text .= ','.$regs[$z];
            }

            return ['length' => $len, 'text' => $text, 'kind' => 'code'];
        }
        if ($x === 1) {
            return ['length' => $len, 'text' => "BIT {$y},{$target}", 'kind' => 'code'];
        }
        if ($x === 2) {
            $text = "RES {$y},{$target}";
            if ($mode !== 0 && $z !== 6) {
                $regs = $mode === 1 ? self::REG_IX : self::REG_IY;
                $text .= ','.$regs[$z];
            }

            return ['length' => $len, 'text' => $text, 'kind' => 'code'];
        }
        $text = "SET {$y},{$target}";
        if ($mode !== 0 && $z !== 6) {
            $regs = $mode === 1 ? self::REG_IX : self::REG_IY;
            $text .= ','.$regs[$z];
        }

        return ['length' => $len, 'text' => $text, 'kind' => 'code'];
    }

    /** @return array{length: int, text: string, kind: string, target?: int}|null */
    private function decodeED(int $op, string $bytes, int $offset, int $address): ?array
    {
        $length = strlen($bytes);
        $x = $op >> 6;
        $y = ($op >> 3) & 7;
        $z = $op & 7;
        $p = $y >> 1;
        $q = $y & 1;

        $imm16 = function () use ($bytes, $offset, $length): ?int {
            if ($offset + 3 >= $length) {
                return null;
            }

            return ord($bytes[$offset + 2]) | (ord($bytes[$offset + 3]) << 8);
        };

        if ($x === 0 || $x === 3) {
            return ['length' => 2, 'text' => 'NOP', 'kind' => 'code'];
        }

        if ($x === 2) {
            if ($y < 4 || $z > 3) {
                return ['length' => 2, 'text' => 'NOP', 'kind' => 'code'];
            }
            $block = [
                'LDI', 'CPI', 'INI', 'OUTI', 'LDD', 'CPD', 'IND', 'OUTD',
                'LDIR', 'CPIR', 'INIR', 'OTIR', 'LDDR', 'CPDR', 'INDR', 'OTDR',
            ];

            return ['length' => 2, 'text' => $block[($y - 4) * 4 + $z], 'kind' => 'code'];
        }

        // x === 1
        if ($z === 0) {
            return ['length' => 2, 'text' => $y === 6 ? 'IN (C)' : 'IN '.self::REG[$y].',(C)', 'kind' => 'code'];
        }
        if ($z === 1) {
            return ['length' => 2, 'text' => $y === 6 ? 'OUT (C),0' : 'OUT (C),'.self::REG[$y], 'kind' => 'code'];
        }
        if ($z === 2) {
            $dd = self::DD[$p];

            return ['length' => 2, 'text' => ($q === 0 ? "SBC HL,{$dd}" : "ADC HL,{$dd}"), 'kind' => 'code'];
        }
        if ($z === 3) {
            $nn = $imm16();
            if ($nn === null) {
                return null;
            }
            $dd = self::DD[$p];

            return $q === 0
                ? ['length' => 4, 'text' => sprintf('LD ($%04X),%s', $nn, $dd), 'kind' => 'code']
                : ['length' => 4, 'text' => sprintf('LD %s,($%04X)', $dd, $nn), 'kind' => 'code'];
        }
        if ($z === 4) {
            return ['length' => 2, 'text' => 'NEG', 'kind' => 'code'];
        }
        if ($z === 5) {
            if ($y === 3) {
                return ['length' => 2, 'text' => 'RETI', 'kind' => 'return'];
            }
            if ($y % 2 === 1) {
                return ['length' => 2, 'text' => 'RETN', 'kind' => 'return'];
            }

            return null; // y even: prefix slots
        }
        if ($z === 6) {
            $mode = match (true) {
                $y === 0 || $y === 4 => 'IM 0',
                $y === 1 || $y === 5 => 'IM 0/1',
                $y === 2 || $y === 6 => 'IM 1',
                default => 'IM 2',
            };

            return ['length' => 2, 'text' => $mode, 'kind' => 'code'];
        }
        // z === 7
        $text = match ($y) {
            0 => 'LD I,A',
            1 => 'LD R,A',
            2 => 'LD A,I',
            3 => 'LD A,R',
            4 => 'RRD',
            5 => 'RLD',
            default => 'NOP',
        };

        return ['length' => 2, 'text' => $text, 'kind' => 'code'];
    }

    private function signedDisp(int $disp): string
    {
        if ($disp === 0) {
            return '';
        }
        if ($disp < 0x80) {
            return sprintf('+$%02X', $disp);
        }

        return sprintf('-$%02X', 256 - $disp);
    }
}
