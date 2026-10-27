<?php

namespace App\Services\AI;

interface AIProviderInterface
{
    public function name(): string;

    /** @throws AIProviderException */
    public function complete(AIRequest $request): AIResponse;
}
