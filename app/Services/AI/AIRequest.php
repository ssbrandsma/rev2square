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
        /** Safe correlation identifier for log entries (e.g. analysis-run-123). */
        public readonly ?string $correlationId = null,
        /**
         * Full message history for multi-turn/tool requests. When set, it
         * replaces the system/user pair in the provider payload.
         *
         * @var list<array{role: string, content?: string|null, tool_calls?: mixed, tool_call_id?: string, name?: string}|null>
         */
        public readonly ?array $messages = null,
        /**
         * Tool definitions for native tool calling (Chat Completions `tools`).
         *
         * @var list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}|null>
         */
        public readonly ?array $tools = null,
        /**
         * Usage attribution, consumed by the metering decorator only.
         *
         * @var array{user_id?: ?int, project_id?: ?int, feature?: string, operation?: string}|null
         */
        public readonly ?array $usage = null,
    ) {}
}
