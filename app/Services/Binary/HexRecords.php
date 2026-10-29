<?php

namespace App\Services\Binary;

/**
 * Intel HEX and Motorola S-record parsing with checksums, address
 * ranges, and overlap detection. Produces address-bearing segments;
 * raw data bytes stay in the artifact — analysis rebuilds the
 * address map from these records on demand (bounded).
 */
final class HexRecords
{
    public const MAX_RECORDS = 200000;

    public const MAX_DATA_BYTES = 16 * 1024 * 1024;

    /** @return array{ok: bool, detail?: string} */
    public static function probeIntelHex(string $bytes): array
    {
        $lines = preg_split('/\r?\n/', substr($bytes, 0, 65536)) ?: [];
        $valid = 0;
        foreach (array_slice($lines, 0, 20) as $line) {
            if (preg_match('/^:([0-9A-Fa-f]{2}){5,}/', $line) === 1) {
                $valid++;
            } elseif (trim($line) !== '') {
                return ['ok' => false];
            }
        }

        if ($valid === 0) {
            return ['ok' => false];
        }

        return ['ok' => true, 'detail' => $valid.' valid Intel HEX record(s) at the start of the file (colon + byte-count + address + type + checksum shape)'];
    }

    /** @return array{ok: bool, detail?: string} */
    public static function probeSrec(string $bytes): array
    {
        $lines = preg_split('/\r?\n/', substr($bytes, 0, 65536)) ?: [];
        $valid = 0;
        foreach (array_slice($lines, 0, 20) as $line) {
            if (preg_match('/^S[0-9]([0-9A-Fa-f]{2}){2,}/', $line) === 1) {
                $valid++;
            } elseif (trim($line) !== '') {
                return ['ok' => false];
            }
        }

        if ($valid === 0) {
            return ['ok' => false];
        }

        return ['ok' => true, 'detail' => $valid.' valid S-record line(s) at the start of the file (Sn + byte-count + address + checksum shape)'];
    }

    /**
     * @return array{ok: bool, error?: string, data?: array{records: int, data_bytes: int, bad_checksums: int, segments: list<array{start: int, end: int, length: int}>, min_address: ?int, max_address: ?int, entry: ?int, overlaps: int, warnings: list<string>}}
     */
    public static function parseIntelHex(string $bytes): array
    {
        $lines = preg_split('/\r?\n/', $bytes) ?: [];
        $ranges = [];
        $records = 0;
        $dataBytes = 0;
        $badChecksums = 0;
        $warnings = [];
        $entry = null;
        $upper = 0;

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($records >= self::MAX_RECORDS) {
                $warnings[] = 'records_capped';

                break;
            }
            if (! str_starts_with($line, ':') || (strlen($line) - 1) % 2 !== 0) {
                return ['ok' => false, 'error' => 'malformed_record:'.($index + 1)];
            }
            $raw = @hex2bin(substr($line, 1));
            if ($raw === false || strlen($raw) < 5) {
                return ['ok' => false, 'error' => 'malformed_record:'.($index + 1)];
            }
            $count = ord($raw[0]);
            if (strlen($raw) !== 5 + $count) {
                return ['ok' => false, 'error' => 'malformed_record:'.($index + 1)];
            }
            $sum = array_sum(array_map('ord', str_split($raw))) & 0xFF;
            if ($sum !== 0) {
                $badChecksums++;
            }
            $records++;
            $type = ord($raw[3]);
            $addr = (ord($raw[1]) << 8) | ord($raw[2]);

            if ($type === 0x00) {
                $base = ($upper << 16) | $addr;
                $dataBytes += $count;
                if ($dataBytes > self::MAX_DATA_BYTES) {
                    $warnings[] = 'data_capped';

                    break;
                }
                $ranges[] = [$base, $base + $count - 1];
            } elseif ($type === 0x01) {
                break;
            } elseif ($type === 0x04 && $count === 2) {
                $upper = (ord($raw[4]) << 8) | ord($raw[5]);
            } elseif ($type === 0x05 && $count === 4) {
                $entry = unpack('N', substr($raw, 4, 4))[1];
            } elseif ($type > 0x05) {
                $warnings[] = 'unsupported_record_type:'.$type;
            }
        }

        return ['ok' => true, 'data' => self::summarize($ranges, $records, $dataBytes, $badChecksums, $entry, $warnings)];
    }

    /** @return array{ok: bool, error?: string, data?: array{records: int, data_bytes: int, bad_checksums: int, segments: list<array{start: int, end: int, length: int}>, min_address: ?int, max_address: ?int, entry: ?int, overlaps: int, warnings: list<string>}} */
    public static function parseSrec(string $bytes): array
    {
        $addrLen = ['S1' => 2, 'S2' => 3, 'S3' => 4, 'S5' => 2, 'S7' => 4, 'S8' => 3, 'S9' => 2];
        $lines = preg_split('/\r?\n/', $bytes) ?: [];
        $ranges = [];
        $records = 0;
        $dataBytes = 0;
        $badChecksums = 0;
        $warnings = [];
        $entry = null;

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($records >= self::MAX_RECORDS) {
                $warnings[] = 'records_capped';

                break;
            }
            $kind = substr($line, 0, 2);
            if (! isset($addrLen[$kind]) && $kind !== 'S0' && $kind !== 'S6') {
                return ['ok' => false, 'error' => 'malformed_record:'.($index + 1)];
            }
            $raw = @hex2bin(substr($line, 2));
            if ($raw === false || strlen($raw) < 2) {
                return ['ok' => false, 'error' => 'malformed_record:'.($index + 1)];
            }
            $count = ord($raw[0]);
            if (strlen($raw) !== $count + 1) {
                return ['ok' => false, 'error' => 'malformed_record:'.($index + 1)];
            }
            $sum = array_sum(array_map('ord', str_split($raw))) & 0xFF;
            if ((~$sum & 0xFF) !== 0) {
                $badChecksums++;
            }
            $records++;

            $alen = $addrLen[$kind] ?? 0;
            if ($alen > 0 && in_array($kind, ['S1', 'S2', 'S3'], true)) {
                $addr = 0;
                for ($i = 0; $i < $alen; $i++) {
                    $addr = ($addr << 8) | ord($raw[1 + $i]);
                }
                $dataLen = $count - $alen - 1;
                if ($dataLen > 0) {
                    $dataBytes += $dataLen;
                    if ($dataBytes > self::MAX_DATA_BYTES) {
                        $warnings[] = 'data_capped';

                        break;
                    }
                    $ranges[] = [$addr, $addr + $dataLen - 1];
                }
            } elseif (in_array($kind, ['S7', 'S8', 'S9'], true)) {
                $addr = 0;
                for ($i = 0; $i < $alen; $i++) {
                    $addr = ($addr << 8) | ord($raw[1 + $i]);
                }
                $entry = $addr;
            }
        }

        return ['ok' => true, 'data' => self::summarize($ranges, $records, $dataBytes, $badChecksums, $entry, $warnings)];
    }

    /**
     * Rebuild address→byte segments from records (bounded). Overlaps
     * resolve last-record-wins, deterministically; the overlap count
     * is reported so analysis never hides patched regions.
     *
     * @return array{segments: list<array{base: int, bytes: string}>, bytes: int, overlaps: int, truncated: bool}
     */
    public static function materialize(string $format, string $bytes, int $maxBytes): array
    {
        $map = [];
        $total = 0;
        $overlaps = 0;
        $truncated = false;

        $emit = function (int $address, string $data) use (&$map, &$total, &$overlaps, &$truncated, $maxBytes): void {
            $len = strlen($data);
            for ($i = 0; $i < $len; $i++) {
                if ($total >= $maxBytes) {
                    $truncated = true;

                    return;
                }
                $addr = $address + $i;
                if (isset($map[$addr])) {
                    $overlaps++;
                } else {
                    $total++;
                }
                $map[$addr] = $data[$i];
            }
        };

        $lines = preg_split('/\r?\n/', $bytes) ?: [];
        if ($format === 'ihex') {
            $upper = 0;
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || ! str_starts_with($line, ':')) {
                    continue;
                }
                $raw = @hex2bin(substr($line, 1));
                if ($raw === false || strlen($raw) < 5) {
                    continue;
                }
                $count = ord($raw[0]);
                if (strlen($raw) !== 5 + $count) {
                    continue;
                }
                $type = ord($raw[3]);
                $addr = (ord($raw[1]) << 8) | ord($raw[2]);
                if ($type === 0x00) {
                    $emit(($upper << 16) | $addr, substr($raw, 4, $count));
                } elseif ($type === 0x04 && $count === 2) {
                    $upper = (ord($raw[4]) << 8) | ord($raw[5]);
                }
                if ($truncated) {
                    break;
                }
            }
        } else {
            $addrLen = ['S1' => 2, 'S2' => 3, 'S3' => 4];
            foreach ($lines as $line) {
                $line = trim($line);
                $kind = substr($line, 0, 2);
                if (! isset($addrLen[$kind])) {
                    continue;
                }
                $raw = @hex2bin(substr($line, 2));
                if ($raw === false || strlen($raw) < 2) {
                    continue;
                }
                $count = ord($raw[0]);
                if (strlen($raw) !== $count + 1) {
                    continue;
                }
                $alen = $addrLen[$kind];
                $addr = 0;
                for ($i = 0; $i < $alen; $i++) {
                    $addr = ($addr << 8) | ord($raw[1 + $i]);
                }
                $dataLen = $count - $alen - 1;
                if ($dataLen > 0) {
                    $emit($addr, substr($raw, 1 + $alen, $dataLen));
                }
                if ($truncated) {
                    break;
                }
            }
        }

        ksort($map, SORT_NUMERIC);
        $segments = [];
        $base = null;
        $buf = '';
        $prev = null;
        foreach ($map as $addr => $byte) {
            if ($base === null || $addr !== $prev + 1) {
                if ($base !== null) {
                    $segments[] = ['base' => $base, 'bytes' => $buf];
                }
                $base = $addr;
                $buf = '';
            }
            $buf .= $byte;
            $prev = $addr;
        }
        if ($base !== null) {
            $segments[] = ['base' => $base, 'bytes' => $buf];
        }

        return ['segments' => $segments, 'bytes' => $total, 'overlaps' => $overlaps, 'truncated' => $truncated];
    }

    /**
     * @param  list<array{int, int}>  $ranges
     * @return array{records: int, data_bytes: int, bad_checksums: int, segments: list<array{start: int, end: int, length: int}>, min_address: ?int, max_address: ?int, entry: ?int, overlaps: int, warnings: list<string>}
     */
    private static function summarize(array $ranges, int $records, int $dataBytes, int $badChecksums, ?int $entry, array $warnings): array
    {
        usort($ranges, fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        $segments = [];
        $overlaps = 0;
        foreach ($ranges as [$start, $end]) {
            $last = count($segments) - 1;
            if ($last >= 0 && $start <= $segments[$last]['end'] + 1) {
                if ($start <= $segments[$last]['end']) {
                    $overlaps++;
                }
                $segments[$last]['end'] = max($segments[$last]['end'], $end);
                $segments[$last]['length'] = $segments[$last]['end'] - $segments[$last]['start'] + 1;
            } else {
                $segments[] = ['start' => $start, 'end' => $end, 'length' => $end - $start + 1];
            }
        }

        if ($badChecksums > 0) {
            $warnings[] = 'bad_checksums:'.$badChecksums;
        }
        if ($overlaps > 0) {
            $warnings[] = 'overlapping_records:'.$overlaps;
        }

        return [
            'records' => $records,
            'data_bytes' => $dataBytes,
            'bad_checksums' => $badChecksums,
            'segments' => array_slice($segments, 0, 256),
            'min_address' => $segments === [] ? null : $segments[0]['start'],
            'max_address' => $segments === [] ? null : $segments[count($segments) - 1]['end'],
            'entry' => $entry,
            'overlaps' => $overlaps,
            'warnings' => $warnings,
        ];
    }
}
