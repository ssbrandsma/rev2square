<?php

namespace Tests\Unit;

use App\Services\Discussion\Markup;
use PHPUnit\Framework\TestCase;

class MarkupTest extends TestCase
{
    public function test_paragraphs_and_line_breaks(): void
    {
        $html = Markup::render("First line\nsecond line\n\nSecond paragraph.");

        $this->assertStringContainsString('<p>First line<br>', $html);
        $this->assertStringContainsString('<p>Second paragraph.</p>', $html);
    }

    public function test_links_are_autolinked_safely(): void
    {
        $html = Markup::render('See https://example.test/docs, it helps.');

        $this->assertStringContainsString('<a href="https://example.test/docs"', $html);
        $this->assertStringContainsString('rel="noopener nofollow"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        // Trailing punctuation stays outside the link.
        $this->assertStringContainsString('</a>,', $html);
    }

    public function test_code_blocks_and_inline_code(): void
    {
        $html = Markup::render("Run:\n```\nflash --port /dev/ttyUSB0\n```\nThen `reboot`.");

        $this->assertStringContainsString('<pre', $html);
        $this->assertStringContainsString('flash --port /dev/ttyUSB0', $html);
        $this->assertStringContainsString('<code class="rounded bg-zinc-100', $html);
        $this->assertStringContainsString('reboot', $html);
    }

    public function test_html_is_escaped_and_javascript_urls_are_not_linked(): void
    {
        $html = Markup::render('<script>alert(1)</script> javascript:alert(2)');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<a href="javascript:', $html);
    }

    public function test_excerpt_truncates(): void
    {
        $this->assertSame('short', Markup::excerpt('short'));
        $this->assertSame('ab…', Markup::excerpt('abcdef', 3));
    }
}
