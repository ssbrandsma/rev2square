<?php

namespace Tests\Feature;

use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    public function test_session_expired_page_explains_recovery(): void
    {
        $html = view('errors.419')->render();

        $this->assertStringContainsString('session expired', strtolower($html));
        $this->assertStringContainsString('draft', strtolower($html));
        $this->assertStringContainsString(route('login'), $html);
    }
}
