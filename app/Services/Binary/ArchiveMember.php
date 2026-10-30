<?php

namespace App\Services\Binary;

use App\Models\ProjectArtifact;
use App\Services\Artifacts\ArtifactExtractor;
use App\Services\Artifacts\ElfParser;
use App\Services\Binary\PeParser;
use App\Services\Binary\PrgParser;

/**
 * Archive-member staging for analysis. Extracts ONE member with
 * explicit budgets, detects its real format from bytes (never from
 * the member name), parses structural metadata for it, and validates
 * the requested target against that evidence.
 *
 * Selecting a member is the explicit opt-in for unrecognized bytes:
 * a member that detects as unknown is treated as a raw image (which
 * then needs a target and base address, like any raw upload).
 *
 * @return array{ok: bool, bytes?: string, format?: string, arch?: ?string, snapshot?: array<string, mixed>, info?: array{name: string, size: int, sha256: string, format: string, arch: ?string}, code?: string, message?: string}
 */
final class ArchiveMember
{
    public static function prepare(string $zipBytes, string $member, ?string $requestedTarget, array $config): array
    {
        $cap = (int) config('artifacts.binary_max_archive_member_bytes', 32 * 1024 * 1024);
        $cd = ZipReader::centralDirectory($zipBytes);
        if (! ($cd['ok'] ?? false)) {
            return self::fail('member_unreadable', 'The archive central directory does not parse ('.($cd['error'] ?? 'unknown').').');
        }

        $entry = null;
        foreach ($cd['entries'] as $candidate) {
            if ($candidate['name'] === $member) {
                $entry = $candidate;
                break;
            }
        }
        if ($entry === null) {
            return self::fail('member_not_found', 'Archive member “'.$member.'” is not in this file. It may have been re-uploaded since the page was rendered.');
        }
        if ($entry['encrypted']) {
            return self::fail('member_encrypted', 'Archive member “'.$member.'” is encrypted. Decrypt it before analysis.');
        }
        if (! in_array($entry['method'], [0, 8], true)) {
            return self::fail('member_unsupported_method', 'Archive member “'.$member.'” uses an unsupported compression method.');
        }
        if ($entry['size'] <= 0) {
            return self::fail('member_empty', 'Archive member “'.$member.'” is empty. There is nothing to analyze.');
        }
        if ($entry['size'] > $cap) {
            return self::fail('member_too_large', 'Archive member “'.$member.'” ('.number_format($entry['size']).' bytes) exceeds the analysis staging cap ('.number_format($cap).' bytes).');
        }

        $bytes = $entry['method'] === 0
            ? ZipReader::readStored($zipBytes, $entry)
            : ZipReader::readInflated($zipBytes, $entry, $cap);
        if ($bytes === null) {
            return self::fail('member_unreadable', 'Archive member “'.$member.'” could not be extracted. The archive may be corrupt.');
        }

        $detection = FormatDetector::detect($bytes, $member);
        $format = $detection['format'] ?? ProjectArtifact::FORMAT_UNKNOWN;
        if ($format === ProjectArtifact::FORMAT_UNKNOWN) {
            $format = ProjectArtifact::FORMAT_RAW;
        }

        $snapshot = ['data' => []];
        $arch = null;
        if ($format === ProjectArtifact::FORMAT_ELF) {
            $parsed = ElfParser::parse($bytes, ArtifactExtractor::elfLimits());
            if (! ($parsed['ok'] ?? false)) {
                return self::fail('member_unparseable', 'Archive member “'.$member.'” looks like ELF but does not parse ('.($parsed['error'] ?? 'unknown').').');
            }
            $snapshot = ['data' => $parsed['data'] ?? []];
            $arch = $parsed['arch'] ?? null;
        } elseif ($format === ProjectArtifact::FORMAT_PE) {
            $parsed = PeParser::parse($bytes, ArtifactExtractor::peLimits());
            if (! ($parsed['ok'] ?? false)) {
                return self::fail('member_unparseable', 'Archive member “'.$member.'” looks like PE but does not parse ('.($parsed['error'] ?? 'unknown').').');
            }
            $snapshot = ['data' => $parsed['data'] ?? []];
            $arch = $parsed['arch'] ?? null;
        } elseif ($format === ProjectArtifact::FORMAT_PRG) {
            $parsed = PrgParser::parse($bytes);
            if (! ($parsed['ok'] ?? false)) {
                return self::fail('member_unparseable', 'Archive member “'.$member.'” looks like a C64 PRG but does not parse.');
            }
            $snapshot = ['data' => $parsed['data'] ?? []];
            $arch = $parsed['arch'] ?? 'mos6502';
        } elseif (! in_array($format, [
            ProjectArtifact::FORMAT_RAW, ProjectArtifact::FORMAT_IHEX, ProjectArtifact::FORMAT_SREC,
        ], true)) {
            return self::fail('member_format_unsupported', 'Archive member “'.$member.'” is '.$format.' — no verified backend analyzes that member format yet.');
        }

        // Structural member evidence constrains the target exactly like
        // a top-level file: the ARM/Thumb pair stays switchable, every
        // cross-architecture combination is refused with its reason.
        $target = $requestedTarget ?? '';
        $structural = Targets::candidatesFor($format, $arch, $snapshot)[0] ?? null;
        if ($structural !== null && $target !== '' && $target !== $structural['id']
            && ! TargetResolver::compatibleOverride($format, $structural['id'], $target)) {
            return self::fail('member_target_mismatch', 'Target “'.$target.'” contradicts the member: '.$structural['note'].' Analysis would decode garbage.');
        }

        $rawLike = in_array($format, [
            ProjectArtifact::FORMAT_RAW, ProjectArtifact::FORMAT_IHEX, ProjectArtifact::FORMAT_SREC,
        ], true);
        if ($target === '' && $structural === null) {
            return self::fail('member_target_required', 'Archive member “'.$member.'” carries no processor identification. Select the exact processor target before analysis.');
        }
        if ($format === ProjectArtifact::FORMAT_RAW && ! isset($config['base_address'])) {
            return self::fail('member_base_required', 'Archive member “'.$member.'” is raw bytes. Re-queue with a load/base address (hex, e.g. 0x8000).');
        }
        if (! $rawLike && $target === '' && $structural !== null) {
            $target = $structural['id'];
        }

        return [
            'ok' => true,
            'bytes' => $bytes,
            'format' => $format,
            'arch' => $arch,
            'snapshot' => $snapshot,
            'target' => $target,
            'info' => [
                'name' => $member,
                'size' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
                'format' => $format,
                'arch' => $arch,
            ],
        ];
    }

    private static function fail(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}
