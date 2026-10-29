<?php

namespace Tests\Unit\Artifacts;

use App\Services\Artifacts\Redactor;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    public function test_auth_headers_and_cookies_are_redacted(): void
    {
        $in = "Authorization: Bearer abcdefgh12345678\nCookie: session=xyz\nX-Ok: keep";

        $out = Redactor::redactText($in);

        $this->assertStringNotContainsString('abcdefgh12345678', $out);
        $this->assertStringNotContainsString('session=xyz', $out);
        $this->assertStringContainsString('Authorization: [REDACTED]', $out);
        $this->assertStringContainsString('Cookie: [REDACTED]', $out);
        $this->assertStringContainsString('X-Ok: keep', $out);
    }

    public function test_url_credentials_are_redacted(): void
    {
        $this->assertSame(
            'https://[REDACTED]@host.example/x?token=[REDACTED]&page=2',
            Redactor::redactUrl('https://user:s3cret@host.example/x?token=s3cret&page=2')
        );
        $this->assertSame(
            '/api/data?password=[REDACTED]',
            Redactor::redactUrl('/api/data?password=hunter2')
        );
    }

    public function test_private_keys_and_assignments_are_redacted(): void
    {
        $in = "-----BEGIN EC PRIVATE KEY-----\nAAAA\n-----END EC PRIVATE KEY-----\napi_key = SYNTHETIC-1";

        $out = Redactor::redactText($in);

        $this->assertStringNotContainsString('SYNTHETIC-1', $out);
        $this->assertStringContainsString('[PRIVATE KEY REDACTED]', $out);
        $this->assertStringContainsString('api_key = [REDACTED]', $out);
        $this->assertSame(2, Redactor::countSecrets($in));
    }

    public function test_safe_headers_survive_filtering(): void
    {
        $out = Redactor::filterHeaders([
            'host' => 'example.com',
            'cookie' => 'x=1',
            'content-length' => '42',
        ]);

        $this->assertSame(['host' => 'example.com', 'content-length' => '42'], $out);
    }
}
