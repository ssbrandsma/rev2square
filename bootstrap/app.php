<?php

use App\Support\TrustedHostPatterns;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Production runs behind TLS proxies (Caddy, or Virtualmin Apache)
        // on trusted networks, so forwarded proto/host headers must be
        // trusted for secure cookies and correct URL generation.
        // TRUSTED_PROXIES is a comma-separated list of IPs/CIDRs, the
        // PRIVATE_SUBNETS token, or "*" to trust the connecting proxy.
        // Laravel's direct peer is always the in-Compose nginx, which only
        // the edge proxy (or a host-local process, in the Virtualmin
        // loopback variant) can reach — see docs/DEPLOYMENT*.md.
        $proxies = array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('TRUSTED_PROXIES', '*'))
        )));

        $middleware->trustProxies(at: $proxies === ['*'] ? '*' : $proxies);

        // Reject unexpected Host headers (inactive in local/testing by
        // Laravel's own gate; enforced in production). TRUSTED_HOSTS pins
        // additional exact names; the APP_URL host and loopback entries
        // for container healthchecks are always included.
        $middleware->trustHosts(
            at: TrustedHostPatterns::fromEnv(env('TRUSTED_HOSTS'), env('APP_URL')),
            subdomains: false,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
