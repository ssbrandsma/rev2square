<?php

namespace App\Services\Binary;

/**
 * Dalvik DEX parsing: header validation, string pool, type/method
 * tables, and class definitions with bounded method signatures.
 * Bytecode instructions are not decoded here — that belongs to an
 * Android-capable adapter (see JadxAdapter).
 */
final class DexParser
{
    public const NAME = 'dex-php';

    public const VERSION = '1.0.0';

    /**
     * @param  array{max_strings?: int, max_classes?: int, max_methods?: int}  $limits
     * @return array{ok: bool, error?: string, arch?: ?string, data?: array<string, mixed>}
     */
    public static function parse(string $bytes, array $limits = []): array
    {
        $limits = array_merge(['max_strings' => 2000, 'max_classes' => 1000, 'max_methods' => 2000], $limits);
        $reader = new ByteReader($bytes);
        $magic = $reader->magic(0, 8);
        if ($magic === null || ! preg_match('/\Adex\n03[5-9]\0\z/', $magic)) {
            return ['ok' => false, 'error' => 'not_dex'];
        }
        $endian = $reader->u32le(40);
        $stringSize = $reader->u32le(56);
        $stringOff = $reader->u32le(60);
        $typeSize = $reader->u32le(64);
        $typeOff = $reader->u32le(68);
        $methodSize = $reader->u32le(88);
        $methodOff = $reader->u32le(92);
        $classSize = $reader->u32le(96);
        $classOff = $reader->u32le(100);
        if ($endian === null || $stringSize === null || $stringOff === null || $typeSize === null
            || $typeOff === null || $methodSize === null || $methodOff === null
            || $classSize === null || $classOff === null) {
            return ['ok' => false, 'error' => 'truncated_header'];
        }
        if ($endian !== 0x12345678) {
            return ['ok' => false, 'error' => 'bad_endian_tag'];
        }
        foreach ([$stringSize, $typeSize, $methodSize, $classSize] as $n) {
            if ($n > 10_000_000) {
                return ['ok' => false, 'error' => 'table_too_large'];
            }
        }

        $warnings = [];

        $stringAt = function (int $idx) use ($reader, $stringSize, $stringOff): ?string {
            if ($idx >= $stringSize) {
                return null;
            }
            $ptr = $reader->u32le($stringOff + $idx * 4);
            if ($ptr === null) {
                return null;
            }
            $leb = self::uleb128($reader, $ptr);
            if ($leb === null || $leb[0] > 65536) {
                return null;
            }
            $raw = $reader->slice($leb[1], $leb[0]);
            if ($raw === null || ! mb_check_encoding($raw, 'UTF-8')) {
                return null;
            }

            return $raw;
        };

        $typeAt = function (int $idx) use ($reader, $typeSize, $typeOff, $stringAt): ?string {
            if ($idx >= $typeSize) {
                return null;
            }
            $sidx = $reader->u32le($typeOff + $idx * 4);
            if ($sidx === null) {
                return null;
            }

            return $stringAt($sidx);
        };

        // Bounded string pool (offsets are file offsets into data).
        $strings = [];
        $keptStrings = min($stringSize, $limits['max_strings']);
        for ($i = 0; $i < $keptStrings; $i++) {
            $ptr = $reader->u32le($stringOff + $i * 4);
            $value = $stringAt($i);
            if ($ptr !== null && $value !== null && $value !== '') {
                $strings[] = ['offset' => $ptr, 'value' => mb_substr($value, 0, 256)];
            }
        }
        if ($stringSize > $keptStrings) {
            $warnings[] = 'strings_capped';
        }

        // Bounded classes with access flags.
        $classes = [];
        $keptClasses = min($classSize, $limits['max_classes']);
        for ($i = 0; $i < $keptClasses; $i++) {
            $base = $classOff + $i * 32;
            $classIdx = $reader->u32le($base);
            $access = $reader->u32le($base + 4);
            if ($classIdx === null || $access === null) {
                return ['ok' => false, 'error' => 'truncated_class_defs'];
            }
            $classes[] = [
                'type' => $typeAt($classIdx) ?? 'type#'.$classIdx,
                'access_flags' => sprintf('0x%X', $access),
                'interface' => (bool) ($access & 0x200),
                'enum' => (bool) ($access & 0x4000),
            ];
        }
        if ($classSize > $keptClasses) {
            $warnings[] = 'classes_capped';
        }

        // Bounded method signatures: class.name (no proto decode — the
        // proto table walk is left to a full DEX adapter).
        $methods = [];
        $keptMethods = min($methodSize, $limits['max_methods']);
        for ($i = 0; $i < $keptMethods; $i++) {
            $base = $methodOff + $i * 8;
            $classIdx = $reader->u16le($base);
            $nameIdx = $reader->u32le($base + 4);
            if ($classIdx === null || $nameIdx === null) {
                return ['ok' => false, 'error' => 'truncated_method_ids'];
            }
            $methods[] = ($typeAt($classIdx) ?? 'type#'.$classIdx).'->'.($stringAt($nameIdx) ?? 'name#'.$nameIdx);
        }
        if ($methodSize > $keptMethods) {
            $warnings[] = 'methods_capped';
        }

        return [
            'ok' => true,
            'arch' => 'dalvik',
            'data' => [
                'version' => substr($magic, 4, 3),
                'string_count' => $stringSize,
                'type_count' => $typeSize,
                'method_count' => $methodSize,
                'class_count' => $classSize,
                'strings' => $strings,
                'classes' => $classes,
                'methods' => $methods,
                'warnings' => $warnings,
            ],
        ];
    }

    /** @return array{int, int}|null [value, nextOffset] */
    public static function uleb128(ByteReader $reader, int $offset): ?array
    {
        $value = 0;
        $shift = 0;
        for ($i = 0; $i < 5; $i++) {
            $byte = $reader->u8($offset + $i);
            if ($byte === null) {
                return null;
            }
            $value |= ($byte & 0x7F) << $shift;
            if (($byte & 0x80) === 0) {
                return [$value, $offset + $i + 1];
            }
            $shift += 7;
        }

        return null;
    }
}
