<?php

namespace App\Services\Binary\Disasm;

/**
 * ARM A32 (ARM-state) decoder: common integer core, little-endian.
 *
 * Covered: data processing (immediate, register, shifted-register, all
 * 16 opcodes incl. conditions and S flag), MUL/MLA/UMULL/SMULL/UMLAL/
 * SMLAL, LDR/STR (+B, immediate/register offset, pre/post, writeback),
 * LDRH/STRH, LDM/STM (all addressing modes), B/BL/BLX/BX/BLX-reg, MRS,
 * MSR (register + immediate), SVC, SWP, CLZ, BKPT.
 *
 * Deliberately NOT covered (emitted as `.word` data, never guessed):
 * coprocessor (CDP/MCR/MRC/LDC/STC), NEON/VFP, signed/dual halfword
 * loads (LDRSB/LDRSH/LDRD/STRD), exclusive access (LDREX/STREX),
 * hints (NOPv7/YIELD/WFE/WFI/SEV), PLD/PLI, CPS/SETEND, SSAT/USAT,
 * PKH, parallel add/sub, signed-multiply media (SMUL/SMLAW/...),
 * ERET/HVC/SMC, and NV-conditioned encodings (unpredictable; the one
 * exception is BLX-immediate, which steals the NV slot).
 *
 * Misaligned addresses refuse (null) so the sweep steps byte-wise to
 * the next word boundary instead of decoding shifted garbage.
 */
final class Arm32Decoder implements InstructionDecoder
{
    public function id(): string
    {
        return 'arm32';
    }

    public function name(): string
    {
        return 'ARM A32 (ARM state)';
    }

    public function addressBits(): int
    {
        return 32;
    }

    public function decode(string $bytes, int $offset, int $address): ?array
    {
        if (($address & 3) !== 0 || $offset + 4 > strlen($bytes)) {
            return null;
        }

        $w = ord($bytes[$offset])
            | (ord($bytes[$offset + 1]) << 8)
            | (ord($bytes[$offset + 2]) << 16)
            | (ord($bytes[$offset + 3]) << 24);

        // BLX immediate steals the NV condition slot (FA/FB prefix).
        if (($w & 0xFE000000) === 0xFA000000) {
            $off = $this->signExtend($w & 0xFFFFFF, 24) << 2 | ((($w >> 24) & 1) << 1);
            $target = ($address + 8 + $off) & 0xFFFFFFFF;

            return ['length' => 4, 'text' => sprintf('blx 0x%x', $target), 'kind' => 'call', 'target' => $target];
        }

        $cond = ($w >> 28) & 0xF;
        if ($cond === 0xF) {
            return $this->word($w);
        }
        $cs = $cond === 0xE ? '' : $this->condName($cond);

        // BX / BLX-register (exact masks).
        if (($w & 0x0FFFFFF0) === 0x012FFF10) {
            $rm = $w & 0xF;

            return ['length' => 4, 'text' => 'bx '.$this->reg($rm), 'kind' => $rm === 14 ? 'return' : 'jump'];
        }
        if (($w & 0x0FFFFFF0) === 0x012FFF30) {
            return ['length' => 4, 'text' => 'blx '.$this->reg($w & 0xF), 'kind' => 'call'];
        }

        // MRS / MSR-register (exact masks; checked before generic DP).
        if ((($w >> 16) & 0xFBF) === 0x10F && ($w & 0xFFF) === 0) {
            $psr = (($w >> 22) & 1) === 1 ? 'spsr' : 'cpsr';

            return ['length' => 4, 'text' => sprintf('mrs%s %s, %s', $cs, $this->reg(($w >> 12) & 0xF), $psr), 'kind' => 'code'];
        }
        if ((($w >> 20) & 0xFB) === 0x12 && (($w >> 4) & 0xFFF) === 0xF00) {
            $psr = (($w >> 22) & 1) === 1 ? 'spsr' : 'cpsr';

            return ['length' => 4, 'text' => sprintf('msr%s %s_%s, %s', $cs, $psr, $this->psrFields(($w >> 16) & 0xF), $this->reg($w & 0xF)), 'kind' => 'code'];
        }
        // MSR-immediate shares the 001 prefix with DP-immediate.
        if (($w & 0x0FB00000) === 0x03200000 && (($w >> 12) & 0xF) === 0xF) {
            $psr = (($w >> 22) & 1) === 1 ? 'spsr' : 'cpsr';
            $value = $this->ror($w & 0xFF, ((($w >> 8) & 0xF) * 2));

            return ['length' => 4, 'text' => sprintf('msr%s %s_%s, %s', $cs, $psr, $this->psrFields(($w >> 16) & 0xF), $this->imm($value)), 'kind' => 'code'];
        }

        // MUL / MLA.
        if (($w & 0x0FC000F0) === 0x00000090) {
            $op = (($w >> 21) & 1) === 1 ? 'mla' : 'mul';
            $s = (($w >> 20) & 1) === 1 ? 's' : '';
            $text = $op === 'mla'
                ? sprintf('%s%s%s %s, %s, %s, %s', $op, $cs, $s, $this->reg(($w >> 16) & 0xF), $this->reg($w & 0xF), $this->reg(($w >> 8) & 0xF), $this->reg(($w >> 12) & 0xF))
                : sprintf('%s%s%s %s, %s, %s', $op, $cs, $s, $this->reg(($w >> 16) & 0xF), $this->reg($w & 0xF), $this->reg(($w >> 8) & 0xF));

            return ['length' => 4, 'text' => $text, 'kind' => 'code'];
        }

        // UMULL / UMLAL / SMULL / SMLAL.
        if (($w & 0x0F8000F0) === 0x00800090) {
            $u = ($w >> 22) & 1;
            $a = ($w >> 21) & 1;
            $op = ($u === 1 ? 's' : 'u').'mull';
            $op = $a === 1 ? substr($op, 0, 1).'mlal' : $op;
            $s = (($w >> 20) & 1) === 1 ? 's' : '';

            return ['length' => 4, 'text' => sprintf('%s%s%s %s, %s, %s, %s', $op, $cs, $s, $this->reg(($w >> 12) & 0xF), $this->reg(($w >> 16) & 0xF), $this->reg($w & 0xF), $this->reg(($w >> 8) & 0xF)), 'kind' => 'code'];
        }

        // SWP / SWPB.
        if (($w & 0x0FB00FF0) === 0x01000090) {
            $b = (($w >> 22) & 1) === 1 ? 'b' : '';

            return ['length' => 4, 'text' => sprintf('swp%s%s %s, %s, [%s]', $b, $cs, $this->reg(($w >> 12) & 0xF), $this->reg($w & 0xF), $this->reg(($w >> 16) & 0xF)), 'kind' => 'code'];
        }

        // CLZ.
        if (($w & 0x0FFF0FF0) === 0x016F0F10) {
            return ['length' => 4, 'text' => sprintf('clz%s %s, %s', $cs, $this->reg(($w >> 12) & 0xF), $this->reg($w & 0xF)), 'kind' => 'code'];
        }

        // BKPT.
        if (($w & 0x0FF000F0) === 0x01200070) {
            return ['length' => 4, 'text' => sprintf('bkpt%s %s', $cs, $this->imm(($w >> 8) & 0xFFF)), 'kind' => 'code'];
        }

        $class = ($w >> 25) & 7;

        // LDRH / STRH (misc class 000, bits[7:4]=1011 only — signed and
        // dual forms are uncovered and fall through to .word).
        if ($class === 0 && (($w >> 4) & 0xF) === 0xB) {
            return $this->halfword($w, $cs, $address);
        }
        // Remaining 000-class bit4+bit7 patterns are media or uncovered
        // misc forms — never forced through the DP decoder.
        if ($class === 0 && ($w & 0x90) === 0x90) {
            return $this->word($w);
        }

        // B / BL.
        if ($class === 0b101) {
            $link = (($w >> 24) & 1) === 1;
            $target = ($address + 8 + ($this->signExtend($w & 0xFFFFFF, 24) << 2)) & 0xFFFFFFFF;

            return ['length' => 4, 'text' => sprintf('%s%s 0x%x', $link ? 'bl' : 'b', $cs, $target), 'kind' => $link ? 'call' : 'jump', 'target' => $target];
        }

        // SVC.
        if (($w & 0x0F000000) === 0x0F000000) {
            return ['length' => 4, 'text' => sprintf('svc%s %s', $cs, $this->imm($w & 0xFFFFFF)), 'kind' => 'code'];
        }

        // LDM / STM.
        if ($class === 0b100) {
            return $this->blockTransfer($w, $cs);
        }

        // LDR / STR (word/byte).
        if ((($w >> 26) & 3) === 0b01) {
            return $this->singleTransfer($w, $cs, $address);
        }

        // Data processing: immediate, register, shifted-register.
        if ($class === 0b001 || $class === 0b000) {
            return $this->dataProcessing($w, $cs);
        }

        // Coprocessor, NEON/VFP, and anything else: honest data.
        return $this->word($w);
    }

    /** @return array{length: int, text: string, kind: string} */
    private function word(int $w): array
    {
        return ['length' => 4, 'text' => sprintf('.word 0x%08X', $w), 'kind' => 'data'];
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
        return ['eq', 'ne', 'cs', 'cc', 'mi', 'pl', 'vs', 'vc', 'hi', 'ls', 'ge', 'lt', 'gt', 'le', 'al'][$cond];
    }

    private function imm(int $v): string
    {
        return $v <= 255 ? '#'.$v : sprintf('#0x%x', $v);
    }

    private function ror(int $v, int $n): int
    {
        $n %= 32;

        return $n === 0 ? $v : ((($v >> $n) | ($v << (32 - $n))) & 0xFFFFFFFF);
    }

    private function signExtend(int $v, int $bits): int
    {
        return ($v & (1 << ($bits - 1))) !== 0 ? $v - (1 << $bits) : $v;
    }

    private function psrFields(int $mask): string
    {
        $out = (($mask >> 3) & 1) === 1 ? 'f' : '';
        $out .= (($mask >> 2) & 1) === 1 ? 's' : '';
        $out .= (($mask >> 1) & 1) === 1 ? 'x' : '';
        $out .= ($mask & 1) === 1 ? 'c' : '';

        return $out === '' ? 'none' : $out;
    }

    /** @return array{length: int, text: string, kind: string} */
    private function singleTransfer(int $w, string $cs, int $address): array
    {
        $i = ($w >> 25) & 1;
        $p = ($w >> 24) & 1;
        $u = ($w >> 23) & 1;
        $b = ($w >> 22) & 1;
        $wb = ($w >> 21) & 1;
        $load = ($w >> 20) & 1;
        $rn = ($w >> 16) & 0xF;
        $rd = ($w >> 12) & 0xF;
        $op = ($load === 1 ? 'ldr' : 'str').($b === 1 ? 'b' : '');

        if ($i === 1) {
            // Register offset: immediate shift only (bit4 set would be
            // a media instruction aliasing this shape).
            if (($w & 0x10) !== 0) {
                return $this->word($w);
            }
            $shift = $this->shiftText(($w >> 5) & 3, ($w >> 7) & 0x1F, null);
            $off = ', '.($u === 1 ? '' : '-').$this->reg($w & 0xF).$shift;
        } else {
            $imm = $w & 0xFFF;
            $off = $imm === 0 && $rn !== 15 ? '' : ', '.($u === 1 ? '#' : '#-').$imm;
        }

        if ($p === 1) {
            $text = sprintf('%s%s %s, [%s%s]%s', $op, $cs, $this->reg($rd), $this->reg($rn), $off, $wb === 1 ? '!' : '');
        } else {
            $text = sprintf('%s%s %s, [%s]%s', $op, $cs, $this->reg($rd), $this->reg($rn), $off);
        }

        // PC-relative loads resolve: firmware vector reads stay legible.
        if ($rn === 15 && $p === 1 && $i === 0) {
            $resolved = ($address + 8 + ($u === 1 ? ($w & 0xFFF) : -($w & 0xFFF))) & 0xFFFFFFFF;
            $text .= sprintf(' ; 0x%x', $resolved);
        }

        return ['length' => 4, 'text' => $text, 'kind' => $load === 1 && $rd === 15 ? 'jump' : 'code'];
    }

    /** @return array{length: int, text: string, kind: string} */
    private function halfword(int $w, string $cs, int $address): array
    {
        $p = ($w >> 24) & 1;
        $u = ($w >> 23) & 1;
        $wb = ($w >> 21) & 1;
        $load = ($w >> 20) & 1;
        $rn = ($w >> 16) & 0xF;
        $rd = ($w >> 12) & 0xF;
        $op = $load === 1 ? 'ldrh' : 'strh';

        if ((($w >> 22) & 1) === 1) {
            $off = ((($w >> 8) & 0xF) << 4) | ($w & 0xF);
            $off = $off === 0 && $rn !== 15 ? '' : ', '.($u === 1 ? '#' : '#-').$off;
        } else {
            $off = ', '.($u === 1 ? '' : '-').$this->reg($w & 0xF);
        }

        if ($p === 1) {
            $text = sprintf('%s%s %s, [%s%s]%s', $op, $cs, $this->reg($rd), $this->reg($rn), $off, $wb === 1 ? '!' : '');
        } else {
            $text = sprintf('%s%s %s, [%s]%s', $op, $cs, $this->reg($rd), $this->reg($rn), $off === '' ? '' : $off);
        }

        return ['length' => 4, 'text' => $text, 'kind' => $load === 1 && $rd === 15 ? 'jump' : 'code'];
    }

    /** @return array{length: int, text: string, kind: string} */
    private function blockTransfer(int $w, string $cs): array
    {
        $p = ($w >> 24) & 1;
        $u = ($w >> 23) & 1;
        $s = ($w >> 22) & 1;
        $wb = ($w >> 21) & 1;
        $load = ($w >> 20) & 1;
        $rn = ($w >> 16) & 0xF;
        $list = $w & 0xFFFF;
        if ($list === 0) {
            return $this->word($w);
        }

        $suffix = match (true) {
            $p === 0 && $u === 1 => 'ia',
            $p === 1 && $u === 1 => 'ib',
            $p === 0 && $u === 0 => 'da',
            default => 'db',
        };
        $regs = [];
        $start = null;
        $prev = null;
        for ($r = 0; $r <= 16; $r++) {
            $set = $r <= 15 && (($list >> $r) & 1) === 1;
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

        $text = sprintf('%s%s%s %s%s, {%s}%s', $load === 1 ? 'ldm' : 'stm', $suffix, $cs, $this->reg($rn), $wb === 1 ? '!' : '', implode(', ', $regs), $s === 1 ? '^' : '');
        $kind = 'code';
        if ($load === 1 && (($list >> 15) & 1) === 1) {
            $kind = $rn === 13 ? 'return' : 'jump';
        }

        return ['length' => 4, 'text' => $text, 'kind' => $kind];
    }

    /** @return array{length: int, text: string, kind: string, target?: int} */
    private function dataProcessing(int $w, string $cs): array
    {
        $immediate = (($w >> 25) & 7) === 0b001;
        $opcode = ($w >> 21) & 0xF;
        $s = (($w >> 20) & 1) === 1;
        $rn = ($w >> 16) & 0xF;
        $rd = ($w >> 12) & 0xF;

        $names = ['and', 'eor', 'sub', 'rsb', 'add', 'adc', 'sbc', 'rsc', 'tst', 'teq', 'cmp', 'cmn', 'orr', 'mov', 'bic', 'mvn'];
        $op = $names[$opcode];
        $noRd = $opcode >= 8 && $opcode <= 11;
        $sSuffix = ($s && ! $noRd) ? 's' : '';

        if ($immediate) {
            $op2 = $this->imm($this->ror($w & 0xFF, ((($w >> 8) & 0xF) * 2)));
        } else {
            // Signed-multiply media (SMUL/SMLAW/...) share the DP class
            // bits; their [11:8]==0 + S==0 + bit7 shape never matches a
            // legitimate shifted DP operand, so refuse it as data.
            $bucket = ($w >> 22) & 0x3F;
            if (($bucket === 0x04 || $bucket === 0x05) && (($w >> 7) & 1) === 1 && ! $s && (($w >> 8) & 0xF) === 0) {
                return $this->word($w);
            }
            $rm = $this->reg($w & 0xF);
            if (($w & 0x10) === 0) {
                $op2 = $rm.$this->shiftText(($w >> 5) & 3, ($w >> 7) & 0x1F, null);
            } else {
                $op2 = $rm.$this->shiftText(($w >> 5) & 3, null, ($w >> 8) & 0xF);
            }
        }

        if ($noRd) {
            $text = sprintf('%s%s %s, %s', $op, $cs, $this->reg($rn), $op2);
        } elseif ($opcode === 13 || $opcode === 15) {
            $text = sprintf('%s%s%s %s, %s', $op, $cs, $sSuffix, $this->reg($rd), $op2);
        } else {
            $text = sprintf('%s%s%s %s, %s, %s', $op, $cs, $sSuffix, $this->reg($rd), $this->reg($rn), $op2);
        }

        $kind = 'code';
        if ($rd === 15) {
            $kind = ($opcode === 13 && ! $immediate && ($w & 0xF) === 14) ? 'return' : 'jump';
        }

        return ['length' => 4, 'text' => $text, 'kind' => $kind];
    }

    /**
     * Shift suffix: LSL #0 is printed as no shift (canonical MOV form);
     * ROR #0 is RRX; ASR/LSR #0 mean 32.
     */
    private function shiftText(int $type, ?int $amount, ?int $rs): string
    {
        $name = ['lsl', 'lsr', 'asr', 'ror'][$type];
        if ($rs !== null) {
            return ', '.$name.' '.$this->reg($rs);
        }
        if ($name === 'lsl' && $amount === 0) {
            return '';
        }
        if ($name === 'ror' && $amount === 0) {
            return ', rrx';
        }
        if (($name === 'lsr' || $name === 'asr') && $amount === 0) {
            $amount = 32;
        }

        return ', '.$name.' #'.$amount;
    }
}
