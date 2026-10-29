<?php

namespace App\Services\Binary;

/**
 * Shared printable-string extraction for binary formats: ASCII and
 * UTF-16LE runs with file offsets. Output is untrusted data — every
 * consumer must escape before display and treat matches as data,
 * never as instructions.
 *
 * @return array{strings: list<array{offset: int, encoding: string, value: string}>, truncated: bool}
 */
final class StringsExtractor
{
    public static function extract(string $bytes, int $maxStrings = 2000, int $maxBytes = 256, int $minLength = 4): array
    {
        $strings = [];
        $truncated = false;
        $length = strlen($bytes);

        $flush = function (int $start, int $end, string $encoding) use (&$strings, &$truncated, $bytes, $maxStrings, $maxBytes, $minLength): void {
            $run = $end - $start;
            if ($encoding === 'utf16le') {
                $run = (int) ($run / 2);
            }
            if ($run < $minLength) {
                return;
            }
            if (count($strings) >= $maxStrings) {
                $truncated = true;

                return;
            }
            $raw = substr($bytes, $start, min($end - $start, $encoding === 'utf16le' ? $maxBytes * 2 : $maxBytes));
            $value = $encoding === 'utf16le'
                ? (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE')
                : $raw;
            // Drop runs that decode to control garbage.
            if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
                return;
            }
            $strings[] = ['offset' => $start, 'encoding' => $encoding, 'value' => $value];
        };

        $asciiStart = null;
        for ($i = 0; $i < $length; $i++) {
            $c = ord($bytes[$i]);
            $printable = ($c >= 32 && $c < 127) || $c === 9;
            if ($printable && $asciiStart === null) {
                $asciiStart = $i;
            } elseif (! $printable && $asciiStart !== null) {
                $flush($asciiStart, $i, 'ascii');
                $asciiStart = null;
            }
        }
        if ($asciiStart !== null) {
            $flush($asciiStart, $length, 'ascii');
        }

        // UTF-16LE: printable low byte followed by 0x00, aligned runs.
        $wideStart = null;
        for ($i = 0; $i + 1 < $length; $i += 2) {
            $lo = ord($bytes[$i]);
            $hi = ord($bytes[$i + 1]);
            $wide = (($lo >= 32 && $lo < 127) || $lo === 9) && $hi === 0;
            if ($wide && $wideStart === null) {
                $wideStart = $i;
            } elseif (! $wide && $wideStart !== null) {
                $flush($wideStart, $i, 'utf16le');
                $wideStart = null;
            }
        }
        if ($wideStart !== null) {
            $flush($wideStart, $length - ($length % 2), 'utf16le');
        }

        usort($strings, fn ($a, $b) => $a['offset'] <=> $b['offset']);

        return ['strings' => array_slice($strings, 0, $maxStrings), 'truncated' => $truncated];
    }
}
