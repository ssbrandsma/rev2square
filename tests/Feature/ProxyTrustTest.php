<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Forwarded-header behavior behind the TLS proxies (Caddy in
 * compose.prod.yaml, Virtualmin Apache in compose.virtualmin.yaml,
 * each in front of Docker nginx -> php-fpm). TrustProxies is active in
 * every environment, so these run as ordinary feature tests.
 */
class ProxyTrustTest extends TestCase
{
    public function test_forwarded_proto_and_host_are_honored(): void
    {
        $response = $this->call('GET', '/', [], [], [], [
            'REMOTE_ADDR' => '172.21.0.5',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'rev2square.example.com',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        ]);

        $response->assertOk();
        $this->assertTrue(request()->secure());
        $this->assertSame('rev2square.example.com', request()->getHost());
        $this->assertSame('203.0.113.7', request()->ip());
        $this->assertSame('https://rev2square.example.com/projects', URL::to('/projects'));
    }

    public function test_plain_http_without_forwarding_is_not_secure(): void
    {
        $this->call('GET', '/up');

        $this->assertFalse(request()->secure());
    }
}
