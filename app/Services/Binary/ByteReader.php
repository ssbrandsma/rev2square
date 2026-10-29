<?php

namespace App\Services\Binary;

/**
 * Bounds-checked little/big-endian reads over an in-memory byte
 * string. Every read returns null past the end instead of warning,
 * so malformed headers degrade to detection evidence, not crashes.
 */
final class ByteReader
{
    public function __construct(public readonly string $bytes) {}

    public function length(): int
    {
        return strlen($this->bytes);
    }

    public function u8(int $offset): ?int
    {
        if ($offset < 0 || $offset >= strlen($this->bytes)) {
            return null;
        }

        return ord($this->bytes[$offset]);
    }

    public function u16le(int $offset): ?int
    {
        $raw = $this->slice($offset, 2);
        if ($raw === null) {
            return null;
        }

        $v = unpack('v', $raw);

        return $v[1];
    }

    public function u16be(int $offset): ?int
    {
        $raw = $this->slice($offset, 2);
        if ($raw === null) {
            return null;
        }

        $v = unpack('n', $raw);

        return $v[1];
    }

    public function u32le(int $offset): ?int
    {
        $raw = $this->slice($offset, 4);
        if ($raw === null) {
            return null;
        }

        $v = unpack('V', $raw);

        return $v[1];
    }

    public function u32be(int $offset): ?int
    {
        $raw = $this->slice($offset, 4);
        if ($raw === null) {
            return null;
        }

        $v = unpack('N', $raw);

        return $v[1];
    }

    public function u64le(int $offset): ?int
    {
        $raw = $this->slice($offset, 8);
        if ($raw === null) {
            return null;
        }

        $v = unpack('P', $raw);

        return $v[1];
    }

    public function slice(int $offset, int $length): ?string
    {
        if ($offset < 0 || $length < 0 || $offset + $length > strlen($this->bytes)) {
            return null;
        }

        return substr($this->bytes, $offset, $length);
    }

    public function magic(int $offset, int $length): ?string
    {
        return $this->slice($offset, $length);
    }

    /** NUL-terminated printable string, bounded. Null when unbounded. */
    public function cstring(int $offset, int $max = 256): ?string
    {
        if ($offset < 0 || $offset >= strlen($this->bytes) || $max <= 0) {
            return null;
        }

        $end = strpos($this->bytes, "\0", $offset);
        if ($end === false || $end - $offset > $max) {
            return null;
        }

        return substr($this->bytes, $offset, $end - $offset);
    }
}
