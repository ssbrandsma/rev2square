<?php

namespace Tests\Unit;

use App\Services\Revival\ArchitecturePrompt;
use PHPUnit\Framework\TestCase;

class ArchitecturePromptTest extends TestCase
{
    public function test_system_prompt_defines_hash_binding_rule(): void
    {
        // Alignment contract with ArchitectureSchema::validate(): hashes
        // are never printed in the input, so the model must leave
        // content_hash empty (server-side binding) rather than invent one.
        $prompt = ArchitecturePrompt::system();

        $this->assertStringContainsString('otherwise leave it empty and it is bound server-side', $prompt);
        $this->assertStringContainsString('never invent a hash', $prompt);
        $this->assertStringNotContainsString('the content hash printed beside the source', $prompt);
    }
}
