<?php

namespace Tests\Unit\Ops;

use PHPUnit\Framework\TestCase;

// REVERB_ALLOWED_ORIGINS parsing in config/reverb.php. Each case runs in a
// fresh PHP process: the config file evaluates env() at require time, so
// in-process mutation cannot re-exercise the parsing. No Laravel boot, no
// database; the subprocess only needs vendor/autoload.php for env().
class ReverbAllowedOriginsTest extends TestCase
{
    private function parsedOrigins(?string $envValue): array
    {
        $root = realpath(__DIR__.'/../../../');
        $this->assertNotFalse($root);

        $export = var_export($envValue, true);
        $code = <<<PHP
            require '{$root}/vendor/autoload.php';
            if ({$export} !== null) { \$_SERVER['REVERB_ALLOWED_ORIGINS'] = {$export}; }
            \$c = require '{$root}/config/reverb.php';
            echo json_encode(\$c['apps']['apps'][0]['allowed_origins']);
            PHP;

        $output = [];
        $exit = 0;
        exec('php -r '.escapeshellarg($code).' 2>&1', $output, $exit);

        $this->assertSame(0, $exit, 'config probe failed: '.implode("\n", $output));
        $decoded = json_decode(implode("\n", $output), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function test_unset_origins_default_to_wildcard(): void
    {
        $this->assertSame(['*'], $this->parsedOrigins(null));
    }

    public function test_single_hostname_is_pinned(): void
    {
        $this->assertSame(
            ['rev2square.example.com'],
            $this->parsedOrigins('rev2square.example.com')
        );
    }

    public function test_full_url_is_normalized_to_hostname(): void
    {
        // Reverb v1.12 compares parse_url(Origin, HOST) against the
        // entries, so full URLs must be reduced to hostnames (a literal
        // 'https://...' entry could never match and would lock everyone
        // out). Ports, paths, and case are normalized away the same way.
        $this->assertSame(
            ['rev2square.example.com'],
            $this->parsedOrigins('https://rev2square.example.com')
        );
        $this->assertSame(
            ['a.example.com', 'b.example.com'],
            $this->parsedOrigins('https://A.example.com:8443/x,  b.example.com ')
        );
    }

    public function test_wildcard_entries_survive_normalization(): void
    {
        $this->assertSame(['*'], $this->parsedOrigins('*'));
        $this->assertSame(
            ['*.example.com'],
            $this->parsedOrigins('https://*.example.com')
        );
    }

    public function test_empty_value_falls_back_to_wildcard(): void
    {
        $this->assertSame(['*'], $this->parsedOrigins(''));
    }

    public function test_unparseable_value_fails_closed(): void
    {
        // Present-but-invalid is misconfiguration, not "not configured":
        // deny all (loud 4009s) instead of silently opening to '*'.
        $this->assertSame([], $this->parsedOrigins('http:///no-host-here'));
        $this->assertSame(
            ['ok.example.com'],
            $this->parsedOrigins('http:///no-host-here, ok.example.com')
        );
    }
}
