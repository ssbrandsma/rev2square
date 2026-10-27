<?php

namespace App\Support;

/**
 * Derive Symfony trusted-host regexes from operator configuration.
 *
 * The application sits behind reverse proxies (Caddy or Virtualmin Apache
 * -> Docker nginx -> php-fpm), so the Host header arriving at Laravel was
 * forwarded, not observed. Accepting arbitrary Host values would enable
 * Host-header attacks (poisoned password-reset links, cache poisoning).
 * These patterns restrict acceptable hosts to the public application host
 * (plus its subdomains, mirroring Laravel's default), any explicitly
 * pinned names, and loopback names the container healthchecks depend on.
 */
class TrustedHostPatterns
{
    /**
     * @param  array<int, string>  $hosts  plain hostnames, exact match
     * @param  string|null  $appUrlHost  public host, subdomain-tolerant
     * @return array<int, string> Symfony setTrustedHosts() regexes
     */
    public static function fromHosts(array $hosts, ?string $appUrlHost = null): array
    {
        $patterns = [];

        foreach ($hosts as $host) {
            $host = trim((string) $host);

            if ($host !== '') {
                $patterns[] = '^'.preg_quote($host, '/').'$';
            }
        }

        $appUrlHost = $appUrlHost !== null ? trim($appUrlHost) : '';

        if ($appUrlHost !== '') {
            $patterns[] = '^(.+\.)?'.preg_quote($appUrlHost, '/').'$';
        }

        // Loopback is always allowed: the nginx healthcheck and local
        // operator curls reach the app via 127.0.0.1/localhost.
        foreach (['127.0.0.1', 'localhost', '::1'] as $loopback) {
            $patterns[] = '^'.preg_quote($loopback, '/').'$';
        }

        return array_values(array_unique($patterns));
    }

    /**
     * @param  string|null  $trustedHostsEnv  comma-separated TRUSTED_HOSTS value
     * @param  string|null  $appUrl  APP_URL value
     * @return array<int, string>
     */
    public static function fromEnv(?string $trustedHostsEnv, ?string $appUrl): array
    {
        $hosts = $trustedHostsEnv !== null && trim($trustedHostsEnv) !== ''
            ? explode(',', $trustedHostsEnv)
            : [];

        $appHost = null;

        if ($appUrl !== null && trim($appUrl) !== '') {
            $parsed = parse_url(trim($appUrl), PHP_URL_HOST);
            $appHost = is_string($parsed) && $parsed !== '' ? $parsed : null;
        }

        return self::fromHosts($hosts, $appHost);
    }
}
