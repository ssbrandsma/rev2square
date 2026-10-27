<?php

namespace Tests\Unit;

use App\Support\TrustedHostPatterns;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

/**
 * The TrustHosts middleware is gated off in local/testing by Laravel
 * itself, so this test drives it with a production-like application stub
 * to prove the wiring rejects unexpected Host headers. 127.0.0.1 is always
 * allowed by the derivation, independent of environment.
 */
class TrustHostsMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // This pure-unit process never boots the app, so the bootstrap
        // trustHosts() call never ran: install the same state explicitly.
        TrustHosts::at(
            TrustedHostPatterns::fromEnv(null, 'https://rev2square.example.com'),
            false
        );
    }

    protected function tearDown(): void
    {
        // Both Laravel's and Symfony's trust state are statics: reset so
        // later tests run with the default unrestricted state.
        TrustHosts::flushState();
        Request::setTrustedHosts([]);

        Mockery::close();

        parent::tearDown();
    }

    private function productionMiddleware(): TrustHosts
    {
        $app = Mockery::mock(Application::class);
        $app->shouldReceive('environment')->with('local')->andReturn(false);
        $app->shouldReceive('runningUnitTests')->andReturn(false);

        return new TrustHosts($app);
    }

    public function test_unexpected_host_is_rejected(): void
    {
        $this->expectException(SuspiciousOperationException::class);

        $this->productionMiddleware()->handle(
            Request::create('http://evil.example.com/'),
            fn (Request $request) => $request->getHost()
        );
    }

    public function test_loopback_host_passes(): void
    {
        $host = $this->productionMiddleware()->handle(
            Request::create('http://127.0.0.1/up'),
            fn (Request $request) => $request->getHost()
        );

        $this->assertSame('127.0.0.1', $host);
    }
}
