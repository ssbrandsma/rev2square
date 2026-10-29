<?php

namespace App\Services\Binary;

/**
 * Minimal ZIP central-directory reader for APK/JAR/archive
 * inspection. Parses the end-of-central-directory record and the
 * central directory only — no extraction here, so no zip-bombs and
 * no path traversal. Member reads go through bounded byte ranges.
 */
final class ZipReader
{
    public const MAX_ENTRIES = 2000;

    public const MAX_NAME_BYTES = 512;

    public const MAX_EOCD_COMMENT = 65535;

    /**
     * @return array{ok: bool, error?: string, entries?: list<array{name: string, method: int, encrypted: bool, compressed_size: int, size: int, local_offset: int}>, truncated?: bool}
     */
    public static function centralDirectory(string $bytes, int $maxEntries = self::MAX_ENTRIES): array
    {
        $length = strlen($bytes);
        if ($length < 22) {
            return ['ok' => false, 'error' => 'too_small'];
        }

        // EOCD signature search within the trailing window.
        $window = min($length, self::MAX_EOCD_COMMENT + 22);
        $eocd = null;
        for ($pos = $length - 22; $pos >= $length - $window; $pos--) {
            if (substr($bytes, $pos, 4) === "PK\x05\x06") {
                $eocd = $pos;
                break;
            }
        }
        if ($eocd === null) {
            return ['ok' => false, 'error' => 'no_eocd'];
        }

        $reader = new ByteReader($bytes);
        $count = $reader->u16le($eocd + 10);
        $cdSize = $reader->u32le($eocd + 12);
        $cdOffset = $reader->u32le($eocd + 16);
        if ($count === null || $cdSize === null || $cdOffset === null) {
            return ['ok' => false, 'error' => 'bad_eocd'];
        }
        if ($cdOffset + $cdSize > $length) {
            return ['ok' => false, 'error' => 'cd_out_of_bounds'];
        }

        $entries = [];
        $truncated = $count > $maxEntries;
        $pos = $cdOffset;
        for ($i = 0; $i < min($count, $maxEntries); $i++) {
            if ($reader->magic($pos, 4) !== "PK\x01\x02") {
                return ['ok' => false, 'error' => 'bad_cd_entry'];
            }
            $flags = $reader->u16le($pos + 8);
            $method = $reader->u16le($pos + 10);
            $compressed = $reader->u32le($pos + 20);
            $size = $reader->u32le($pos + 24);
            $nameLen = $reader->u16le($pos + 28);
            $extraLen = $reader->u16le($pos + 30);
            $commentLen = $reader->u16le($pos + 32);
            $localOffset = $reader->u32le($pos + 42);
            if ($flags === null || $method === null || $compressed === null || $size === null
                || $nameLen === null || $extraLen === null || $commentLen === null || $localOffset === null) {
                return ['ok' => false, 'error' => 'bad_cd_entry'];
            }
            if ($nameLen > self::MAX_NAME_BYTES) {
                return ['ok' => false, 'error' => 'name_too_long'];
            }
            $name = $reader->slice($pos + 46, $nameLen);
            if ($name === null) {
                return ['ok' => false, 'error' => 'bad_cd_entry'];
            }
            $entries[] = [
                'name' => $name,
                'method' => $method,
                'encrypted' => (bool) ($flags & 0x1),
                'compressed_size' => $compressed,
                'size' => $size,
                'local_offset' => $localOffset,
            ];
            $pos += 46 + $nameLen + $extraLen + $commentLen;
            if ($pos > $length) {
                return ['ok' => false, 'error' => 'bad_cd_entry'];
            }
        }

        return ['ok' => true, 'entries' => $entries, 'truncated' => $truncated];
    }

    /**
     * Read one stored (method 0) member's raw bytes, bounded. Returns
     * null for deflated/unsupported members — inflation is an explicit
     * opt-in with its own budget (see readInflated).
     */
    public static function readStored(string $bytes, array $entry): ?string
    {
        if (($entry['method'] ?? 8) !== 0 || ($entry['encrypted'] ?? true)) {
            return null;
        }

        $reader = new ByteReader($bytes);
        $local = (int) ($entry['local_offset'] ?? -1);
        if ($reader->magic($local, 4) !== "PK\x03\x04") {
            return null;
        }
        $nameLen = $reader->u16le($local + 26);
        $extraLen = $reader->u16le($local + 28);
        if ($nameLen === null || $extraLen === null) {
            return null;
        }

        $dataOffset = $local + 30 + $nameLen + $extraLen;
        $size = (int) ($entry['size'] ?? -1);
        if ($size < 0) {
            return null;
        }

        return $reader->slice($dataOffset, $size);
    }

    /**
     * Inflate one deflated member with an explicit expanded-size
     * budget. Sizes come from the central directory (authoritative);
     * local-header sizes and any trailing data descriptor are
     * ignored, so the common bit-3 zips work without trusting
     * unverified local fields. The inflated size must match exactly.
     */
    public static function readInflated(string $bytes, array $entry, int $maxExpanded): ?string
    {
        if (($entry['method'] ?? 0) !== 8 || ($entry['encrypted'] ?? true)) {
            return null;
        }

        $size = (int) ($entry['size'] ?? -1);
        $compressed = (int) ($entry['compressed_size'] ?? -1);
        if ($size < 0 || $size > $maxExpanded || $compressed < 0) {
            return null;
        }

        $reader = new ByteReader($bytes);
        $local = (int) ($entry['local_offset'] ?? -1);
        if ($reader->magic($local, 4) !== "PK\x03\x04") {
            return null;
        }
        $nameLen = $reader->u16le($local + 26);
        $extraLen = $reader->u16le($local + 28);
        if ($nameLen === null || $extraLen === null) {
            return null;
        }

        $raw = $reader->slice($local + 30 + $nameLen + $extraLen, $compressed);
        if ($raw === null) {
            return null;
        }

        $out = @gzinflate($raw, $maxExpanded);
        if ($out === false || strlen($out) !== $size) {
            return null;
        }

        return $out;
    }
}
