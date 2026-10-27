<?php

namespace Tests\Unit;

use App\Support\TrustedHostPatterns;
use PHPUnit\Framework\TestCase;

class TrustedHostPatternsTest extends TestCase
{
    public function test_defaults_to_app_host_plus_loopback(): void
    {
        $patterns = TrustedHostPatterns::fromEnv(null, 'https://rev2square.example.com');

        $this->assertContains('^(.+\.)?rev2square\.example\.com$', $patterns);
        $this->assertContains('^127\.0\.0\.1$', $patterns);
        $this->assertContains('^localhost$', $patterns);
        $this->assertContains('^\:\:1$', $patterns);
        $this->assertCount(4, $patterns);
    }

    public function test_explicit_hosts_merge_with_app_host_and_loopback(): void
    {
        $patterns = TrustedHostPatterns::fromEnv(
            'staging.example.com, alias.example.org',
            'https://rev2square.example.com'
        );

        $this->assertContains('^staging\.example\.com$', $patterns);
        $this->assertContains('^alias\.example\.org$', $patterns);
        $this->assertContains('^(.+\.)?rev2square\.example\.com$', $patterns);
        $this->assertContains('^127\.0\.0\.1$', $patterns);
    }

    public function test_blank_entries_are_ignored(): void
    {
        $patterns = TrustedHostPatterns::fromEnv(' , staging.example.com,,', null);

        $this->assertContains('^staging\.example\.com$', $patterns);
        $this->assertNotContains('^$', $patterns);
    }

    public function test_missing_app_url_still_allows_loopback(): void
    {
        $patterns = TrustedHostPatterns::fromEnv(null, null);

        $this->assertContains('^127\.0\.0\.1$', $patterns);
        $this->assertContains('^localhost$', $patterns);
        $this->assertContains('^\:\:1$', $patterns);
        $this->assertCount(3, $patterns);
    }

    public function test_malformed_app_url_does_not_crash(): void
    {
        $patterns = TrustedHostPatterns::fromEnv(null, 'not a url at all :::');

        $this->assertContains('^localhost$', $patterns);
    }
}
