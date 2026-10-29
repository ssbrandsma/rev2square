<?php

namespace App\Services\AI;

/**
 * Thrown when an AI request is refused before reaching the provider:
 * kill switch, suspension, AI restriction, or exhausted budget. Never
 * thrown for provider-side failures (those stay AIProviderException).
 */
class AiDeniedException extends AIProviderException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
