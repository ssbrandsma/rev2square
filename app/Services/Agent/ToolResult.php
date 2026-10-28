<?php

namespace App\Services\Agent;

/**
 * Outcome of one tool invocation.
 *
 * @phpstan-type ToolData array<string, mixed>
 */
final class ToolResult
{
    /**
     * @param  array<string, mixed>  $data  JSON-serializable payload for the model.
     * @param  list<int>  $seenSourceIds  Evidence ids the model legitimately saw.
     */
    public function __construct(
        public readonly array $data,
        public readonly bool $ok = true,
        public readonly array $seenSourceIds = [],
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function ok(array $data, array $seenSourceIds = []): self
    {
        return new self($data, true, $seenSourceIds);
    }

    public static function error(string $code, string $message): self
    {
        return new self(['error' => $message, 'code' => $code], false);
    }
}
