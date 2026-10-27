<?php

namespace App\Services\Revival;

/**
 * Bounded, deterministic partial extraction for oversized source files.
 *
 * Pure line-based strategy (no heavyweight parsers): keeps imports, symbol
 * definitions, hardware-relevant sections and file boundaries, and always
 * records the original 1-based line ranges so excerpts are never mistaken
 * for complete files.
 */
class FileExtractor
{
    private const HEAD_LINES = 30;

    private const TAIL_LINES = 10;

    private const CONTEXT_LINES = 3;

    /** Maximum share of the byte budget consumed by head ranges. */
    private const HEAD_SHARE = 0.6;

    /** Tier 1: definitions, imports, entry points. */
    private const SYMBOL_PATTERNS = [
        '/^\s*(?:public|private|protected|static|async|export|pub(?:\([^)]*\))?|fn|func|def|class|interface|struct|enum|trait|type\s+\w+|sub|function)\b/',
        '/^\s*#\s*(?:include|define|pragma|ifdef|ifndef|endif|import)\b/',
        '/^\s*(?:import|from|package|module|require)\b/',
        '/if\s+__name__\s*==/',
        '/\bint\s+main\s*\(/',
        '/\bfunc\s+main\s*\(/',
        '/\bfn\s+main\s*\(/',
        '/public\s+static\s+void\s+main\s*\(/',
        '/\b(module\.exports|export\s+default)\b/',
    ];

    /** Tier 2: assignments, constants, element ids (useful, but filler-prone). */
    private const SECONDARY_PATTERNS = [
        '/^\s*(?:const|let|var)\s+\w+\s*=/',
        '/^\s*[A-Z][A-Z0-9_]{2,}\s*=/',
        '/\bid="[^"]+"/',
    ];

    private const HARDWARE_KEYWORDS = [
        'firmware', 'driver', 'usb', 'hid', 'uart', 'i2c', 'spi', 'gpio',
        'bluetooth', 'ble', 'radio', 'protocol', 'packet', 'infrared',
        'remote', 'sensor', 'motor', 'servo', 'bootloader', 'flash',
        'eeprom', 'register', 'peripheral', 'stm32', 'esp32', 'arduino',
        'ioctl', 'endpoint', 'descriptor', 'payload', 'checksum', 'crc',
    ];

    /**
     * @return array{content: string, complete: bool, ranges: list<array{start: int, end: int}>, original_bytes: int, original_lines: int, note: ?string}
     */
    public static function extract(string $path, string $content, int $maxBytes, int $maxChunkBytes): array
    {
        $originalBytes = strlen($content);

        if ($content === '') {
            return self::result('', true, [], 0, 0, null);
        }

        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));

        // A trailing newline terminates the last line; it is not an extra line.
        if (end($lines) === '') {
            array_pop($lines);
        }

        $totalLines = count($lines);

        if ($originalBytes <= $maxBytes) {
            return self::result($content, true, [['start' => 1, 'end' => $totalLines]], $originalBytes, $totalLines, null);
        }

        // Minified / single-line blobs: no line structure to exploit.
        if ($totalLines < 5) {
            $excerpt = substr($content, 0, $maxBytes);

            return self::result(
                $excerpt."\n... [truncated within line 1 of {$totalLines}; {$originalBytes} original bytes] ...",
                false,
                [['start' => 1, 'end' => 1]],
                $originalBytes,
                $totalLines,
                'minified content: head excerpt only'
            );
        }

        // Overlong single lines are hard-cut up front so every downstream
        // range stays line-aligned.
        $lineCap = min($maxChunkBytes, $maxBytes);
        $longLinesCut = false;

        foreach ($lines as $index => $line) {
            if (strlen($line) > $lineCap) {
                $lines[$index] = substr($line, 0, $lineCap);
                $longLinesCut = true;
            }
        }

        $tiers = self::interestingTiers($lines, $totalLines);
        $taken = self::takeTiered($tiers, $lines, $maxBytes, min($maxChunkBytes, $maxBytes));

        if ($taken === []) {
            // Nothing fits (pathological budget): hard-cut the head lines.
            $head = implode("\n", array_slice($lines, 0, self::HEAD_LINES));
            $excerpt = substr($head, 0, $maxBytes);

            return self::result(
                $excerpt."\n... [truncated; {$originalBytes} original bytes] ...",
                false,
                [['start' => 1, 'end' => self::HEAD_LINES]],
                $originalBytes,
                $totalLines,
                'head excerpt only'
            );
        }

        $merged = self::mergeRanges($taken);
        $rendered = self::render($merged, $lines, $totalLines);

        $keptLines = array_sum(array_map(fn ($r) => $r[1] - $r[0] + 1, $merged));

        $note = sprintf('partial: %d of %d lines in %d ranges', $keptLines, $totalLines, count($merged));

        if ($longLinesCut) {
            $note .= '; overlong lines truncated';
        }

        return self::result(
            $rendered,
            false,
            array_map(fn ($r) => ['start' => $r[0], 'end' => $r[1]], $merged),
            $originalBytes,
            $totalLines,
            $note
        );
    }

    /**
     * Flagged 1-based line numbers grouped by priority tier:
     * 0 = head, 1 = symbols/imports/entry points/hardware, 2 = secondary + tail.
     *
     * The tail epilogue deliberately ranks last: genuine entry points
     * (main functions, module exports) already match tier 1 patterns, so a
     * padding-filled tail must never crowd out symbol sections.
     *
     * @return list<list<array{0: int, 1: int}>>
     */
    private static function interestingTiers(array $lines, int $totalLines): array
    {
        $flagged = [[], [], []];

        $headEnd = min(self::HEAD_LINES, $totalLines);
        for ($i = 1; $i <= $headEnd; $i++) {
            $flagged[0][$i] = true;
        }

        $tailStart = max(1, $totalLines - self::TAIL_LINES + 1);
        for ($i = $tailStart; $i <= $totalLines; $i++) {
            $flagged[2][$i] = true;
        }

        foreach ($lines as $index => $line) {
            $tier = self::lineTier($line);

            if ($tier === null) {
                continue;
            }

            $number = $index + 1;

            for ($i = max(1, $number - self::CONTEXT_LINES); $i <= min($totalLines, $number + self::CONTEXT_LINES); $i++) {
                // Context never demotes a higher-priority flag.
                if (! isset($flagged[0][$i]) && ! isset($flagged[1][$i])) {
                    $flagged[$tier][$i] = true;
                } elseif ($tier === 1 && ! isset($flagged[0][$i])) {
                    $flagged[1][$i] = true;
                }
            }
        }

        return array_map(self::numbersToRanges(...), $flagged);
    }

    /** @return list<array{0: int, 1: int}> */
    private static function numbersToRanges(array $flagged): array
    {
        $numbers = array_keys($flagged);
        sort($numbers);

        $ranges = [];

        foreach ($numbers as $number) {
            $last = count($ranges) - 1;

            if ($last >= 0 && $number <= $ranges[$last][1] + 1) {
                $ranges[$last][1] = $number;
            } else {
                $ranges[] = [$number, $number];
            }
        }

        return $ranges;
    }

    /** @return ?int 1 for symbols/hardware, 2 for secondary, null for plain. */
    private static function lineTier(string $line): ?int
    {
        if (strlen($line) > 500) {
            return null;
        }

        foreach (self::SYMBOL_PATTERNS as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return 1;
            }
        }

        if (self::looksLikeCFunction($line)) {
            return 1;
        }

        $lower = strtolower($line);

        foreach (self::HARDWARE_KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                return 1;
            }
        }

        foreach (self::SECONDARY_PATTERNS as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return 2;
            }
        }

        return null;
    }

    private static function looksLikeCFunction(string $line): bool
    {
        if (strlen($line) > 160 || str_contains($line, ';') || str_contains($line, '=')) {
            return false;
        }

        if (! str_contains($line, '(') || ! preg_match('/^[A-Za-z_]/', $line)) {
            return false;
        }

        return preg_match('/^[A-Za-z_][A-Za-z0-9_\s\*]+[A-Za-z_0-9\)]\s*\([^;{}]*\)\s*\{?\s*$/', $line) === 1
            && preg_match('/\b(if|for|while|switch|return|sizeof)\b/', $line) !== 1;
    }

    /** @param  list<array{0: int, 1: int}>  $ranges */
    private static function mergeRanges(array $ranges): array
    {
        usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);

        $merged = [];

        foreach ($ranges as $range) {
            $last = count($merged) - 1;

            if ($last >= 0 && $range[0] <= $merged[$last][1] + 1) {
                $merged[$last][1] = max($merged[$last][1], $range[1]);
            } else {
                $merged[] = $range;
            }
        }

        return $merged;
    }

    /**
     * Take ranges tier by tier (head, then symbols, then secondary/tail),
     * line-aligned and within the per-chunk and file budgets. Head ranges
     * stop once they exceed their budget share so symbol sections always
     * get room.
     *
     * @param  list<list<array{0: int, 1: int}>>  $tiers
     * @param  list<string>  $lines  0-based.
     * @return list<array{0: int, 1: int}>
     */
    private static function takeTiered(array $tiers, array $lines, int $maxBytes, int $chunkCap): array
    {
        $taken = [];
        $used = 0;
        $headAllowance = (int) floor($maxBytes * self::HEAD_SHARE);

        foreach ($tiers as $tier => $ranges) {
            foreach (self::splitRanges($ranges, $lines, $chunkCap) as [$start, $end]) {
                $bytes = self::rangeBytes($start, $end, $lines);

                if ($used + $bytes > $maxBytes) {
                    // Tier 0 yields to symbols; later tiers stop entirely.
                    if ($tier === 0) {
                        break;
                    }

                    break 2;
                }

                $used += $bytes;
                $taken[] = [$start, $end];

                if ($tier === 0 && $used >= $headAllowance) {
                    break;
                }
            }
        }

        return $taken;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $ranges
     * @return list<array{0: int, 1: int}>
     */
    private static function splitRanges(array $ranges, array $lines, int $chunkCap): array
    {
        $pieces = [];

        foreach ($ranges as [$start, $end]) {
            $pieceStart = $start;
            $pieceBytes = 0;

            for ($number = $start; $number <= $end; $number++) {
                $lineBytes = strlen($lines[$number - 1]) + 1;

                if ($pieceBytes + $lineBytes > $chunkCap && $number > $pieceStart) {
                    $pieces[] = [$pieceStart, $number - 1];
                    $pieceStart = $number;
                    $pieceBytes = 0;
                }

                $pieceBytes += $lineBytes;
            }

            if ($pieceStart <= $end) {
                $pieces[] = [$pieceStart, $end];
            }
        }

        return $pieces;
    }

    /** @param  list<string>  $lines  0-based. */
    private static function rangeBytes(int $start, int $end, array $lines): int
    {
        $bytes = 0;

        for ($number = $start; $number <= $end; $number++) {
            $bytes += strlen($lines[$number - 1]) + 1;
        }

        return $bytes;
    }

    /** @param  list<array{0: int, 1: int}>  $ranges */
    private static function render(array $ranges, array $lines, int $totalLines): string
    {
        $out = [];
        $previousEnd = 0;

        foreach ($ranges as [$start, $end]) {
            if ($start > $previousEnd + 1) {
                $from = $previousEnd + 1;
                $to = $start - 1;
                $out[] = "... [omitted lines {$from}–{$to} of {$totalLines}] ...";
            }

            for ($number = $start; $number <= $end; $number++) {
                $out[] = $lines[$number - 1];
            }

            $previousEnd = $end;
        }

        if ($previousEnd < $totalLines) {
            $out[] = '... [omitted lines '.($previousEnd + 1)."–{$totalLines} of {$totalLines}] ...";
        }

        return implode("\n", $out);
    }

    /** @return array{content: string, complete: bool, ranges: list<array{start: int, end: int}>, original_bytes: int, original_lines: int, note: ?string} */
    private static function result(string $content, bool $complete, array $ranges, int $bytes, int $lines, ?string $note): array
    {
        return [
            'content' => $content,
            'complete' => $complete,
            'ranges' => $ranges,
            'original_bytes' => $bytes,
            'original_lines' => $lines,
            'note' => $note,
        ];
    }
}
