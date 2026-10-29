<?php

namespace App\Services\Binary;

/**
 * C64 PRG detection. PRG files have no magic — just a 2-byte
 * little-endian load address — so detection combines the load
 * address range with either a BASIC-program structure probe or an
 * explicit `.prg` filename hint. Which of the two fired is recorded
 * in the evidence; a bare load address plus unrelated bytes is not
 * enough on its own.
 */
final class PrgProbe
{
    /** Plausible C64 load addresses ($0400-$FFFF, excluding page zero/stack). */
    public static function plausibleLoadAddress(int $load): bool
    {
        return $load >= 0x0400 && $load <= 0xFFFF;
    }

    /**
     * @return list<array{string, string}>|null evidence pairs, or null when not a PRG
     */
    public static function probe(string $bytes, string $ext): ?array
    {
        if (strlen($bytes) < 4) {
            return null;
        }

        $load = ord($bytes[0]) | (ord($bytes[1]) << 8);
        if (! self::plausibleLoadAddress($load)) {
            return null;
        }

        $basic = PrgParser::detokenize($bytes);
        if ($basic['looks_like_basic']) {
            return [
                ['load_address', sprintf('Load address $%04X with a well-formed BASIC line chain (%d line(s))', $load, count($basic['lines']))],
                ['basic_structure', 'Next-line links and line numbers decode consistently from file offset 2'],
            ];
        }

        // Machine-language-only PRG: no content magic exists, so the
        // filename hint is required and is labelled as a hint.
        if ($ext === 'prg' || $ext === 'p00') {
            return [
                ['load_address', sprintf('Load address $%04X', $load)],
                ['filename_hint', 'No BASIC structure found; classified by .'.$ext.' filename hint only — treat the machine-code assumption as tentative'],
            ];
        }

        return null;
    }
}
