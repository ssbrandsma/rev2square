<?php

namespace App\Services\Agent;

/**
 * Outcome of one bounded agent turn.
 */
final class AgentTurnResult
{
    /**
     * @param  list<array{marker: string, source_id: int, verified: bool}>  $citations
     * @param  list<string>  $unverifiedMarkers
     * @param  list<array{name: string, args: string, ok: bool, ms: int}>  $toolTrace
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $citations = [],
        public readonly array $unverifiedMarkers = [],
        public readonly array $toolTrace = [],
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly string $stoppedReason = 'complete',
        public readonly int $durationMs = 0,
        public readonly int $tokenEstimate = 0,
    ) {}

    public function stoppedEarly(): bool
    {
        return $this->stoppedReason !== 'complete';
    }
}
