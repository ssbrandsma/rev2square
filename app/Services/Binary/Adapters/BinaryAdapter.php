<?php

namespace App\Services\Binary\Adapters;

use App\Models\BinaryAnalysis;

/**
 * Deterministic-analysis backend contract. Adapters never execute
 * uploaded content, never touch the network, and never run user
 * input as code — subprocess adapters use fixed paths plus an
 * allowlist of argument shapes (see GhidraAdapter).
 */
interface BinaryAdapter
{
    public function id(): string;

    public function name(): string;

    /** Null when the backend cannot report a version (usually absent). */
    public function version(): ?string;

    /**
     * Live probe (coordinator) or coordinator snapshot (everywhere
     * else). An optional 'pending' => true marks a claimed-but-
     * unanswered probe: still qualifying, never stored as broken.
     *
     * @return array{available: bool, reason: string, setup_doc: ?string, pending?: bool}
     */
    public function probe(): array;

    /** Formats this adapter can open. @return list<string> */
    public function formats(): array;

    /**
     * Exact supported target identifiers (see Targets). Adapters with
     * an unavailable backend return [] here and list unverified
     * candidates via candidateTargets() instead.
     *
     * @return list<string>
     */
    public function targets(): array;

    /**
     * Documented-but-unverified target candidates, shown only with an
     * explicit unverified note. @return list<array{id: string, backend_id: string, note: string}>
     */
    public function candidateTargets(): array;

    /**
     * Per-capability levels for one format+target pair.
     *
     * @return array<string, string> capability => Capability::*
     */
    public function capabilities(string $format, ?string $target): array;

    /**
     * Run deterministic analysis. $heartbeat is called between phases
     * and returns false when the run was cancelled.
     *
     * @param  array<string, mixed>  $snapshot  extraction snapshot
     * @param  array<string, mixed>  $config  resolved target configuration
     * @param  callable(): bool  $heartbeat
     * @return array<string, mixed> evidence document (JSON-serializable)
     *
     * @throws AnalysisFailedException permanent or environmental failure (carries error code)
     */
    public function analyze(BinaryAnalysis $analysis, string $bytes, array $snapshot, array $config, callable $heartbeat): array;
}
