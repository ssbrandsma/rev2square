<?php

namespace App\Services\AI;

/**
 * One model-requested tool invocation (Chat Completions tool_calls entry).
 *
 * Arguments stay raw until the turn runner validates them against the
 * tool's allowlist; malformed JSON decodes to null and is rejected.
 */
class AIToolCall
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $argumentsRaw,
    ) {}

    /** @return array<string, mixed>|null */
    public function arguments(): ?array
    {
        try {
            $decoded = json_decode($this->argumentsRaw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
