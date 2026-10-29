<?php

namespace App\Services\Binary;

use App\Models\ProjectArtifact;
use App\Services\Binary\Adapters\AdapterRegistry;
use App\Services\Binary\Adapters\Capability;

/**
 * Per-artifact capability rows for the UI and the inspect_binary
 * tool. Each row pairs one adapter with one target and reports
 * independent capability levels — never a single "supported" flag.
 */
final class CapabilityMatrix
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array{adapter: string, adapter_name: string, available: bool, availability_reason: string, target: ?string, target_name: ?string, unverified_backend_id: ?string, capabilities: array<string, string>, requestable: bool, note: string}>
     */
    public static function forArtifact(ProjectArtifact $artifact, ?array $snapshot): array
    {
        $snapshot ??= [];
        $candidates = Targets::candidatesFor($artifact->format, $artifact->arch, $snapshot);
        $rows = [];

        foreach (AdapterRegistry::all() as $adapter) {
            $probe = $adapter->probe();
            $formats = $adapter->formats();
            if (! in_array($artifact->format, $formats, true)) {
                continue;
            }

            $targets = [];
            foreach ($candidates as $candidate) {
                $targets[$candidate['id']] = ['source' => $candidate['source'], 'note' => $candidate['note'], 'unverified' => null];
            }

            // Native targets the user may pick for open-ended formats.
            if ($adapter->id() === 'php-native') {
                foreach (Targets::nativeIds() as $nativeId) {
                    if (! isset($targets[$nativeId]) && self::nativeApplicable($artifact, $nativeId)) {
                        $targets[$nativeId] = ['source' => 'user_config', 'note' => 'User-selected target (no structural identification).', 'unverified' => null];
                    }
                }
            }

            // External backends: unverified documented candidates.
            foreach ($adapter->candidateTargets() as $candidate) {
                if (! isset($targets[$candidate['id']])) {
                    $targets[$candidate['id']] = ['source' => 'backend_documented', 'note' => $candidate['note'], 'unverified' => $candidate['backend_id']];
                }
            }

            if ($targets === [] && $candidates === []) {
                // Adapter covers the format but no target is known at
                // all (e.g. unrecognized machine): one honest row.
                $targets[''] = ['source' => 'unknown', 'note' => 'No processor target identified for this file.', 'unverified' => null];
            }

            foreach ($targets as $targetId => $meta) {
                $target = $targetId === '' ? null : $targetId;
                $caps = $adapter->capabilities($artifact->format, $target);
                $tinfo = $target !== null ? Targets::get($target) : null;
                $rows[] = [
                    'adapter' => $adapter->id(),
                    'adapter_name' => $adapter->name(),
                    'available' => $probe['available'],
                    'availability_reason' => $probe['reason'],
                    'target' => $target,
                    'target_name' => $tinfo['name'] ?? null,
                    'target_source' => $meta['source'],
                    'unverified_backend_id' => $meta['unverified'],
                    'capabilities' => $caps,
                    'requestable' => $probe['available'] && ($caps[Capability::DISASSEMBLY] ?? null) === Capability::SUPPORTED,
                    'note' => $meta['note'],
                ];
            }
        }

        return $rows;
    }

    private static function nativeApplicable(ProjectArtifact $artifact, string $nativeId): bool
    {
        // Open-ended formats accept any native target via user config.
        if (in_array($artifact->format, [
            ProjectArtifact::FORMAT_RAW, ProjectArtifact::FORMAT_IHEX, ProjectArtifact::FORMAT_SREC,
        ], true)) {
            return true;
        }

        // PRG is structurally 6502; other native targets would be lies.
        if ($artifact->format === ProjectArtifact::FORMAT_PRG) {
            return false;
        }

        // Structured formats with an unrecognized machine (not merely
        // unmapped) may still be user-configured tentatively.
        return in_array($artifact->format, [
            ProjectArtifact::FORMAT_ELF, ProjectArtifact::FORMAT_PE, ProjectArtifact::FORMAT_MACHO,
        ], true) && $artifact->arch === null;
    }
}
