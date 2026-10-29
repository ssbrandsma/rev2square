<?php

namespace Tests\Unit\Media;

use App\Services\Media\StoryDocument;
use App\Services\Media\StoryRenderer;
use PHPUnit\Framework\TestCase;

class StoryRenderingTest extends TestCase
{
    public function test_inline_syntax_renders_and_escapes(): void
    {
        $html = StoryRenderer::inline('A **bold** <tag> and *em* plus `code`.');

        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('&lt;tag&gt;', $html);
        $this->assertStringContainsString('<em>em</em>', $html);
        $this->assertStringContainsString('<code>code</code>', $html);
    }

    public function test_only_safe_links_render(): void
    {
        $this->assertStringContainsString(
            '<a href="https://example.com/x"',
            StoryRenderer::inline('[docs](https://example.com/x)')
        );
        $this->assertStringNotContainsString('<a href', StoryRenderer::inline('[x](javascript:alert(1))'));
        $this->assertStringNotContainsString('<a href', StoryRenderer::inline('[x](https://user:pass@example.com/)'));
        $this->assertStringNotContainsString('<a href', StoryRenderer::inline('[x](ftp://example.com/f)'));
        $this->assertTrue(StoryRenderer::safeUrl('http://example.com/'));
        $this->assertFalse(StoryRenderer::safeUrl('data:text/html,<script>alert(1)</script>'));
    }

    public function test_unclosed_markers_stay_literal(): void
    {
        $html = StoryRenderer::inline('**oops and still literal');

        $this->assertStringNotContainsString('<strong>', $html);
        $this->assertStringNotContainsString('<em>', $html);
        $this->assertStringContainsString('**oops', $html);

        $single = StoryRenderer::inline('*oops still literal');
        $this->assertStringNotContainsString('<em>', $single);
    }

    public function test_plaintext_extraction_skips_images(): void
    {
        $text = StoryDocument::toPlainText(['version' => 1, 'blocks' => [
            ['type' => 'paragraph', 'text' => 'Hello'],
            ['type' => 'image', 'media_id' => 9, 'layout' => 'normal'],
            ['type' => 'list', 'ordered' => false, 'items' => ['a', 'b']],
        ]]);

        $this->assertStringContainsString('Hello', $text);
        $this->assertStringContainsString('- a', $text);
        $this->assertStringNotContainsString('9', $text);
    }
}
