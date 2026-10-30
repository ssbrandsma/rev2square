<?php

namespace App\Services\Binary;

use App\Models\ProjectArtifact;
use App\Models\WorkerCapability;
use App\Services\Binary\Adapters\AdapterRegistry;
use App\Services\Binary\Adapters\Capability;
use App\Services\Binary\Adapters\ExternalBackend;

/**
 * Per-artifact capability rows for the UI and the inspect_binary
 * tool. Each row pairs one adapter with one target and reports
 * independent capability levels — never a single "supported" flag.
 */
final class CapabilityMatrix
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array{adapter: string, adapter_name: string, available: bool, availability_reason: string, worker_verified: bool, worker_note: string, target: ?string, target_name: ?string, unverified_backend_id: ?string, capabilities: array<string, string>, requestable: bool, note: string}>
     */
    public static function forArtifact(ProjectArtifact $artifact, ?array $snapshot): array
    {
        $snapshot ??= [];
        $candidates = Targets::candidatesFor($artifact->format, $artifact->arch, $snapshot);
        $rows = [];

        foreach (AdapterRegistry::all() as $adapter) {
            $probe = $adapter->probe();
            $worker = WorkerCapability::verifiedFor($adapter);
            // Execution readiness: subprocess backends answer from the
            // worker snapshot; the bundled adapter from its probe.
            $execReady = $adapter instanceof ExternalBackend ? $worker['verified'] : $probe['available'];
            $formats = $adapter->formats();
            if (! in_array($artifact->format, $formats, true)) {
                continue;
            }

            $targets = [];
            foreach ($candidates as $candidate) {
                $targets[$candidate['id']] = ['source' => $candidate['source'], 'note' => $candidate['note'], 'unverified' => null];
            }

            // Verified targets the user may pick for open-ended formats
            // (raw-like directly, archives per member).
            if (in_array($artifact->format, [
                ProjectArtifact::FORMAT_RAW, ProjectArtifact::FORMAT_IHEX, ProjectArtifact::FORMAT_SREC,
                ProjectArtifact::FORMAT_ARCHIVE,
            ], true)) {
                foreach ($adapter->targets() as $verifiedId) {
                    if (! isset($targets[$verifiedId])) {
                        $note = $artifact->format === ProjectArtifact::FORMAT_ARCHIVE
                            ? 'User-selected target for an archive member (member + target required).'
                            : 'User-selected target (no structural identification).';
                        $targets[$verifiedId] = ['source' => 'user_config', 'note' => $note, 'unverified' => null];
                    }
                }
            } else {
                // Structured formats with an unrecognized machine (not
                // merely unmapped) may still be user-configured
                // tentatively through any verified target.
                foreach ($adapter->targets() as $verifiedId) {
                    if (! isset($targets[$verifiedId]) && self::tentativeApplicable($artifact)) {
                        $targets[$verifiedId] = ['source' => 'user_config', 'note' => 'Tentative user-selected target (machine unrecognized).', 'unverified' => null];
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
                    'worker_verified' => $worker['verified'],
                    'worker_note' => $worker['note'],
                    'target' => $target,
                    'target_name' => $tinfo['name'] ?? null,
                    'target_source' => $meta['source'],
                    'unverified_backend_id' => $meta['unverified'],
                    'capabilities' => $caps,
                    'requestable' => $execReady && Capability::requestable($caps),
                    'note' => $meta['note'],
                ];
            }
        }

        return $rows;
    }

    /**
     * Selector options: one row per registered target, each marked
     * requestable exactly when dispatch would accept it (same matrix,
     * same worker rules — the selector cannot drift from routing).
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<array{id: string, name: string, requestable: bool, adapter: ?string, decompilation: ?string, reason: string}>
     */
    public static function targetOptions(ProjectArtifact $artifact, ?array $snapshot): array
    {
        $rows = self::forArtifact($artifact, $snapshot);
        $options = [];
        foreach (Targets::all() as $id => $info) {
            $mine = array_values(array_filter($rows, fn ($row) => $row['target'] === $id));
            $ready = array_values(array_filter($mine, fn ($row) => $row['requestable']));
            if ($ready !== []) {
                $options[] = [
                    'id' => $id,
                    'name' => $info['name'],
                    'requestable' => true,
                    'adapter' => $ready[0]['adapter_name'],
                    'decompilation' => $ready[0]['capabilities'][Capability::DECOMPILATION] ?? null,
                    'reason' => 'Ready via '.$ready[0]['adapter_name'],
                ];
            } else {
                $options[] = [
                    'id' => $id,
                    'name' => $info['name'],
                    'requestable' => false,
                    'adapter' => null,
                    'decompilation' => null,
                    'reason' => self::unavailableReason($mine),
                ];
            }
        }

        return $options;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private static function unavailableReason(array $rows): string
    {
        if ($rows === []) {
            return 'No backend covers this target for this file.';
        }
        // A backend that supports the pair but lacks worker
        // verification is the most actionable story: lead with it.
        foreach ($rows as $row) {
            if (Capability::requestable($row['capabilities'])) {
                return self::shorten($row['adapter_name'].': '.$row['worker_note']);
            }
        }
        // Otherwise prefer a worker-verified capability verdict over a
        // local missing-binary guess.
        foreach ($rows as $row) {
            if ($row['worker_verified']) {
                foreach (Capability::primaryOutputs() as $output) {
                    $level = $row['capabilities'][$output] ?? Capability::UNSUPPORTED;
                    if ($level !== Capability::UNSUPPORTED) {
                        return self::shorten($row['adapter_name'].': '.Capability::label($output).' '.Capability::levelLabel($level).'.');
                    }
                }

                return self::shorten($row['adapter_name'].': no supported output for this pair.');
            }
        }

        return self::shorten($rows[0]['adapter_name'].' unavailable: '.$rows[0]['availability_reason']);
    }

    private static function shorten(string $reason): string
    {
        return mb_strlen($reason) > 140 ? mb_substr($reason, 0, 137).'…' : $reason;
    }

    private static function tentativeApplicable(ProjectArtifact $artifact): bool
    {
        // PRG is structurally 6502; other targets would be lies.
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
