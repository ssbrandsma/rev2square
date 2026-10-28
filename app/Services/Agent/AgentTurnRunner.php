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
        $trace = [];
        $inputTokens = 0;
        $outputTokens = 0;
        $sawUsage = false;
        $lastEstimate = $this->estimateTokens($messages);

        while (true) {
            if ($this->elapsed($started) >= $this->turnSeconds) {
                return $this->partial($seenSources, $trace, $inputTokens, $outputTokens, $sawUsage, $started, $lastEstimate, 'time_budget',
                    'I ran out of time while investigating. What I found so far is listed below — ask me to continue and I will pick up from there.');
            }

            $lastEstimate = $this->estimateTokens($messages);

            if ($lastEstimate > $this->tokenBudget) {
                return $this->partial($seenSources, $trace, $inputTokens, $outputTokens, $sawUsage, $started, $lastEstimate, 'token_budget',
                    'The investigation grew too large for one turn. Narrow the question or ask about one file at a time.');
            }

            if ($providerCalls >= $this->maxProviderCalls) {
                return $this->finalTextAttempt($messages, 'tool_budget', $seenSources, $trace, $inputTokens, $outputTokens, $sawUsage, $started, $lastEstimate, $correlationId);
            }

            $providerCalls++;

            try {
                $response = $this->provider->complete(new AIRequest(
                    system: '',
                    user: $question,
                    correlationId: $correlationId,
                    messages: $messages,
                    tools: $this->toolDefinitions,
                ));
            } catch (AIProviderException $e) {
                return $this->partial($seenSources, $trace, $inputTokens, $outputTokens, $sawUsage, $started, $lastEstimate, 'provider_error',
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
                    $seenSources, $trace, $inputTokens, $outputTokens, $sawUsage, $started, $lastEstimate
                );
            }

            if ($toolCalls >= $this->maxToolCalls) {
                return $this->finalTextAttempt($messages, 'tool_budget', $seenSources, $trace, $inputTokens, $outputTokens, $sawUsage, $started, $lastEstimate, $correlationId);
            }

            // Execute first, then append the assistant turn followed by its
            // tool results, so the history is always well-formed.
            $executed = [];

            foreach ($calls as $call) {
                if ($toolCalls >= $this->maxToolCalls) {
                    break;
                }

                if ($this->elapsed($started) >= $this->turnSeconds) {
                    return $this->partial($seenSources, $trace, $inputTokens, $outputTokens, $sawUsage, $started, $lastEstimate, 'time_budget',
                        'I ran out of time while investigating. What I found so far is listed below — ask me to continue and I will pick up from there.');
                }

                $toolCalls++;
                $callStarted = microtime(true);

                $result = $this->dispatch($call->name, $call->arguments(), $signatures);
                foreach ($result->seenSourceIds as $id) {
                    $seenSources[$id] = true;
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
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call->id,
                    'name' => $call->name,
                    'content' => substr((string) json_encode($result->data), 0, 12000),
                ];
            }
        }
    }

    private function dispatch(string $name, ?array $args, array &$signatures): ToolResult
    {
        if (! in_array($name, $this->allowedTools, true)) {
            return ToolResult::error('unknown_tool', "Unknown tool '{$name}'.");
        }

        if ($args === null) {
            return ToolResult::error('invalid_arguments', 'Tool arguments were not valid JSON.');
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
    private function finalTextAttempt(array $messages, string $reason, array $seenSources, array $trace, int $in, int $out, bool $sawUsage, float $started, int $estimate, string $correlationId): AgentTurnResult
    {
        $messages[] = [
            'role' => 'user',
            'content' => 'The tool budget for this turn is spent. Answer from the evidence gathered so far, citing the [S{id}] sources you saw. If the evidence is insufficient, say what is missing.',
        ];

        try {
            $response = $this->provider->complete(new AIRequest(
                system: '',
                user: '',
                correlationId: $correlationId,
                messages: $messages,
            ));
        } catch (AIProviderException) {
            return $this->partial($seenSources, $trace, $in, $out, $sawUsage, $started, $estimate, $reason,
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
            $seenSources, $trace, $in, $out, $sawUsage, $started, $estimate
        );
    }

    /**
     * @param  array<int, bool>  $seenSources
     * @param  list<array{name: string, args: string, ok: bool, ms: int}>  $trace
     */
    private function partial(array $seenSources, array $trace, int $in, int $out, bool $sawUsage, float $started, int $estimate, string $reason, string $note): AgentTurnResult
    {
        return $this->finish($note, $reason, $seenSources, $trace, $in, $out, $sawUsage, $started, $estimate);
    }

    /**
     * @param  array<int, bool>  $seenSources
     * @param  list<array{name: string, args: string, ok: bool, ms: int}>  $trace
     */
    private function finish(string $answer, string $reason, array $seenSources, array $trace, int $in, int $out, bool $sawUsage, float $started, int $estimate): AgentTurnResult
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
