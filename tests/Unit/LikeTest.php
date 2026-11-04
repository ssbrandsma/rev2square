<?php

namespace Tests\Unit;

use App\Support\Like;
use PHPUnit\Framework\TestCase;

class LikeTest extends TestCase
{
    public function test_escape_neutralizes_wildcards_and_backslash(): void
    {
        $this->assertSame('plain', Like::escape('plain'));
        $this->assertSame('100\\% \\_sure\\_', Like::escape('100% _sure_'));
        $this->assertSame('back\\\\slash', Like::escape('back\\slash'));
    }

    public function test_contains_wraps_escaped_needle(): void
    {
        $this->assertSame('%plain%', Like::contains('plain'));
        $this->assertSame('%100\\%%', Like::contains('100%'));
    }
}
