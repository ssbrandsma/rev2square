<?php

namespace App\Services\AI;

class AIRateLimitedException extends AIProviderException
{
    public function __construct(
        string $message = 'AI provider rate limit exceeded.',
        public readonly ?int $resetsAt = null,
    ) {
        parent::__construct($message);
    }
}
