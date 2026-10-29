<?php

namespace Tests\Unit;

use App\Services\Agent\AgentAnswer;
use PHPUnit\Framework\TestCase;

class AgentAnswerTest extends TestCase
{
    public function test_qa_answer_shape_renders_without_raw_markers(): void
    {
        // Shape of the observed 28 Oct 2026 answer: conclusion, OBSERVED /
        // INFERRED sections, bullet lists, citations. No formatting marker
        // may survive visibly.
        $answer = <<<'MD'
            **Conclusion:** The service talks to Bose cloud endpoints.

            ### OBSERVED

            * `soundtouch-service` posts presets to the cloud [S303].
            * Account handling lives in the service [S305].

            ### INFERRED

            1. Presets sync on startup.
            2. Failures fall back to local state.
            MD;

        $html = AgentAnswer::render($answer);

        $this->assertStringContainsString('<strong>Conclusion:</strong>', $html);
        $this->assertStringContainsString('<h4', $html);
        $this->assertStringContainsString('OBSERVED', $html);
        $this->assertStringContainsString('<ul', $html);
        $this->assertStringContainsString('<ol', $html);
        $this->assertStringContainsString('<li>', $html);
        $this->assertStringContainsString('<code', $html);
        $this->assertStringContainsString('[S303]', $html);
        $this->assertStringContainsString('[S305]', $html);

        $this->assertStringNotContainsString('**', $html);
        $this->assertStringNotContainsString('###', $html);
        $this->assertStringNotContainsString('* `soundtouch', $html);
    }

    public function test_heading_levels_map_to_page_hierarchy(): void
    {
        $html = AgentAnswer::render("## Section\n\n### Detail\n\n# Title");

        $this->assertStringContainsString('<h3', $html);
        $this->assertStringContainsString('<h4', $html);
        $this->assertStringNotContainsString('<h1', $html);
        $this->assertStringNotContainsString('<h2', $html);
    }

    public function test_emphasis_code_and_links_render(): void
    {
        $html = AgentAnswer::render('An *important* note with `code` and https://example.com/docs.');

        $this->assertStringContainsString('<em>important</em>', $html);
        $this->assertStringContainsString('<code', $html);
        $this->assertStringContainsString('<a href="https://example.com/docs"', $html);
    }

    public function test_fenced_code_is_never_interpreted(): void
    {
        $html = AgentAnswer::render("```go\n## not a heading [S1]\n**bold**\n```");

        $this->assertStringContainsString('<pre', $html);
        $this->assertStringNotContainsString('<h4', $html);
        $this->assertStringNotContainsString('<strong>', $html);
        $this->assertStringContainsString('## not a heading [S1]', $html);
    }

    public function test_quotes_and_rules_render(): void
    {
        $html = AgentAnswer::render("> quoted evidence\n\n---\n\nAfter.");

        $this->assertStringContainsString('<blockquote', $html);
        $this->assertStringContainsString('<hr', $html);
        $this->assertStringContainsString('<p>After.</p>', $html);
    }

    public function test_malicious_markup_is_inert(): void
    {
        $html = AgentAnswer::render("<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>\n\n[x](javascript:alert(1))");

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<a href="javascript:', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    public function test_snake_case_and_hash_uses_are_not_emphasis_or_headings(): void
    {
        $html = AgentAnswer::render("Use device_state here.\n\n#1 priority is safety.");

        $this->assertStringContainsString('device_state', $html);
        $this->assertStringNotContainsString('<em>', $html);
        $this->assertStringContainsString('#1 priority', $html);
        $this->assertStringNotContainsString('<h3', $html);
    }
}
