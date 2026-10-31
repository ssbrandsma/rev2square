<?php

namespace App\Services\Binary\Adapters;

/**
 * Tool-version hygiene for the coordinator side of the spool boundary.
 *
 * Runners report raw tool banners (objdump --version is a five-line
 * GPL notice; the runner caps it at 512 bytes). The coordinator needs
 * two shapes: a concise single-line identifier that always fits the
 * 64-char version columns (worker_capabilities.version,
 * binary_analyses.adapter_version) for persistence, display, dedupe
 * and worker_mismatch comparison — and the bounded full banner for
 * snapshot diagnostics. Both are pure and deterministic: the same
 * tool output always yields the same strings.
 */
final class ToolVersion
{
    /** Width of the version identifier columns (both tables). */
    public const IDENTIFIER_MAX = 64;

    /** Bound for the full diagnostic banner in snapshot details. */
    public const FULL_MAX = 2000;

    /**
     * Concise single-line version identifier, safe to persist,
     * display, and compare. Null when nothing usable remains.
     */
    public static function identifier(mixed $raw, int $max = self::IDENTIFIER_MAX): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $max = max(1, $max);
        $scrubbed = mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
        $line = null;
        foreach (preg_split("/\r\n|\r|\n/", $scrubbed) ?: [] as $candidate) {
            if (trim($candidate) !== '') {
                $line = $candidate;
                break;
            }
        }
        if ($line === null) {
            return null;
        }
        // Collapse whitespace first (tabs become spaces, not token
        // joins), then strip controls and format characters
        // (including bidi overrides that could spoof versions).
        $clean = (string) preg_replace('/\s+/u', ' ', $line);
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F\p{Cf}]/u', '', $clean));
        if ($clean === '') {
            return null;
        }

        return mb_strlen($clean) > $max ? mb_substr($clean, 0, $max) : $clean;
    }

    /**
     * Full tool banner for snapshot diagnostics: multiline kept,
     * bounded, control-stripped, safe to store and (escaped) render.
     */
    public static function full(mixed $raw, int $max = self::FULL_MAX): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $max = max(1, $max);
        $scrubbed = mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
        $scrubbed = str_replace(["\r\n", "\r"], "\n", $scrubbed);
        $clean = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\p{Cf}]/u', '', $scrubbed));
        if ($clean === '') {
            return null;
        }

        return mb_strlen($clean) > $max ? mb_substr($clean, 0, $max) : $clean;
    }
}
