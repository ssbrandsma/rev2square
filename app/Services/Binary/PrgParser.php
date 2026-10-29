<?php

namespace App\Services\Binary;

/**
 * C64 PRG parsing: load address, BASIC detokenization, SYS-target
 * discovery, and machine-code/data region separation.
 *
 * Bytes after the load address are NOT blindly classified as code:
 * BASIC lines decode as text, SYS arguments become ML entry
 * candidates, and the remainder is reported as unclassified payload
 * until an analysis target says otherwise.
 */
final class PrgParser
{
    public const NAME = 'prg-php';

    public const VERSION = '1.0.0';

    public const MAX_BASIC_LINES = 2000;

    /** C64 BASIC V2 tokens $80-$CB plus $FF (PI). Others render as {token:$XX}. */
    private const TOKENS = [
        0x80 => 'END', 0x81 => 'FOR', 0x82 => 'NEXT', 0x83 => 'DATA', 0x84 => 'INPUT#',
        0x85 => 'INPUT', 0x86 => 'DIM', 0x87 => 'READ', 0x88 => 'LET', 0x89 => 'GOTO',
        0x8A => 'RUN', 0x8B => 'IF', 0x8C => 'RESTORE', 0x8D => 'GOSUB', 0x8E => 'RETURN',
        0x8F => 'REM', 0x90 => 'STOP', 0x91 => 'ON', 0x92 => 'WAIT', 0x93 => 'LOAD',
        0x94 => 'SAVE', 0x95 => 'VERIFY', 0x96 => 'DEF', 0x97 => 'POKE', 0x98 => 'PRINT#',
        0x99 => 'PRINT', 0x9A => 'CONT', 0x9B => 'LIST', 0x9C => 'CLR', 0x9D => 'CMD',
        0x9E => 'SYS', 0x9F => 'OPEN', 0xA0 => 'CLOSE', 0xA1 => 'GET', 0xA2 => 'NEW',
        0xA3 => 'TAB(', 0xA4 => 'TO', 0xA5 => 'FN', 0xA6 => 'SPC(', 0xA7 => 'THEN',
        0xA8 => 'NOT', 0xA9 => 'STEP', 0xAA => '+', 0xAB => '-', 0xAC => '*',
        0xAD => '/', 0xAE => '^', 0xAF => 'AND', 0xB0 => 'OR', 0xB1 => '>',
        0xB2 => '=', 0xB3 => '<', 0xB4 => 'SGN', 0xB5 => 'INT', 0xB6 => 'ABS',
        0xB7 => 'USR', 0xB8 => 'FRE', 0xB9 => 'POS', 0xBA => 'SQR', 0xBB => 'RND',
        0xBC => 'LOG', 0xBD => 'EXP', 0xBE => 'COS', 0xBF => 'SIN', 0xC0 => 'TAN',
        0xC1 => 'ATN', 0xC2 => 'PEEK', 0xC3 => 'LEN', 0xC4 => 'STR$', 0xC5 => 'VAL',
        0xC6 => 'ASC', 0xC7 => 'CHR$', 0xC8 => 'LEFT$', 0xC9 => 'RIGHT$', 0xCA => 'MID$',
        0xCB => 'GO', 0xFF => 'PI',
    ];

    /**
     * @return array{ok: bool, error?: string, arch?: string, data?: array{load_address: int, basic_lines: list<array{number: int, text: string, offset: int}>, basic_truncated: bool, sys_targets: list<int>, payload_offset: int, payload_bytes: int, warnings: list<string>}}
     */
    public static function parse(string $bytes): array
    {
        if (strlen($bytes) < 3) {
            return ['ok' => false, 'error' => 'too_small'];
        }

        $load = ord($bytes[0]) | (ord($bytes[1]) << 8);
        if (! PrgProbe::plausibleLoadAddress($load)) {
            return ['ok' => false, 'error' => 'bad_load_address'];
        }

        $basic = self::detokenize($bytes);
        $warnings = [];
        if (! $basic['looks_like_basic'] && $basic['lines'] !== []) {
            $warnings[] = 'basic_chain_broken';
        }
        if (! $basic['looks_like_basic']) {
            $warnings[] = 'no_basic_structure';
        }

        return [
            'ok' => true,
            'arch' => 'mos6502',
            'data' => [
                'load_address' => $load,
                'basic_lines' => $basic['lines'],
                'basic_truncated' => $basic['truncated'],
                'sys_targets' => $basic['sys_targets'],
                'payload_offset' => $basic['payload_offset'],
                'payload_bytes' => strlen($bytes) - $basic['payload_offset'],
                'warnings' => $warnings,
            ],
        ];
    }

    /**
     * Walk the BASIC line chain from file offset 2.
     *
     * @return array{looks_like_basic: bool, lines: list<array{number: int, text: string, offset: int}>, truncated: bool, sys_targets: list<int>, payload_offset: int}
     */
    public static function detokenize(string $bytes): array
    {
        $lines = [];
        $sysTargets = [];
        $length = strlen($bytes);
        $pos = 2;
        $looksLikeBasic = false;
        $truncated = false;

        while ($pos + 4 <= $length && count($lines) < self::MAX_BASIC_LINES) {
            $link = ord($bytes[$pos]) | (ord($bytes[$pos + 1]) << 8);
            if ($link === 0) {
                $pos += 2;
                $looksLikeBasic = $lines !== [];
                break;
            }
            $number = ord($bytes[$pos + 2]) | (ord($bytes[$pos + 3]) << 8);
            // Link must advance forward within the file; line numbers
            // must be ordered. Otherwise this is not a BASIC chain.
            if ($link <= $pos || $link > $length) {
                break;
            }
            if ($lines !== [] && $number < $lines[count($lines) - 1]['number']) {
                break;
            }

            $text = '';
            $p = $pos + 4;
            $end = min($link, $length);
            $inQuotes = false;
            while ($p < $end) {
                $c = ord($bytes[$p]);
                if ($c === 0) {
                    $p++;
                    break;
                }
                if ($c === 0x22) {
                    $inQuotes = ! $inQuotes;
                }
                if ($c >= 0x80 && ! $inQuotes && isset(self::TOKENS[$c])) {
                    $text .= ' '.self::TOKENS[$c].' ';
                } elseif ($c >= 0x80 && ! $inQuotes) {
                    $text .= sprintf('{token:$%02X}', $c);
                } elseif ($c >= 32 && $c < 127) {
                    $text .= chr($c);
                } elseif ($c >= 160 && $c < 224) {
                    // Shifted PETSCII letters: map to ASCII lowercase.
                    $text .= chr($c - 96);
                } else {
                    $text .= sprintf('{$%02X}', $c);
                }
                $p++;
            }

            if (preg_match_all('/\bSYS\s+(\d{1,5})\b/', $text, $m) === false) {
                break;
            }
            foreach ($m[1] as $target) {
                if ((int) $target <= 65535) {
                    $sysTargets[] = (int) $target;
                }
            }

            $lines[] = ['number' => $number, 'text' => trim(preg_replace('/\s+/', ' ', $text) ?? ''), 'offset' => $pos];
            $pos = $p;
            if ($p >= $length) {
                break;
            }
        }

        if (count($lines) >= self::MAX_BASIC_LINES) {
            $truncated = true;
        }

        // A lone link-0 terminator with no lines is not a program.
        if ($lines === []) {
            $looksLikeBasic = false;
        }

        return [
            'looks_like_basic' => $looksLikeBasic,
            'lines' => $lines,
            'truncated' => $truncated,
            'sys_targets' => array_values(array_unique($sysTargets)),
            'payload_offset' => $pos,
        ];
    }
}
