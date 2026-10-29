<?php

namespace App\Services\Binary;

use App\Models\ProjectArtifact;

/**
 * Processor-target registry. Every id maps to one concrete
 * architecture variant — never a whole family. The `native`
 * flag marks targets the bundled pure-PHP adapter can actually
 * disassemble here and now; everything else needs an external
 * backend (see Adapters) and is reported honestly as such.
 *
 * ARM naming: `arm32` is 32-bit ARM state; `arm32-thumb` is
 * Thumb-2/Thumb state. Architecture versions (ARMv5) and core
 * families (ARM7/ARM9) are device context, not target ids —
 * request the exact part when it matters.
 */
final class Targets
{
    /**
     * @var array<string, array{name: string, arch: string, bits: int, endian: string, native: bool, note: string}>
     */
    private const TARGETS = [
        'mos6502' => ['name' => 'MOS 6502 / 6510', 'arch' => 'mos6502', 'bits' => 16, 'endian' => 'little', 'native' => true, 'note' => 'NMOS 6502; covers the 6510 CPU in C64 platform context.'],
        'z80' => ['name' => 'Zilog Z80', 'arch' => 'z80', 'bits' => 16, 'endian' => 'little', 'native' => true, 'note' => 'Full map incl. CB/ED/DD/FD. 8080/8085 object code largely decodes but is not a verified target.'],
        'i8051' => ['name' => 'Intel 8051', 'arch' => 'i8051', 'bits' => 16, 'endian' => 'little', 'native' => true, 'note' => 'Classic core; 0xA5 reserved. Silicon variants (SFR layout) need device context.'],
        'x86' => ['name' => 'x86 (32-bit)', 'arch' => 'x86', 'bits' => 32, 'endian' => 'little', 'native' => false, 'note' => 'Needs the Ghidra backend (not installed).'],
        'x86-64' => ['name' => 'x86-64', 'arch' => 'x86-64', 'bits' => 64, 'endian' => 'little', 'native' => false, 'note' => 'Needs the Ghidra backend (not installed).'],
        'arm32' => ['name' => 'ARM 32-bit (ARM state)', 'arch' => 'arm32', 'bits' => 32, 'endian' => 'little', 'native' => false, 'note' => 'Needs the Ghidra backend (not installed). Exact core/ARM version from device context.'],
        'arm32-thumb' => ['name' => 'ARM 32-bit (Thumb state)', 'arch' => 'arm32', 'bits' => 32, 'endian' => 'little', 'native' => false, 'note' => 'Thumb vs ARM state must be known per function; needs the Ghidra backend (not installed).'],
        'aarch64' => ['name' => 'AArch64', 'arch' => 'aarch64', 'bits' => 64, 'endian' => 'little', 'native' => false, 'note' => 'Needs the Ghidra backend (not installed).'],
        'mips32' => ['name' => 'MIPS32', 'arch' => 'mips', 'bits' => 32, 'endian' => 'either', 'native' => false, 'note' => 'Endianness per file; needs the Ghidra backend (not installed).'],
        'riscv32' => ['name' => 'RISC-V 32-bit', 'arch' => 'riscv32', 'bits' => 32, 'endian' => 'little', 'native' => false, 'note' => 'Needs the Ghidra backend (not installed).'],
        'riscv64' => ['name' => 'RISC-V 64-bit', 'arch' => 'riscv64', 'bits' => 64, 'endian' => 'little', 'native' => false, 'note' => 'Needs the Ghidra backend (not installed).'],
        'ppc32' => ['name' => 'PowerPC 32-bit', 'arch' => 'ppc', 'bits' => 32, 'endian' => 'big', 'native' => false, 'note' => 'Needs the Ghidra backend (not installed).'],
        'avr' => ['name' => 'AVR 8-bit (classic)', 'arch' => 'avr', 'bits' => 16, 'endian' => 'little', 'native' => false, 'note' => 'Classic AVR only — AVR32 is a different architecture. Needs a verified backend.'],
        'pic18' => ['name' => 'PIC18', 'arch' => 'pic18', 'bits' => 16, 'endian' => 'little', 'native' => false, 'note' => 'Needs a verified backend; baseline/mid-range/PIC24/dsPIC/PIC32 are separate targets.'],
        'msp430' => ['name' => 'MSP430', 'arch' => 'msp430', 'bits' => 16, 'endian' => 'little', 'native' => false, 'note' => 'Needs a verified backend (not installed).'],
        'dalvik' => ['name' => 'Dalvik bytecode', 'arch' => 'dalvik', 'bits' => 32, 'endian' => 'little', 'native' => false, 'note' => 'Metadata only in the bundled adapter; reconstruction needs JADX (not installed).'],
        'jvm' => ['name' => 'Java bytecode', 'arch' => 'jvm', 'bits' => 32, 'endian' => 'big', 'native' => false, 'note' => 'Metadata only in the bundled adapter; reconstruction needs JADX (not installed).'],
        'clr' => ['name' => '.NET CIL', 'arch' => 'clr', 'bits' => 32, 'endian' => 'little', 'native' => false, 'note' => 'Runtime detection only; method bodies need ILSpy (not installed).'],
    ];

    /** @return array<string, array{name: string, arch: string, bits: int, endian: string, native: bool, note: string}> */
    public static function all(): array
    {
        return self::TARGETS;
    }

    public static function exists(string $id): bool
    {
        return isset(self::TARGETS[$id]);
    }

    /** @return array{name: string, arch: string, bits: int, endian: string, native: bool, note: string}|null */
    public static function get(string $id): ?array
    {
        return self::TARGETS[$id] ?? null;
    }

    /** @return list<string> */
    public static function nativeIds(): array
    {
        return array_keys(array_filter(self::TARGETS, fn ($t) => $t['native']));
    }

    /**
     * Structural target candidates from extraction output. Returns
     * ordered candidates with provenance — never a silent guess.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<array{id: string, source: string, note: string}>
     */
    public static function candidatesFor(string $format, ?string $arch, array $snapshot): array
    {
        $data = $snapshot['data'] ?? [];

        if ($format === ProjectArtifact::FORMAT_PRG) {
            return [['id' => 'mos6502', 'source' => 'structural', 'note' => 'C64 PRG implies a 6502-family CPU (6510 in C64 context).']];
        }

        if ($format === ProjectArtifact::FORMAT_DEX || $format === ProjectArtifact::FORMAT_APK) {
            return [['id' => 'dalvik', 'source' => 'structural', 'note' => 'DEX bytecode implies the Dalvik target.']];
        }

        if ($format === ProjectArtifact::FORMAT_CLASS || (($data['kind'] ?? null) === 'jar' && $format === ProjectArtifact::FORMAT_ARCHIVE)) {
            return [['id' => 'jvm', 'source' => 'structural', 'note' => 'Java class files imply the JVM target.']];
        }

        if ($format === ProjectArtifact::FORMAT_PE && ($data['runtime'] ?? null) === 'clr') {
            return [['id' => 'clr', 'source' => 'structural', 'note' => 'COM descriptor directory present: managed .NET assembly.']];
        }

        if ($format === ProjectArtifact::FORMAT_ELF && $arch === 'arm32') {
            $out = [['id' => 'arm32', 'source' => 'structural', 'note' => 'ELF e_machine EM_ARM.']];
            // Thumb hint: odd function-symbol addresses (LSB set).
            // The last hex digit decides — no integer overflow risk.
            foreach (($data['symbols'] ?? []) as $symbol) {
                $value = strtolower((string) ($symbol['value'] ?? '0'));
                $odd = in_array(substr($value, -1), ['1', '3', '5', '7', '9', 'b', 'd', 'f'], true);
                if (($symbol['type'] ?? null) === 2 && $odd) {
                    $out[] = ['id' => 'arm32-thumb', 'source' => 'heuristic', 'note' => 'Tentative: function symbol '.$symbol['name'].' has its Thumb bit set.'];

                    break;
                }
            }

            return $out;
        }

        $byArch = [
            'x86' => 'x86', 'x86-64' => 'x86-64', 'aarch64' => 'aarch64',
            'mips' => 'mips32', 'riscv32' => 'riscv32', 'riscv64' => 'riscv64',
            'ppc' => 'ppc32', 'arm32' => 'arm32',
        ];
        if ($arch !== null && isset($byArch[$arch])) {
            return [['id' => $byArch[$arch], 'source' => 'structural', 'note' => 'Machine identifier maps to '.$byArch[$arch].'.']];
        }

        return [];
    }
}
