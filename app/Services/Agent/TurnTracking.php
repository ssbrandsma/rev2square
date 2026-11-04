<?php

namespace App\Services\Agent;

/**
 * Mutable per-turn tracking state for AgentTurnRunner. Carries the
 * provider/tool counters, progress bookkeeping, and usage totals
 * between loop steps so run() stays readable.
 *
 * @internal
 */
class TurnTracking
{
    public int $toolCalls = 0;

    public int $providerCalls = 0;

    /** @var array<string, int> */
    public array $signatures = [];

    /** @var array<int, bool> */
    public array $seenSources = [];

    /** @var array<int, bool> */
    public array $seenArtifacts = [];

    /** @var array<string, bool> pinned "id@rev" doc revisions seen */
    public array $seenDocs = [];

    public int $searchesSinceRead = 0;

    /** @var array<int, bool> */
    public array $foundUnread = [];

    /** @var array<int, bool> */
    public array $readIds = [];

    /** @var list<array<string, mixed>> */
    public array $trace = [];

    public int $inputTokens = 0;

    public int $outputTokens = 0;

    public bool $sawUsage = false;

    public int $lastEstimate = 0;
}
