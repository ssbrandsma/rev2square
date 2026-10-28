<?php

namespace App\Services\AI;

class AIResponse
{
    public function __construct(
        public readonly string $content,
        public readonly string $model,
        public readonly ?string $requestId = null,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly int $durationMs = 0,
        public readonly ?string $finishReason = null,
        /** @var list<AIToolCall>|null Model-requested tool invocations. */
        public readonly ?array $toolCalls = null,
    ) {}

    public function totalTokens(): ?int
    {
        if ($this->inputTokens === null && $this->outputTokens === null) {
            return null;
        }

        return ($this->inputTokens ?? 0) + ($this->outputTokens ?? 0);
    }
}
