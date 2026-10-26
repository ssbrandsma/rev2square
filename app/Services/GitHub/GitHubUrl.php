<?php

namespace App\Services\GitHub;

/**
 * Parses and canonicalizes public GitHub repository URLs.
 *
 * Accepted: https://github.com/owner/name with optional trailing slash,
 * `.git` suffix, or extra path segments (/tree/…, /blob/…, …).
 * Canonical identity is lowercase owner/name.
 */
class GitHubUrl
{
    private const OWNER_PATTERN = '/^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,37}[a-zA-Z0-9])?$/';

    private const NAME_PATTERN = '/^[a-zA-Z0-9._-]{1,100}$/';

    /**
     * @return array{owner: string, name: string}
     *
     * @throws InvalidGitHubUrlException
     */
    public static function parse(string $url): array
    {
        $parts = parse_url(trim($url));

        if ($parts === false
            || ! isset($parts['host'], $parts['path'])
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || strtolower($parts['host']) !== 'github.com'
        ) {
            throw new InvalidGitHubUrlException('URL must be a public https://github.com/owner/repository address.');
        }

        $segments = array_values(array_filter(explode('/', $parts['path'])));

        if (count($segments) < 2) {
            throw new InvalidGitHubUrlException('URL must include both owner and repository name.');
        }

        [$owner, $name] = $segments;
        $name = preg_replace('/\.git$/i', '', $name);

        if (! preg_match(self::OWNER_PATTERN, $owner) || ! preg_match(self::NAME_PATTERN, $name)) {
            throw new InvalidGitHubUrlException('Owner or repository name contains invalid characters.');
        }

        return ['owner' => strtolower($owner), 'name' => strtolower($name)];
    }

    public static function canonical(string $owner, string $name): string
    {
        return 'https://github.com/'.strtolower($owner).'/'.strtolower($name);
    }
}
