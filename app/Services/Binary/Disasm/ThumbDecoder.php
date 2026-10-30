<?php

namespace App\Services\Binary\Disasm;

/**
 * ARM Thumb T32 decoder (16-bit Thumb + 32-bit Thumb-2), little-endian.
 *
 * 16-bit coverage is near-complete: shifts, add/sub (register and
 * immediate), move/compare/add/sub immediate, register data processing,
 * high-register ops, BX/BLX, LDR-literal (with resolved address), all
 * load/store forms (register, 5-bit immediate, SP-relative), ADR,
 * ADD-SP, PUSH/POP, SETEND, REV/REV16/REVSH, sign/zero extension,
 * CBZ/CBNZ, hints (NOP/YIELD/WFE/WFI/SEV/SEVL), LDMIA/STMIA,
 * conditional branches, UDF, SVC, unconditional branch.
 *
 * 32-bit coverage is the common firmware core: BL/BLX, B.W, MOVW/MOVT,
 * ADD.W/SUB.W (12-bit immediate, incl. ADR.W), MRS/MSR with common
 * system-register names, DMB/DSB/ISB. Everything else 32-bit (load/
 * store single and multiple, dual, exclusive, table branches,
 * data-processing modified/shifted immediate and register, media,
 * coprocessor) is emitted as `.word`: 32-bit width is certain for any
 * first halfword >= 0xE800, the decoding is not.
 *
 * Deliberately uncovered 16-bit: CPS (encoding uncertain — kept as
 * data rather than risk a mis-decode), and the op=10 REV slot.
 *
 * B.W guard: inside the F380–F3FF first-halfword block (system
 * instructions) a B.W shape is only accepted with a 0x9xxx second
 * halfword — system encodings all use 0x8xxx seconds, so this can only
 * turn genuinely unallocated patterns into data, never a real system
 * instruction into a branch.
 */
final class ThumbDecoder implements InstructionDecoder
{
    public function id(): string
    {
        return 'arm32-thumb';
    }

    public function name(): string
    {
        return 'ARM Thumb T32 (Thumb/Thumb-2)';
    }

    public function addressBits(): int
    {
        return 32;
    }

    public function decode(string $bytes, int $offset, int $address): ?array
    {
        if (($address & 1) !== 0 || $offset + 2 > strlen($bytes)) {
            return null;
        }

        $h0 = ord($bytes[$offset]) | (ord($bytes[$offset + 1]) << 8);
        if ($h0 < 0xE800) {
            return $this->decode16($h0, $address);
        }

        if ($offset + 4 > strlen($bytes)) {
            return null;
        }
        $h1 = ord($bytes[$offset + 2]) | (ord($bytes[$offset + 3]) << 8);

        return $this->decode32($h0, $h1, $address);
    }

    /** @return array{length: int, text: string, kind: string, target?: int} */
    private function short(int $h): array
    {
        return ['length' => 2, 'text' => sprintf('.short 0x%04X', $h), 'kind' => 'data'];
    }

    /** @return array{length: int, text: string, kind: string} */
    private function word(int $h0, int $h1): array
    {
        return ['length' => 4, 'text' => sprintf('.word 0x%04X%04X', $h1, $h0), 'kind' => 'data'];
    }

    private function reg(int $n): string
    {
        return match ($n) {
            13 => 'sp',
            14 => 'lr',
            15 => 'pc',
            default => 'r'.$n,
        };
    }

    private function condName(int $cond): string
    {
        return ['eq', 'ne', 'cs', 'cc', 'mi', 'pl', 'vs', 'vc', 'hi', 'ls', 'ge', 'lt', 'gt', 'le'][$cond];
    }

    private function imm(int $v): string
    {
        return $v <= 255 ? '#'.$v : sprintf('#0x%x', $v);
    }

    private function signExtend(int $v, int $bits): int
    {
        return ($v & (1 << ($bits - 1))) !== 0 ? $v - (1 << $bits) : $v;
    }

    /** @return array{length: int, text: string, kind: string, target?: int} */
    private function decode16(int $h, int $address): array
    {
        // Shift-immediate / add-sub page (000x). Add/sub first: its mask
        // sits inside the shift page.
        if (($h & 0xFE00) === 0x1800) {
            $op = ($h >> 9) & 3;
            $rd = $h & 7;
            $rn = ($h >> 3) & 7;
            $rm = ($h >> 6) & 7;
            $text = match ($op) {
                0 => sprintf('adds %s, %s, %s', $this->reg($rd), $this->reg($rn), $this->reg($rm)),
                1 => sprintf('subs %s, %s, %s', $this->reg($rd), $this->reg($rn), $this->reg($rm)),
                2 => sprintf('adds %s, %s, #%d', $this->reg($rd), $this->reg($rn), $rm),
                default => sprintf('subs %s, %s, #%d', $this->reg($rd), $this->reg($rn), $rm),
            };

            return ['length' => 2, 'text' => $text, 'kind' => 'code'];
        }
        if (($h & 0xE000) === 0x0000) {
            $op = ($h >> 11) & 3;
            $imm5 = ($h >> 6) & 0x1F;
            $rd = $h & 7;
            $rm = ($h >> 3) & 7;
            if ($op === 0 && $imm5 === 0) {
                return ['length' => 2, 'text' => sprintf('movs %s, %s', $this->reg($rd), $this->reg($rm)), 'kind' => 'code'];
            }
            $name = ['lsls', 'lsrs', 'asrs'][$op] ?? null;
            if ($name === null) {
                return $this->short($h);
            }
            $amount = ($op !== 0 && $imm5 === 0) ? 32 : $imm5;

            return ['length' => 2, 'text' => sprintf('%s %s, %s, #%d', $name, $this->reg($rd), $this->reg($rm), $amount), 'kind' => 'code'];
        }

        // Move/compare/add/sub immediate.
        if (($h & 0xE000) === 0x2000) {
            $op = ($h >> 11) & 3;
            $name = ['movs', 'cmp', 'adds', 'subs'][$op];

            return ['length' => 2, 'text' => sprintf('%s %s, #%d', $name, $this->reg(($h >> 8) & 7), $h & 0xFF), 'kind' => 'code'];
        }

        // Register data processing.
        if (($h & 0xFC00) === 0x4000) {
            return $this->dpReg($h);
        }

        // High-register ops, BX/BLX.
        if (($h & 0xFC00) === 0x4400) {
            return $this->hiReg($h);
        }

        // LDR literal (resolved address appended for legibility).
        if (($h & 0xF800) === 0x4800) {
            $off = ($h & 0xFF) * 4;
            $resolved = (($address + 4) & ~3) + $off;

            return ['length' => 2, 'text' => sprintf('ldr %s, [pc, #%d] ; 0x%x', $this->reg(($h >> 8) & 7), $off, $resolved), 'kind' => 'code'];
        }

        // Load/store register-offset.
        if (($h & 0xFE00) === 0x5000) {
            $l = ($h >> 11) & 1;
            $b = ($h >> 10) & 1;
            $name = ($l === 1 ? 'ldr' : 'str').($b === 1 ? 'b' : '');

            return ['length' => 2, 'text' => sprintf('%s %s, [%s, %s]', $name, $this->reg($h & 7), $this->reg(($h >> 3) & 7), $this->reg(($h >> 6) & 7)), 'kind' => 'code'];
        }
        if (($h & 0xFE00) === 0x5200) {
            $name = ['strh', 'ldrsb', 'ldrh', 'ldrsh'][($h >> 10) & 3];

            return ['length' => 2, 'text' => sprintf('%s %s, [%s, %s]', $name, $this->reg($h & 7), $this->reg(($h >> 3) & 7), $this->reg(($h >> 6) & 7)), 'kind' => 'code'];
        }

        // Load/store 5-bit immediate.
        if (($h & 0xE000) === 0x6000) {
            $b = ($h >> 12) & 1;
            $l = ($h >> 11) & 1;
            $name = ($l === 1 ? 'ldr' : 'str').($b === 1 ? 'b' : '');
            $off = (($h >> 6) & 0x1F) * ($b === 1 ? 1 : 4);
            $mem = $off === 0
                ? sprintf('[%s]', $this->reg(($h >> 3) & 7))
                : sprintf('[%s, #%d]', $this->reg(($h >> 3) & 7), $off);

            return ['length' => 2, 'text' => sprintf('%s %s, %s', $name, $this->reg($h & 7), $mem), 'kind' => 'code'];
        }
        if (($h & 0xF000) === 0x8000) {
            $name = ((($h >> 11) & 1) === 1) ? 'ldrh' : 'strh';
            $off = (($h >> 6) & 0x1F) * 2;
            $mem = $off === 0
                ? sprintf('[%s]', $this->reg(($h >> 3) & 7))
                : sprintf('[%s, #%d]', $this->reg(($h >> 3) & 7), $off);

            return ['length' => 2, 'text' => sprintf('%s %s, %s', $name, $this->reg($h & 7), $mem), 'kind' => 'code'];
        }

        // SP-relative load/store.
        if (($h & 0xF000) === 0x9000) {
            $name = ((($h >> 11) & 1) === 1) ? 'ldr' : 'str';

            return ['length' => 2, 'text' => sprintf('%s %s, [sp, #%d]', $name, $this->reg(($h >> 8) & 7), ($h & 0xFF) * 4), 'kind' => 'code'];
        }

        // ADR / ADD (SP plus immediate).
        if (($h & 0xF800) === 0xA000) {
            $resolved = (($address + 4) & ~3) + ($h & 0xFF) * 4;

            return ['length' => 2, 'text' => sprintf('adr %s, 0x%x', $this->reg(($h >> 8) & 7), $resolved), 'kind' => 'code'];
        }
        if (($h & 0xF800) === 0xA800) {
            $s = ($h >> 11) & 1;
            $rd = ($h >> 8) & 7;
            $off = ($h & 0xFF) * 4;
            if ($s === 1 && $rd !== 5) {
                return $this->short($h);
            }
            $text = $s === 1 ? sprintf('add sp, sp, #%d', $off) : sprintf('add %s, sp, #%d', $this->reg($rd), $off);

            return ['length' => 2, 'text' => $text, 'kind' => 'code'];
        }

        // Miscellaneous 16-bit page (0xB000): order matters, masks nest.
        if (($h & 0xF000) === 0xB000) {
            return $this->misc16($h, $address);
        }

        // LDMIA / STMIA.
        if (($h & 0xF000) === 0xC000) {
            $list = $h & 0xFF;
            if ($list === 0) {
                return $this->short($h);
            }
            $name = ((($h >> 11) & 1) === 1) ? 'ldmia' : 'stmia';

            return ['length' => 2, 'text' => sprintf('%s %s!, {%s}', $name, $this->reg(($h >> 8) & 7), $this->regList($list, false)), 'kind' => 'code'];
        }

        // Conditional branch / UDF / SVC.
        if (($h & 0xF000) === 0xD000) {
            $cond = ($h >> 8) & 0xF;
            if ($cond === 0xE) {
                return ['length' => 2, 'text' => sprintf('udf #%d', $h & 0xFF), 'kind' => 'code'];
            }
            if ($cond === 0xF) {
                return ['length' => 2, 'text' => sprintf('svc #%d', $h & 0xFF), 'kind' => 'code'];
            }
            $target = ($address + 4 + ($this->signExtend($h & 0xFF, 8) << 1)) & 0xFFFFFFFF;

            return ['length' => 2, 'text' => sprintf('b%s 0x%x', $this->condName($cond), $target), 'kind' => 'branch', 'target' => $target];
        }

        // Unconditional branch (0xE000–0xE7FF; 0xE800+ is 32-bit).
        $target = ($address + 4 + ($this->signExtend($h & 0x7FF, 11) << 1)) & 0xFFFFFFFF;

        return ['length' => 2, 'text' => sprintf('b 0x%x', $target), 'kind' => 'jump', 'target' => $target];
    }

    /** @return array{length: int, text: string, kind: string} */
    private function dpReg(int $h): array
    {
        $op = ($h >> 6) & 0xF;
        $rs = $this->reg(($h >> 3) & 7);
        $rd = $this->reg($h & 7);
        $text = match ($op) {
            0 => "ands $rd, $rs",
            1 => "eors $rd, $rs",
            2 => "lsls $rd, $rs",
            3 => "lsrs $rd, $rs",
            4 => "asrs $rd, $rs",
            5 => "adcs $rd, $rs",
            6 => "sbcs $rd, $rs",
            7 => "rors $rd, $rs",
            8 => "tst $rd, $rs",
            9 => "rsbs $rd, $rs, #0",
            10 => "cmp $rd, $rs",
            11 => "cmn $rd, $rs",
            12 => "orrs $rd, $rs",
            13 => "muls $rd, $rs",
            14 => "bics $rd, $rs",
            default => "mvns $rd, $rs",
        };

        return ['length' => 2, 'text' => $text, 'kind' => 'code'];
    }

    /** @return array{length: int, text: string, kind: string} */
    private function hiReg(int $h): array
    {
        $op = ($h >> 8) & 3;
        $dn = (($h >> 7) & 1) << 3 | ($h & 7);
        $m = (($h >> 6) & 1) << 3 | (($h >> 3) & 7);

        if ($op === 3) {
            if (($h & 7) !== 0) {
                return $this->short($h);
            }
            if ((($h >> 7) & 1) === 1) {
                return ['length' => 2, 'text' => 'blx '.$this->reg($m), 'kind' => 'call'];
            }

            return ['length' => 2, 'text' => 'bx '.$this->reg($m), 'kind' => $m === 14 ? 'return' : 'jump'];
        }
        if ($op === 1) {
            return ['length' => 2, 'text' => 'cmp '.$this->reg($dn).', '.$this->reg($m), 'kind' => 'code'];
        }
        $text = $op === 0
            ? 'add '.$this->reg($dn).', '.$this->reg($m)
            : 'mov '.$this->reg($dn).', '.$this->reg($m);

        return ['length' => 2, 'text' => $text, 'kind' => $op === 2 && $dn === 15 ? ($m === 14 ? 'return' : 'jump') : 'code'];
    }

    /** @return array{length: int, text: string, kind: string, target?: int} */
    private function misc16(int $h, int $address): array
    {
        // ADD/SUB SP immediate (checked before CBZ: 0xB000 matches both).
        if (($h & 0xFF00) === 0xB000) {
            $name = (($h >> 7) & 1) === 1 ? 'sub' : 'add';

            return ['length' => 2, 'text' => sprintf('%s sp, #%d', $name, $h & 0x7F), 'kind' => 'code'];
        }

        // Sign/zero extension.
        if (($h & 0xFF00) === 0xB200) {
            $name = ['sxth', 'sxtb', 'uxth', 'uxtb'][($h >> 6) & 3];

            return ['length' => 2, 'text' => sprintf('%s %s, %s', $name, $this->reg($h & 7), $this->reg(($h >> 3) & 7)), 'kind' => 'code'];
        }

        // CBZ / CBNZ: op=[11], [10:9]==0 (mask), i=[8], imm5=[7:3].
        if (($h & 0xFE00) === 0xB000 || ($h & 0xFE00) === 0xB800) {
            $offset = ((($h >> 8) & 1) << 6) | ((($h >> 3) & 0x1F) << 1);
            $target = ($address + 4 + $offset) & 0xFFFFFFFF;
            $name = (($h >> 11) & 1) === 1 ? 'cbnz' : 'cbz';

            return ['length' => 2, 'text' => sprintf('%s %s, 0x%x', $name, $this->reg($h & 7), $target), 'kind' => 'branch', 'target' => $target];
        }

        // PUSH / POP.
        if (($h & 0xFE00) === 0xB400 || ($h & 0xFE00) === 0xBC00) {
            $pop = ($h & 0xFE00) === 0xBC00;
            $extra = (($h >> 8) & 1) === 1;
            $list = $h & 0xFF;
            if ($list === 0 && ! $extra) {
                return $this->short($h);
            }
            $text = sprintf('%s {%s}', $pop ? 'pop' : 'push', $this->regList($list, $extra ? ($pop ? 15 : 14) : null));
            $kind = ($pop && $extra) ? 'return' : 'code';

            return ['length' => 2, 'text' => $text, 'kind' => $kind];
        }

        // SETEND.
        if (($h & 0xFFF7) === 0xB650) {
            return ['length' => 2, 'text' => ((($h >> 3) & 1) === 1) ? 'setend be' : 'setend le', 'kind' => 'code'];
        }

        // Byte/halfword reverse.
        if (($h & 0xFF00) === 0xBA00) {
            $op = ($h >> 6) & 3;
            if ($op === 2) {
                return $this->short($h);
            }
            $name = [0 => 'rev', 1 => 'rev16', 3 => 'revsh'][$op];

            return ['length' => 2, 'text' => sprintf('%s %s, %s', $name, $this->reg($h & 7), $this->reg(($h >> 3) & 7)), 'kind' => 'code'];
        }

        // BKPT.
        if (($h & 0xFF00) === 0xBE00) {
            return ['length' => 2, 'text' => sprintf('bkpt #%d', $h & 0xFF), 'kind' => 'code'];
        }

        // Hints (NOP/YIELD/WFE/WFI/SEV/SEVL).
        if (($h & 0xFF00) === 0xBF00) {
            if (($h & 0xF) !== 0) {
                return $this->short($h);
            }
            $name = [0 => 'nop', 1 => 'yield', 2 => 'wfe', 3 => 'wfi', 4 => 'sev', 5 => 'sevl'][($h >> 4) & 0xF] ?? null;
            if ($name === null) {
                return $this->short($h);
            }

            return ['length' => 2, 'text' => $name, 'kind' => 'code'];
        }

        // CPS and anything else on this page: honest data.
        return $this->short($h);
    }

    private function regList(int $list, ?int $extra): string
    {
        $regs = [];
        $start = null;
        $prev = null;
        for ($r = 0; $r <= 8; $r++) {
            $set = $r <= 7 && (($list >> $r) & 1) === 1;
            if ($set && $start === null) {
                $start = $r;
                $prev = $r;
            } elseif ($set) {
                $prev = $r;
            } elseif ($start !== null) {
                // Collapse runs of 3+; pairs read better expanded.
                if ($prev - $start < 2) {
                    for ($q = $start; $q <= $prev; $q++) {
                        $regs[] = 'r'.$q;
                    }
                } else {
                    $regs[] = 'r'.$start.'-r'.$prev;
                }
                $start = null;
            }
        }
        if ($extra !== null) {
            $regs[] = $this->reg($extra);
        }

        return implode(', ', $regs);
    }

    /** @return array{length: int, text: string, kind: string, target?: int} */
    private function decode32(int $h0, int $h1, int $address): array
    {
        // MRS / MSR (exact first-halfword masks win over branch shapes).
        if (($h1 & 0xF000) === 0x8000) {
            if ($h0 === 0xF3EF) {
                $name = $this->sysmName(($h1 >> 4) & 0xFF);
                if ($name !== null) {
                    return ['length' => 4, 'text' => 'mrs '.$this->reg($h1 & 0xF).', '.$name, 'kind' => 'code'];
                }

                return $this->word($h0, $h1);
            }
            if (($h0 & 0xFFF0) === 0xF380) {
                $name = $this->sysmName(($h1 >> 4) & 0xFF);
                if ($name !== null) {
                    return ['length' => 4, 'text' => 'msr '.$name.', '.$this->reg($h0 & 0xF), 'kind' => 'code'];
                }

                return $this->word($h0, $h1);
            }
            if ($h0 === 0xF3BF) {
                $barrier = match (($h1 >> 4) & 0xF) {
                    0x5 => 'dmb',
                    0x4 => 'dsb',
                    0x6 => 'isb',
                    default => null,
                };
                if ($barrier !== null && (($h1 >> 8) & 0xF) === 0xF) {
                    $opt = [0xF => 'sy', 0xE => 'st', 0xD => 'ish', 0xC => 'ishst', 0xB => 'nsh', 0xA => 'nshst', 0x7 => 'osh', 0x6 => 'oshst', 0x3 => 'un'][$h1 & 0xF] ?? sprintf('#0x%x', $h1 & 0xF);

                    return ['length' => 4, 'text' => $barrier.' '.$opt, 'kind' => 'code'];
                }
            }
        }

        if (($h0 >> 11) === 0b11110) {
            // BL / BLX (second halfword 11xx: unambiguous in this class).
            if (($h1 >> 14) === 3) {
                $target = ($address + 4 + $this->branchOffset($h0, $h1)) & 0xFFFFFFFF;
                $name = ((($h1 >> 12) & 1) === 1) ? 'bl' : 'blx';

                return ['length' => 4, 'text' => sprintf('%s 0x%x', $name, $target), 'kind' => 'call', 'target' => $target];
            }
            // B.W (second halfword 10x0): excluded inside the system
            // block unless the second halfword is 0x9xxx (system
            // encodings all use 0x8xxx seconds).
            if (($h1 >> 14) === 2 && (($h1 >> 12) & 1) === 0
                && ($h0 < 0xF380 || $h0 > 0xF3FF || ($h1 & 0xF000) === 0x9000)) {
                $target = ($address + 4 + $this->branchOffset($h0, $h1)) & 0xFFFFFFFF;

                return ['length' => 4, 'text' => sprintf('b.w 0x%x', $target), 'kind' => 'jump', 'target' => $target];
            }
        }

        // MOVW / MOVT.
        if (($h0 & 0xF7F0) === 0xF240 || ($h0 & 0xF7F0) === 0xF2C0) {
            $imm = ((($h0 >> 10) & 1) << 16) | (($h0 & 0xF) << 12) | ((($h1 >> 12) & 7) << 8) | ($h1 & 0xFF);
            $name = (($h0 >> 7) & 1) === 1 ? 'movt' : 'movw';

            return ['length' => 4, 'text' => sprintf('%s %s, %s', $name, $this->reg(($h1 >> 8) & 0xF), $this->imm($imm)), 'kind' => 'code'];
        }

        // ADD.W / SUB.W / ADR.W (12-bit immediate).
        if (($h0 & 0xF7F0) === 0xF200 || ($h0 & 0xF7F0) === 0xF2A0) {
            $sub = ($h0 & 0xF7F0) === 0xF2A0;
            $imm12 = ((($h0 >> 10) & 1) << 11) | ((($h1 >> 12) & 7) << 8) | ($h1 & 0xFF);
            $rn = $h0 & 0xF;
            $rd = ($h1 >> 8) & 0xF;
            if ($rn === 15) {
                $base = ($address + 4) & ~3;
                $resolved = ($sub ? $base - $imm12 : $base + $imm12) & 0xFFFFFFFF;

                return ['length' => 4, 'text' => sprintf('adr %s, 0x%x', $this->reg($rd), $resolved), 'kind' => 'code'];
            }
            $name = $sub ? 'sub.w' : 'add.w';

            return ['length' => 4, 'text' => sprintf('%s %s, %s, %s', $name, $this->reg($rd), $this->reg($rn), $this->imm($imm12)), 'kind' => 'code'];
        }

        return $this->word($h0, $h1);
    }

    /**
     * Shared BL/BLX/B.W offset: S:I1:I2:imm10:imm11:0, sign-extended
     * from 25 bits. I1 = NOT(J1 XOR S), I2 = NOT(J2 XOR S).
     */
    private function branchOffset(int $h0, int $h1): int
    {
        $s = ($h0 >> 10) & 1;
        $i1 = (($h1 >> 13) & 1) === $s ? 1 : 0;
        $i2 = (($h1 >> 11) & 1) === $s ? 1 : 0;
        $raw = ($s << 24) | ($i1 << 23) | ($i2 << 22) | (($h0 & 0x3FF) << 12) | (($h1 & 0x7FF) << 1);

        return $this->signExtend($raw, 25);
    }

    private function sysmName(int $sysm): ?string
    {
        return match ($sysm) {
            0x00 => 'apsr',
            0x01 => 'iapsr',
            0x02 => 'eapsr',
            0x03 => 'xpsr',
            0x05 => 'ipsr',
            0x06 => 'epsr',
            0x07 => 'iepsr',
            0x08 => 'msp',
            0x09 => 'psp',
            0x10 => 'primask',
            0x11 => 'basepri',
            0x12 => 'basepri_max',
            0x13 => 'faultmask',
            0x14 => 'control',
            default => null,
        };
    }
}
