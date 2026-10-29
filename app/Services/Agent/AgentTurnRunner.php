<?php

namespace App\Services\Agent;

use App\Services\AI\AIProviderException;
use App\Services\AI\AIProviderInterface;
use App\Services\AI\AIRequest;

/**
 * One bounded model turn: prompt -> [tool calls] -> cited answer.
 *
 * All limits are enforced here, in application code: at most N tool
 * calls per turn, at most M identical calls, a wall-clock cap, and a
 * token budget over the composed messages. Unknown tools, malformed
 * arguments, and provider failures end the turn gracefully with the
 * partial investigation trace preserved.
 */
class AgentTurnRunner
{
    /**
     * @param  \Closure(string, ?array): ToolResult  $executor  Validated tool dispatch.
     * @param  list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>  $toolDefinitions
     * @param  list<string>  $allowedTools
     * @param  array{user_id?: ?int, project_id?: ?int, feature?: string, operation?: string}|null  $usage  Metering attribution for every provider attempt this turn.
     */
    public function __construct(
        private readonly AIProviderInterface $provider,
        private readonly \Closure $executor,
        private readonly array $toolDefinitions,
        private readonly array $allowedTools,
        private readonly int $maxToolCalls = 6,
        private readonly int $maxIdenticalCalls = 2,
        private readonly int $turnSeconds = 60,
        private readonly int $tokenBudget = 30000,
        private readonly int $maxProviderCalls = 8,
        private readonly int $maxSearchesBeforeRead = 2,
        private readonly ?array $usage = null,
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $history  Prior turns, oldest first.
     */
    public function run(string $system, string $question, array $history, string $correlationId): AgentTurnResult
    {
        $started = microtime(true);
        $messages = [['role' => 'system', 'content' => $system]];

        foreach (array_slice($history, -6) as $turn) {
            if (($turn['role'] ?? null) === 'user' || ($turn['role'] ?? null) === 'assistant') {
                $messages[] = ['role' => $turn['role'], 'content' => substr((string) ($turn['content'] ?? ''), 0, 2000)];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $question];

        $toolCalls = 0;
        $providerCalls = 0;
        $signatures = [];
        $seenSources = [];
        $seenArtifacts = [];
        $searchesSinceRead = 0;
        $foundUnread = [];
        $readIds = [];
        $trace = [];
        $inputTokens = 0;
        $outputTokens = 0;
        $sawUsage = false;
        $lastEstimate = $this->estimateTokens($messages);

        while (true) {
            if ($this->elapsed($started) >= $this->turnSeconds) {
                return $this->partial($seenSources, $seenArtifacts, $trace, $inputTokens, $outputTokens, $sawUsage, $started, $lastEstimate, 'time_budget',
                    'I ran out of time while investigating. What I found so far is listed below — ask me to continue and I will pick up from there.');
            }

            $lastEstimate = $this->estimateTokens($messages);

            if ($lastEstimate > $this->tokenBudget) {
                return $this->partial($seenSources, $seenArtifacts, $trace, $inputTokens, $outputTokens, $sawUsage, $started, $lastEstimate, 'token_budget',
                    'The investigation grew too large for one turn. Narrow the question or ask about one file at a time.');
            }

            if ($providerCalls >= $this->maxProviderCalls) {
                return $this->finalTextAttempt($messages, $seenArtifacts, 'tool_budget', $seenSources, $trace, $inputTokens, $outputTokens, $sawUsage, $started, $lastEstimate, $correlationId);
            }

            $providerCalls++;

            try {
                $response = $this->provider->complete(new AIRequest(
                    system: '',
                    user: $question,
                    correlationId: $correlationId,
                    messages: $messages,
                    tools: $this->toolDefinitions,
                    usage: $this->usage,
                ));
            } catch (AIProviderException $e) {
                return $this->partial($seenSources, $seenArtifacts, $trace, $inputTokens, $outputTokens, $sawUsage, $started, $lastEstimate, 'provider_error',
                    'The model request failed ('.substr($e->getMessage(), 0, 200).'). Please try again.');
            }

            if ($response->inputTokens !== null || $response->outputTokens !== null) {
                $sawUsage = true;
                $inputTokens += $response->inputTokens ?? 0;
                $outputTokens += $response->outputTokens ?? 0;
            }

            $calls = $response->toolCalls ?? [];

            if ($calls === []) {
                return $this->finish(
                    trim($response->content) !== '' ? $response->content : 'The model returned an empty answer. Please rephrase the question.',
                    trim($response->content) !== '' ? 'complete' : 'empty_response',
                    $seenSources, $seenArtifacts, $trace, $inputTokens, $outputTokens, $sawUsage, $started, $lastEstimate
                );
            }

            if ($toolCalls >= $this->maxToolCalls) {
                return $this->finalTextAttempt($messages, $seenArtifacts, 'tool_budget', $seenSources, $trace, $inputTokens, $outputTokens, $sawUsage, $started, $lastEstimate, $correlationId);
            }

            // Execute first, then append the assistant turn followed by its
            // tool results, so the history is always well-formed.
            $executed = [];

            foreach ($calls as $call) {
                if ($toolCalls >= $this->maxToolCalls) {
                    break;
                }

                if ($this->elapsed($started) >= $this->turnSeconds) {
                    return $this->partial($seenSources, $seenArtifacts, $trace, $inputTokens, $outputTokens, $sawUsage, $started, $lastEstimate, 'time_budget',
                        'I ran out of time while investigating. What I found so far is listed below — ask me to continue and I will pick up from there.');
                }

                $toolCalls++;
                $callStarted = microtime(true);

                $result = $this->dispatch($call->name, $call->arguments(), $signatures, $searchesSinceRead, $foundUnread, $toolCalls);

                if ($call->name === EvidenceTools::SEARCH) {
                    $searchesSinceRead++;
                    foreach ($result->seenSourceIds as $id) {
                        $foundUnread[$id] = true;
                    }
                }

                if ($call->name === EvidenceTools::READ && $result->ok) {
                    $searchesSinceRead = 0;
                    $readId = $call->arguments()['source_id'] ?? null;
                    if (is_numeric($readId)) {
                        $readIds[(int) $readId] = true;
                        unset($foundUnread[(int) $readId]);
                    }
                }

                // Artifact searches consume planning budget like evidence
                // searches; bounded reads and list/compare progress reset it.
                if (in_array($call->name, [ArtifactTools::SEARCH_ELF, ArtifactTools::SEARCH_CAPTURE], true)) {
                    $searchesSinceRead++;
                }

                if (in_array($call->name, [ArtifactTools::READ_ELF, ArtifactTools::READ_PACKET, ArtifactTools::LIST_FLOWS, ArtifactTools::COMPARE, ArtifactTools::LIST], true) && $result->ok) {
                    $searchesSinceRead = 0;
                }

                // Binary evidence reads are progress; inspection and
                // status polls are not.
                if (in_array($call->name, [BinaryTools::DISASM, BinaryTools::FUNCTIONS, BinaryTools::XREFS, BinaryTools::CALLGRAPH], true) && $result->ok) {
                    $searchesSinceRead = 0;
                }

                // A successfully examined snapshot is progress; a missing
                // one is not — it must not launder a search loop.
                if ($call->name === EvidenceTools::ARCHITECTURE && $result->ok) {
                    if (($result->data['available'] ?? false) === true) {
                        $searchesSinceRead = 0;
                    } else {
                        $searchesSinceRead++;
                    }
                }

                foreach ($result->seenSourceIds as $id) {
                    $seenSources[$id] = true;
                }

                foreach ($result->seenArtifactIds as $id) {
                    $seenArtifacts[$id] = true;
                }

                $trace[] = [
                    'name' => $call->name,
                    'args' => substr($call->argumentsRaw, 0, 300),
                    'ok' => $result->ok,
                    'ms' => (int) round((microtime(true) - $callStarted) * 1000),
                ];

                $executed[] = [$call, $result];
            }

            $assistantMessage = ['role' => 'assistant', 'content' => $response->content, 'tool_calls' => []];
            foreach ($executed as [$call]) {
                $assistantMessage['tool_calls'][] = [
                    'id' => $call->id,
                    'type' => 'function',
                    'function' => ['name' => $call->name, 'arguments' => $call->argumentsRaw],
                ];
            }
            $messages[] = $assistantMessage;

            foreach ($executed as [$call, $result]) {
                $content = $result->data;

                if ($call->name === EvidenceTools::SEARCH && $result->ok) {
                    $content = self::markReadResults($content, $readIds);
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call->id,
                    'name' => $call->name,
                    'content' => substr((string) json_encode($content), 0, 12000),
                ];
            }
        }
    }

    /**
     * @param  array<string, int>  $signatures
     * @param  array<int, bool>  $foundUnread
     */
    private function dispatch(string $name, ?array $args, array &$signatures, int $searchesSinceRead, array $foundUnread, int $toolCalls): ToolResult
    {
        if (! in_array($name, $this->allowedTools, true)) {
            return ToolResult::error('unknown_tool', "Unknown tool '{$name}'.");
        }

        if ($args === null) {
            return ToolResult::error('invalid_arguments', 'Tool arguments were not valid JSON.');
        }

        if ($name === EvidenceTools::SEARCH
            && $searchesSinceRead >= $this->maxSearchesBeforeRead
            && $foundUnread !== []) {
            $ids = array_map(fn ($id): string => "[S{$id}]", array_slice(array_keys($foundUnread), 0, 5));
            $remaining = max(0, $this->maxToolCalls - $toolCalls);

            return ToolResult::error(
                'read_before_searching',
                'Earlier searches already found unread sources ('.implode(', ', $ids).'). '
                .'Use read_evidence on the most promising one before searching again — '
                ."this blocked call still used budget ({$remaining} of {$this->maxToolCalls} tool calls remain)."
            );
        }

        $signature = $name.'|'.json_encode(self::normalize($args));
        $signatures[$signature] = ($signatures[$signature] ?? 0) + 1;

        if ($signatures[$signature] > $this->maxIdenticalCalls) {
            return ToolResult::error(
                'duplicate_call',
                "This exact call already ran {$this->maxIdenticalCalls} times this turn. Use its earlier result or try different arguments."
            );
        }

        try {
            return ($this->executor)($name, $args);
        } catch (\Throwable) {
            return ToolResult::error('tool_failed', "Tool '{$name}' failed. Continue with the evidence gathered so far.");
        }
    }

    /**
     * Flag results the turn already opened so the model picks unread
     * sources instead of re-reading the same file.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, bool>  $readIds
     * @return array<string, mixed>
     */
    private static function markReadResults(array $data, array $readIds): array
    {
        if (! isset($data['results']) || ! is_array($data['results'])) {
            return $data;
        }

        foreach ($data['results'] as $i => $row) {
            $id = is_array($row) ? ($row['source_id'] ?? null) : null;
            $data['results'][$i]['already_read'] = is_int($id) && isset($readIds[$id]);
        }

        return $data;
    }

    /** @param  array<string, mixed>  $args */
    private static function normalize(array $args): mixed
    {
        ksort($args);
        foreach ($args as $key => $value) {
            if (is_array($value)) {
                $args[$key] = self::normalize($value);
            }
        }

        return $args;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  array<int, bool>  $seenSources
     * @param  list<array{name: string, args: string, ok: bool, ms: int}>  $trace
     */
    private function finalTextAttempt(array $messages, array $seenArtifacts, string $reason, array $seenSources, array $trace, int $in, int $out, bool $sawUsage, float $started, int $estimate, string $correlationId): AgentTurnResult
    {
        $messages[] = [
            'role' => 'user',
            'content' => 'The tool budget for this turn is spent. Answer from the evidence gathered so far, citing the [S{id}] and [A{id}] sources you saw. If the evidence is insufficient, say what is missing.',
        ];

        try {
            $response = $this->provider->complete(new AIRequest(
                system: '',
                user: '',
                correlationId: $correlationId,
                messages: $messages,
                usage: $this->usage,
            ));
        } catch (AIProviderException) {
            return $this->partial($seenSources, $seenArtifacts, $trace, $in, $out, $sawUsage, $started, $estimate, $reason,
                'The tool budget for this turn is spent and the summary request failed. The investigation trace below shows what was examined.');
        }

        if ($response->inputTokens !== null || $response->outputTokens !== null) {
            $sawUsage = true;
            $in += $response->inputTokens ?? 0;
            $out += $response->outputTokens ?? 0;
        }

        return $this->finish(
            trim($response->content) !== ''
                ? $response->content."\n\n(Note: the tool budget for this turn was spent; this answer covers the evidence gathered so far.)"
                : 'The tool budget for this turn is spent. Ask a narrower follow-up to continue.',
            $reason,
            $seenSources, $seenArtifacts, $trace, $in, $out, $sawUsage, $started, $estimate
        );
    }

    /**
     * @param  array<int, bool>  $seenSources
     * @param  list<array{name: string, args: string, ok: bool, ms: int}>  $trace
     */
    private function partial(array $seenSources, array $seenArtifacts, array $trace, int $in, int $out, bool $sawUsage, float $started, int $estimate, string $reason, string $note): AgentTurnResult
    {
        return $this->finish($note, $reason, $seenSources, $seenArtifacts, $trace, $in, $out, $sawUsage, $started, $estimate);
    }

    /**
     * @param  array<int, bool>  $seenSources
     * @param  list<array{name: string, args: string, ok: bool, ms: int}>  $trace
     */
    private function finish(string $answer, string $reason, array $seenSources, array $seenArtifacts, array $trace, int $in, int $out, bool $sawUsage, float $started, int $estimate): AgentTurnResult
    {
        preg_match_all('/\[S(\d+)\]/', $answer, $m);
        $citations = [];
        $unverified = [];

        foreach (array_unique($m[1]) as $raw) {
            $id = (int) $raw;
            $verified = isset($seenSources[$id]);
            $citations[] = ['marker' => "[S{$id}]", 'source_id' => $id, 'verified' => $verified];
            if (! $verified) {
                $unverified[] = "[S{$id}]";
            }
        }

        preg_match_all('/\[A(\d+)\]/', $answer, $am);
        foreach (array_unique($am[1]) as $raw) {
            $id = (int) $raw;
            $verified = isset($seenArtifacts[$id]);
            $citations[] = ['marker' => "[A{$id}]", 'artifact_id' => $id, 'verified' => $verified];
            if (! $verified) {
                $unverified[] = "[A{$id}]";
            }
        }

        return new AgentTurnResult(
            answer: $answer,
            citations: $citations,
            unverifiedMarkers: $unverified,
            toolTrace: $trace,
            inputTokens: $sawUsage ? $in : null,
            outputTokens: $sawUsage ? $out : null,
            stoppedReason: $reason,
            durationMs: (int) round(($this->elapsed($started)) * 1000),
            tokenEstimate: $estimate,
        );
    }

    /** @param  list<array<string, mixed>>  $messages */
    private function estimateTokens(array $messages): int
    {
        $chars = strlen((string) json_encode($messages)) + strlen((string) json_encode($this->toolDefinitions));

        return (int) ceil($chars / 4);
    }

    private function elapsed(float $started): float
    {
        return microtime(true) - $started;
    }
}
