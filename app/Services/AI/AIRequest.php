<?php

namespace App\Services\AI;

class AIRequest
{
    public function __construct(
        public readonly string $system,
        public readonly string $user,
        /** @var array<string, mixed>|null JSON Schema for constrained decoding */
        public readonly ?array $jsonSchema = null,
        public readonly ?int $maxCompletionTokens = null,
    ) {}
}
