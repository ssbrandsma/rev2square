<?php

namespace App\Services\Binary;

use App\Jobs\AnalyzeBinaryJob;
use App\Models\BinaryAnalysis;
use App\Models\ProjectArtifact;
use App\Models\User;
use App\Models\WorkerCapability;
use App\Services\Binary\Adapters\AdapterRegistry;
use App\Services\Binary\Adapters\Capability;
use App\Services\Binary\Adapters\ExternalBackend;
use Illuminate\Database\QueryException;

/**
 * Validated, idempotent analysis dispatch. Resolution failures and
 * unavailable capabilities refuse with explanations — never with a
 * placeholder run. Re-requesting an identical configuration returns
 * the existing run and reports its ACTUAL state (never "processing"
 * unless a job is genuinely outstanding).
 */
final class AnalysisRequest
{
    /**
     * @param  array{target?: ?string, base_address?: mixed, entry?: mixed, platform?: mixed, adapter?: ?string}  $input
     * @return array{ok: bool, analysis?: BinaryAnalysis, reused?: bool, error?: string}
     */
    public static function request(ProjectArtifact $artifact, User $user, array $input): array
    {
        if (! in_array($artifact->state, [ProjectArtifact::STATE_READY, ProjectArtifact::STATE_PARTIAL, ProjectArtifact::STATE_NEEDS_CONFIGURATION], true)) {
            return ['ok' => false, 'error' => 'This file is '.$artifact->stateLabel().'. Analysis needs an extracted or configuration-pending artifact.'];
        }

        $snapshot = $artifact->readSnapshot() ?? [];
        $resolved = TargetResolver::resolve($artifact, $snapshot, $input, $user);
        if (! ($resolved['ok'] ?? false)) {
            return ['ok' => false, 'error' => implode(' ', $resolved['errors'] ?? ['Target configuration is invalid.'])];
        }

        $adapterId = is_string($input['adapter'] ?? null) && $input['adapter'] !== '' ? $input['adapter'] : null;
        if ($adapterId !== null && AdapterRegistry::get($adapterId) === null) {
            return ['ok' => false, 'error' => 'Unknown analysis backend “'.$adapterId.'”.'];
        }

        $adapter = $adapterId !== null ? AdapterRegistry::get($adapterId) : self::defaultAdapter($artifact, $resolved['target']);
        if ($adapter === null) {
            return ['ok' => false, 'error' => 'No available backend can analyze the “'.$resolved['target'].'” target for this file. '.self::missingBackendHint($artifact, $resolved['target'])];
        }

        // Worker-executed backends (subprocess or sandboxed): the
        // worker snapshot — not this container's binaries — decides.
        if ($adapter instanceof ExternalBackend || $adapter instanceof Adapters\SandboxedBackend) {
            $worker = WorkerCapability::verifiedFor($adapter);
            if (! $worker['verified']) {
                return ['ok' => false, 'error' => 'Backend “'.$adapter->name().'” is not worker-verified: '.$worker['note']];
            }
        } else {
            $probe = $adapter->probe();
            if (! $probe['available']) {
                return ['ok' => false, 'error' => 'Backend “'.$adapter->name().'” is unavailable: '.$probe['reason']];
            }
        }

        $caps = $adapter->capabilities($artifact->format, $resolved['target']);
        if (! Capability::requestable($caps)) {
            $levels = [];
            foreach (Capability::primaryOutputs() as $output) {
                $levels[] = Capability::label($output).': '.Capability::levelLabel($caps[$output] ?? Capability::UNSUPPORTED);
            }

            return ['ok' => false, 'error' => 'Backend “'.$adapter->name().'” produces no supported output for this file/target pair ('.implode('; ', $levels).'). '.self::missingBackendHint($artifact, $resolved['target'])];
        }

        $version = $adapter->version();
        $dedupe = BinaryAnalysis::dedupeKey($artifact, $adapter->id(), $version, $resolved['target'], $resolved['config']);
        $existing = BinaryAnalysis::where('dedupe_key', $dedupe)->first();
        if ($existing !== null) {
            return ['ok' => true, 'analysis' => $existing, 'reused' => true];
        }

        $overrides = [];
        if ($resolved['source'] === 'override' || $resolved['source'] === 'user_config') {
            $overrides[] = [
                'by' => $user->id,
                'at' => now()->toIso8601String(),
                'target' => $resolved['target'],
                'source' => $resolved['source'],
            ];
        }

        try {
            $analysis = BinaryAnalysis::create([
                'project_artifact_id' => $artifact->id,
                'requested_by' => $user->id,
                'adapter' => $adapter->id(),
                'adapter_version' => $version,
                'target' => $resolved['target'],
                'config' => $resolved['config'],
                'overrides' => $overrides,
                'status' => BinaryAnalysis::STATUS_QUEUED,
                'dedupe_key' => $dedupe,
            ]);
        } catch (QueryException $e) {
            // Concurrent identical request won the race: reuse theirs.
            $existing = BinaryAnalysis::where('dedupe_key', $dedupe)->first();
            if ($existing !== null) {
                return ['ok' => true, 'analysis' => $existing, 'reused' => true];
            }

            throw $e;
        }

        AnalyzeBinaryJob::dispatch($analysis->id);

        return ['ok' => true, 'analysis' => $analysis, 'reused' => false];
    }

    public static function defaultAdapter(ProjectArtifact $artifact, string $target): ?Adapters\BinaryAdapter
    {
        foreach (AdapterRegistry::all() as $adapter) {
            if (! in_array($artifact->format, $adapter->formats(), true)) {
                continue;
            }
            $caps = $adapter->capabilities($artifact->format, $target);
            if (self::dispatchReady($adapter) && Capability::requestable($caps)) {
                return $adapter;
            }
        }

        return null;
    }

    public static function dispatchReady(Adapters\BinaryAdapter $adapter): bool
    {
        // Snapshot-gated backends never consult a live probe at
        // dispatch: the web container has no spool access, and only a
        // worker-side capture proves execution readiness.
        if ($adapter instanceof ExternalBackend || $adapter instanceof Adapters\SandboxedBackend) {
            return WorkerCapability::verifiedFor($adapter)['verified'];
        }

        return $adapter->probe()['available'];
    }

    private static function missingBackendHint(ProjectArtifact $artifact, string $target): string
    {
        // A backend that supports the pair but is not execution-ready
        // (stale/missing worker snapshot) is the most actionable story.
        foreach (AdapterRegistry::all() as $adapter) {
            if (! in_array($artifact->format, $adapter->formats(), true)) {
                continue;
            }
            $caps = $adapter->capabilities($artifact->format, $target);
            if (Capability::requestable($caps) && ! self::dispatchReady($adapter)) {
                $worker = WorkerCapability::verifiedFor($adapter);

                return 'Ready backend found but not execution-ready: '.$adapter->name().': '.$worker['note'];
            }
        }

        foreach (AdapterRegistry::all() as $adapter) {
            if (! in_array($artifact->format, $adapter->formats(), true)) {
                continue;
            }
            foreach ($adapter->candidateTargets() as $candidate) {
                if ($candidate['id'] === $target) {
                    return 'Documented backend: '.$adapter->name().' ('.$candidate['backend_id'].', unverified). Owner setup: docs/analysis-spool-deployment.md.';
                }
            }
        }

        $native = in_array($target, Targets::nativeIds(), true)
            ? 'The bundled analyzer covers '.implode(', ', Targets::nativeIds()).' for open formats; '
            : '';

        return $native.'No documented backend covers this pair yet.';
    }
}
