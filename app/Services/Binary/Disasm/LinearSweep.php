<?php

namespace App\Services\Binary\Disasm;

/**
 * Bounded linear sweep shared by the hand-written decoders. Walks a
 * flat byte window; each window carries its own base virtual address
 * so multi-segment images are swept per segment (never with one base
 * smeared across segments). Collects jump/call cross-references and
 * reports data bytes honestly instead of forcing decode.
 *
 * @return array{lines: list<array{offset: int, address: int, bytes: string, text: string}>, xrefs: list<array{from: int, to: int, kind: string}>, instructions: int, data_bytes: int, truncated: bool}
 */
final class LinearSweep
{
    public static function sweep(
        InstructionDecoder $decoder,
        string $bytes,
        int $baseAddress,
        int $startOffset,
        int $maxInstructions,
        int $maxBytes,
    ): array {
        $lines = [];
        $xrefs = [];
        $instructions = 0;
        $dataBytes = 0;
        $truncated = false;
        $length = strlen($bytes);
        $mask = $decoder->addressBits() >= 64 ? -1 : ((1 << $decoder->addressBits()) - 1);

        $offset = max(0, $startOffset);
        $end = min($length, $startOffset + $maxBytes);
        while ($offset < $end) {
            if ($instructions >= $maxInstructions) {
                $truncated = true;
                break;
            }

            $address = ($baseAddress + ($offset - $startOffset)) & $mask;
            $decoded = $decoder->decode($bytes, $offset, $address);

            if ($decoded === null || $offset + $decoded['length'] > $length) {
                $lines[] = [
                    'offset' => $offset,
                    'address' => $address,
                    'bytes' => bin2hex($bytes[$offset]),
                    'text' => sprintf('.db $%02X', ord($bytes[$offset])),
                ];
                $dataBytes++;
                $offset++;

                continue;
            }

            $lines[] = [
                'offset' => $offset,
                'address' => $address,
                'bytes' => bin2hex(substr($bytes, $offset, $decoded['length'])),
                'text' => $decoded['text'],
            ];
            // Fixed-width decoders emit uncovered words as kind=data so
            // the sweep stays aligned; those count as data, not code.
            if (($decoded['kind'] ?? 'code') === 'data') {
                $dataBytes++;
            } else {
                $instructions++;
            }
            if (isset($decoded['target']) && in_array($decoded['kind'], ['jump', 'call', 'branch'], true)) {
                $xrefs[] = ['from' => $address, 'to' => $decoded['target'] & $mask, 'kind' => $decoded['kind']];
            }
            $offset += $decoded['length'];
        }

        if ($offset < $end) {
            $truncated = true;
        }

        return [
            'lines' => $lines,
            'xrefs' => $xrefs,
            'instructions' => $instructions,
            'data_bytes' => $dataBytes,
            'truncated' => $truncated,
        ];
    }
}
