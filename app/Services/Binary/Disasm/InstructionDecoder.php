<?php

namespace App\Services\Binary\Disasm;

/**
 * Single-instruction decode contract. Decoders are pure functions
 * over bytes: no I/O, no state. Lengths are validated against the
 * available bytes so truncated tails decode as data, not overruns.
 */
interface InstructionDecoder
{
    public function id(): string;

    public function name(): string;

    /** Address size in bits (also bounds the address space). */
    public function addressBits(): int;

    /**
     * Decode one instruction at $offset.
     *
     * @return array{length: int, text: string, kind: string, target?: int}|null null when the bytes cannot start an instruction here
     */
    public function decode(string $bytes, int $offset, int $address): ?array;
}
