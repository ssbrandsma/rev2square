<?php

namespace App\Services\GitHub;

class RateLimitedException extends GitHubException
{
    public function __construct(
        string $message = 'GitHub API rate limit exceeded.',
        public readonly ?int $resetsAt = null,
    ) {
        parent::__construct($message);
    }
}
