<?php

namespace Tests\Unit;

use App\Services\Revival\AnalysisPrompt;
use PHPUnit\Framework\TestCase;

class AnalysisPromptTest extends TestCase
{
    public function test_system_prompt_defines_unknown_citation_rule(): void
    {
        // Alignment contract with ReportSchema::validate(): an UNKNOWN
        // finding must cite the examined evidence; gaps with no evidence
        // at all belong in unknowns[], never in findings[].
        $prompt = AnalysisPrompt::system();

        $this->assertStringContainsString('UNKNOWN means the evidence you examined cannot answer', $prompt);
        $this->assertStringContainsString('record the gap in unknowns[] instead', $prompt);
    }
}
