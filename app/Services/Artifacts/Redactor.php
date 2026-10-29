<?php

namespace App\Services\Artifacts;

/**
 * Best-effort secret redaction for model-facing evidence, previews,
 * logs and error reports.
 *
 * Covers recognizable patterns (auth headers, cookies, URL/query
 * credentials, private keys, bearer tokens, password/token
 * assignments). It explicitly does NOT detect every secret: unknown
 * formats and encrypted blobs pass through, which is why raw payloads
 * are never extracted and the disclosure screen says so.
 */
final class Redactor
{
    private const QUERY_KEYS = [
        'password', 'passwd', 'pwd', 'pass', 'token', 'api_key', 'apikey',
        'api-key', 'access_token', 'auth', 'auth_token', 'secret', 'secret_key',
        'session', 'sessionid', 'sid', 'private_key',
    ];

    public static function redactText(string $text): string
    {
        // Private key blocks first (multiline).
        $text = preg_replace(
            '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?-----END [A-Z0-9 ]*PRIVATE KEY-----/s',
            '[PRIVATE KEY REDACTED]',
            $text
        ) ?? $text;

        // Auth headers and cookies.
        $text = preg_replace(
            '/(?im)^((?:proxy-)?authorization\s*:\s*)\S.*$/m',
            '$1[REDACTED]',
            $text
        ) ?? $text;
        $text = preg_replace(
            '/(?im)^((?:set-)?cookie\s*:\s*).*$/m',
            '$1[REDACTED]',
            $text
        ) ?? $text;

        // Bearer tokens anywhere.
        $text = preg_replace(
            '/(?i)\bbearer\s+[A-Za-z0-9\-._~+\/=]{8,}/',
            'bearer [REDACTED]',
            $text
        ) ?? $text;

        // password/token-style assignments (configs, URLs, logs).
        $keys = implode('|', self::QUERY_KEYS);
        $text = preg_replace(
            '/(?i)\b('.$keys.')(\s*[:=]\s*)([^\s&;"\']+)/',
            '$1$2[REDACTED]',
            $text
        ) ?? $text;

        return $text;
    }

    public static function redactUrl(string $url): string
    {
        // Strip userinfo credentials: scheme://user:pass@host → [REDACTED]@host.
        $url = preg_replace('~^([a-z][a-z0-9+.-]*://)[^/@\s]+@~i', '$1[REDACTED]@', $url) ?? $url;

        $pos = strpos($url, '?');
        if ($pos === false) {
            return $url;
        }

        $base = substr($url, 0, $pos);
        $query = substr($url, $pos + 1);
        $parts = explode('&', $query);
        foreach ($parts as $i => $part) {
            $eq = strpos($part, '=');
            $key = strtolower($eq === false ? $part : substr($part, 0, $eq));
            if (in_array($key, self::QUERY_KEYS, true)) {
                $parts[$i] = ($eq === false ? $part : substr($part, 0, $eq)).'=[REDACTED]';
            }
        }

        return $base.'?'.implode('&', $parts);
    }

    /**
     * Keep only headers that are safe to show or transmit.
     *
     * @param  array<string, string>  $headers  lowercased names
     * @return array<string, string>
     */
    public static function filterHeaders(array $headers): array
    {
        $safe = ['host', 'content-type', 'content-length', 'server', 'location', 'nt', 'nts', 'usn', 'st', 'man', 'user-agent'];
        $out = [];
        foreach ($headers as $name => $value) {
            if (in_array(strtolower($name), $safe, true)) {
                $out[strtolower($name)] = substr($value, 0, 256);
            }
        }

        return $out;
    }

    public static function countSecrets(string $text): int
    {
        $count = 0;
        $count += preg_match_all('/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----/s', $text) ?: 0;
        $count += preg_match_all('/(?im)^(?:(?:proxy-)?authorization|(?:set-)?cookie)\s*:/m', $text) ?: 0;
        $count += preg_match_all('/(?i)\bbearer\s+[A-Za-z0-9\-._~+\/=]{8,}/', $text) ?: 0;
        $keys = implode('|', self::QUERY_KEYS);
        $count += preg_match_all('/(?i)\b(?:'.$keys.')\s*[:=]/', $text) ?: 0;

        return $count;
    }
}
