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

    public function test_single_origin_is_pinned(): void
    {
        $this->assertSame(
            ['https://rev2square.example.com'],
            $this->parsedOrigins('https://rev2square.example.com')
        );
    }

    public function test_comma_list_is_split_and_trimmed(): void
    {
        $this->assertSame(
            ['https://a.example.com', 'https://b.example.com'],
            $this->parsedOrigins('https://a.example.com,  https://b.example.com ,')
        );
    }

    public function test_empty_value_falls_back_to_wildcard(): void
    {
        $this->assertSame(['*'], $this->parsedOrigins(''));
    }
}
