<?php

namespace App\Services\Binary;

use App\Models\ProjectArtifact;
use App\Models\User;

/**
 * Target resolution order: structural metadata → user device/CPU
 * context → explicit confirmed override → heuristics (tentative
 * suggestions only, never silent). Incompatible overrides are
 * rejected with an explanation; every accepted override records
 * its author.
 */
final class TargetResolver
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array{target?: ?string, base_address?: mixed, entry?: mixed, platform?: mixed}|array<string, mixed>  $input
     * @return array{ok: bool, target?: string, config?: array<string, mixed>, source?: string, note?: string, errors?: list<string>}
     */
    public static function resolve(ProjectArtifact $artifact, array $snapshot, array $input, ?User $author = null): array
    {
        $format = $artifact->format;
        $candidates = Targets::candidatesFor($format, $artifact->arch, $snapshot);
        $structural = $candidates[0] ?? null;

        $requested = is_string($input['target'] ?? null) && $input['target'] !== '' ? $input['target'] : null;
        if ($requested !== null && ! Targets::exists($requested)) {
            return ['ok' => false, 'errors' => ['Unknown processor target “'.$requested.'”. Choose one of: '.implode(', ', array_keys(Targets::all())).'.']];
        }

        // Member analysis: the member is the subject, so archive-level
        // structural candidates do not apply. The member's real format
        // is detected from bytes at job time; dispatch validates what
        // the snapshot already proves (existence, method, size).
        $member = is_string($input['member'] ?? null) && $input['member'] !== '' ? $input['member'] : null;
        $memberSize = null;
        if ($format === ProjectArtifact::FORMAT_ARCHIVE && $member !== null) {
            $checked = self::checkMember($member, $snapshot);
            if (! ($checked['ok'] ?? false)) {
                return ['ok' => false, 'errors' => [$checked['error']]];
            }
            $memberSize = $checked['size'];
            $structural = null;
        }
        if ($format === ProjectArtifact::FORMAT_ARCHIVE && $member === null && $structural === null) {
            return ['ok' => false, 'errors' => ['This archive has no whole-file processor target. Select the archive member to analyze, plus its exact processor target.']];
        }

        // Raw images and address-only formats have no structural
        // target: the user must supply one (no silent default).
        $needsTarget = $structural === null;
        if ($needsTarget && $requested === null) {
            return ['ok' => false, 'errors' => ['This file carries no processor identification. Select the exact processor target before analysis.']];
        }

        // An explicit override contradicting structural evidence is
        // rejected — e.g. Z80 for a PE that declares x86-64.
        if ($requested !== null && $structural !== null && $requested !== $structural['id']
            && ! self::compatibleOverride($format, $structural['id'], $requested)) {
            return ['ok' => false, 'errors' => [
                'Target “'.$requested.'” contradicts the file: '.$structural['note'].' Analysis would decode garbage.',
            ]];
        }

        $target = $requested ?? $structural['id'];
        $source = $requested !== null && $structural !== null && $requested !== $structural['id'] ? 'override' : ($requested !== null && $structural === null ? 'user_config' : $structural['source']);

        $config = ['target' => $target, 'source' => $source];
        if ($member !== null) {
            $config['member'] = $member;
        }
        if ($source === 'override' || $source === 'user_config') {
            $config['configured_by'] = $author?->id;
            $config['configured_at'] = now()->toIso8601String();
        }

        // Base address: required for raw; optional informational for
        // address-bearing formats (validated, never silently shifted).
        $needsBase = $format === ProjectArtifact::FORMAT_RAW;
        $base = $input['base_address'] ?? null;
        if ($needsBase && ($base === null || $base === '')) {
            return ['ok' => false, 'errors' => ['A raw image needs a load/base address (hex, e.g. 0x8000). Without it, disassembly addresses are meaningless.']];
        }
        if ($base !== null && $base !== '') {
            $parsed = self::parseAddress($base, Targets::get($target)['bits']);
            if ($parsed === null) {
                return ['ok' => false, 'errors' => ['Base address “'.$base.'” is not a valid hex/decimal address for this '.Targets::get($target)['bits'].'-bit target.']];
            }
            $config['base_address'] = $parsed;
        }

        $entry = $input['entry'] ?? null;
        if ($entry !== null && $entry !== '') {
            $parsed = self::parseAddress($entry, Targets::get($target)['bits']);
            if ($parsed === null) {
                return ['ok' => false, 'errors' => ['Entry address “'.$entry.'” is not a valid address for this target.']];
            }
            $config['entry'] = $parsed;
        }

        $platform = $input['platform'] ?? null;
        if (is_string($platform) && $platform !== '') {
            if (! in_array($platform, ['c64', 'generic'], true)) {
                return ['ok' => false, 'errors' => ['Unknown platform context “'.$platform.'”. Supported: c64, generic.']];
            }
            $config['platform'] = $platform;
        }

        // Range validation against the image (or member) size.
        $byteSize = $memberSize ?? (int) $artifact->byte_size;
        if (isset($config['base_address']) && ($format === ProjectArtifact::FORMAT_RAW || $member !== null)) {
            $bits = Targets::get($target)['bits'];
            $max = $bits >= 64 ? PHP_INT_MAX : ((1 << $bits) - 1);
            if ($max < $config['base_address'] + $byteSize - 1) {
                return ['ok' => false, 'errors' => ['Base address plus image size exceeds the '.$bits.'-bit address space.']];
            }
        }
        if (isset($config['entry']) && ($format === ProjectArtifact::FORMAT_RAW || $member !== null) && isset($config['base_address'])) {
            if ($config['entry'] < $config['base_address'] || $config['entry'] >= $config['base_address'] + $byteSize) {
                return ['ok' => false, 'errors' => ['Entry address lies outside the mapped image range.']];
            }
        }

        return [
            'ok' => true,
            'target' => $target,
            'config' => $config,
            'source' => $source,
            'note' => $requested !== null && $structural !== null
                ? 'Explicit override recorded.'
                : ($structural['note'] ?? 'User-supplied configuration.'),
        ];
    }

    /**
     * Dispatch-time member check against the extraction snapshot.
     * The job re-validates against the live central directory —
     * snapshots can be stale, and dispatch must never be trusted.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{ok: bool, size?: int, error?: string}
     */
    public static function checkMember(string $member, array $snapshot): array
    {
        $found = null;
        foreach (($snapshot['data']['members'] ?? []) as $entry) {
            if (($entry['name'] ?? null) === $member) {
                $found = $entry;
                break;
            }
        }
        if ($found === null) {
            return ['ok' => false, 'error' => 'Archive member “'.$member.'” is not listed in this archive. Pick one from the member list.'];
        }
        if (! empty($found['encrypted'])) {
            return ['ok' => false, 'error' => 'Archive member “'.$member.'” is encrypted. Decrypt it before analysis.'];
        }
        if (! in_array($found['method'] ?? null, ['stored', 'deflated'], true)) {
            return ['ok' => false, 'error' => 'Archive member “'.$member.'” uses an unsupported compression method.'];
        }
        $size = (int) ($found['size'] ?? 0);
        if ($size <= 0) {
            return ['ok' => false, 'error' => 'Archive member “'.$member.'” is empty. There is nothing to analyze.'];
        }
        $cap = (int) config('artifacts.binary_max_archive_member_bytes', 32 * 1024 * 1024);
        if ($size > $cap) {
            return ['ok' => false, 'error' => 'Archive member “'.$member.'” ('.number_format($size).' bytes) exceeds the analysis staging cap ('.number_format($cap).' bytes).'];
        }

        return ['ok' => true, 'size' => $size];
    }

    /**
     * Overrides that stay within the same architecture family are
     * allowed (ARM state vs Thumb state); cross-architecture swaps
     * are rejected.
     */
    public static function compatibleOverride(string $format, string $structural, string $requested): bool
    {
        $pair = [$structural, $requested];
        sort($pair);

        return $pair === ['arm32', 'arm32-thumb'];
    }

    /**
     * Parse "0x8000", "8000h"? No — hex with 0x prefix or plain
     * decimal only. Anything else is rejected, never guessed.
     */
    public static function parseAddress(mixed $value, int $bits): ?int
    {
        $text = trim((string) $value);
        if (preg_match('/\A0[xX]([0-9a-fA-F]{1,16})\z/', $text, $m) === 1) {
            $int = hexdec($m[1]);
        } elseif (preg_match('/\A(\d{1,20})\z/', $text, $m) === 1) {
            $int = (int) $m[1];
            if ((string) $int !== $m[1]) {
                return null;
            }
        } else {
            return null;
        }

        if (! is_int($int) || $int < 0) {
            return null;
        }
        $max = $bits >= 64 ? PHP_INT_MAX : ((1 << $bits) - 1);

        return $int <= $max ? $int : null;
    }
}
