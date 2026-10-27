<?php

namespace App\Services\GitHub;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Minimal allowlisted GitHub REST client (read-only, public repositories).
 *
 * - Only ever calls https://<configured host> with validated path segments.
 * - Never follows redirects; a 3xx response is an error.
 * - Never logs credentials or response bodies.
 */
class GitHubClient
{
    public function __construct(
        private readonly string $host,
        private readonly int $timeout,
        private readonly ?string $token,
        private readonly string $userAgent,
    ) {}

    public static function fromConfig(): self
    {
        $config = config('revival.github');

        return new self(
            $config['host'],
            (int) $config['timeout'],
            $config['token'] ?: null,
            $config['user_agent'],
        );
    }

    /** @return array<string, mixed> */
    public function getRepo(string $owner, string $name): array
    {
        $data = $this->request('GET', ['repos', $owner, $name]);

        if (($data['private'] ?? false) === true) {
            throw new RepositoryPrivateException('Private repositories are not supported.');
        }

        return $data;
    }

    public function getBranchSha(string $owner, string $name, string $branch): string
    {
        $this->assertSegment($branch);

        $data = $this->request('GET', ['repos', $owner, $name, 'git', 'ref', 'heads', $branch]);

        $sha = $data['object']['sha'] ?? null;

        if (! is_string($sha) || ! preg_match('/^[0-9a-f]{40}$/', $sha)) {
            throw new GitHubException('Could not resolve branch HEAD commit.');
        }

        return $sha;
    }

    /** @return array{sha: string, truncated: bool, tree: array<int, array<string, mixed>>} */
    public function getTree(string $owner, string $name, string $sha, bool $recursive = true): array
    {
        if (! preg_match('/^[0-9a-f]{40}$/', $sha)) {
            throw new GitHubException('Invalid commit SHA.');
        }

        $query = $recursive ? ['recursive' => '1'] : [];
        $data = $this->request('GET', ['repos', $owner, $name, 'git', 'trees', $sha], $query);

        return [
            'sha' => $data['sha'] ?? $sha,
            'truncated' => (bool) ($data['truncated'] ?? false),
            'tree' => $data['tree'] ?? [],
        ];
    }

    /** @return array{content: string, html_url: ?string, size: int} */
    public function getFileContent(string $owner, string $name, string $path, string $ref): array
    {
        // Raw segments; request() validates and encodes each one exactly once.
        $segments = array_merge(['repos', $owner, $name, 'contents'], explode('/', $path));

        $data = $this->request('GET', $segments, ['ref' => $ref]);

        if (($data['encoding'] ?? null) !== 'base64' || ! isset($data['content'])) {
            throw new GitHubException("Unexpected content response for {$path}.");
        }

        $content = base64_decode(str_replace(["\n", "\r", ' '], '', $data['content']), true);

        if ($content === false) {
            throw new GitHubException("Could not decode content for {$path}.");
        }

        return [
            'content' => $content,
            'html_url' => $data['html_url'] ?? null,
            'size' => (int) ($data['size'] ?? strlen($content)),
        ];
    }

    /**
     * @param  list<string>  $segments  Validated URL path segments.
     * @return array<string, mixed>
     */
    private function request(string $method, array $segments, array $query = []): array
    {
        foreach ($segments as $segment) {
            $this->assertSegment($segment);
        }

        $url = 'https://'.$this->host.'/'.implode('/', array_map(rawurlencode(...), $segments));

        try {
            $response = Http::withHeaders($this->headers())
                ->withOptions(['allow_redirects' => false])
                ->timeout($this->timeout)
                ->send($method, $url, ['query' => $query]);
        } catch (ConnectionException $e) {
            throw new GitHubTimeoutException('GitHub API request timed out.', 0, $e);
        }

        return $this->handleResponse($response, $url);
    }

    /** @return array<string, mixed> */
    private function handleResponse(Response $response, string $url): array
    {
        $status = $response->status();

        if ($status >= 300 && $status < 400) {
            throw new GitHubException('Redirect to untrusted destination rejected.');
        }

        if ($status === 404) {
            throw new RepositoryNotFoundException('Repository not found or not public.');
        }

        if ($status === 429 || ($status === 403 && $this->rateLimitRemaining($response) === 0)) {
            throw new RateLimitedException(
                'GitHub API rate limit exceeded. Try again later or configure a GITHUB_TOKEN.',
                $this->rateLimitReset($response)
            );
        }

        if (! $response->successful()) {
            throw new GitHubException("GitHub API request failed with status {$status}.");
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new GitHubException('GitHub API returned an unexpected response.');
        }

        return $data;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => $this->userAgent,
        ];

        if ($this->token !== null) {
            $headers['Authorization'] = 'Bearer '.$this->token;
        }

        return $headers;
    }

    private function rateLimitRemaining(Response $response): ?int
    {
        $value = $response->header('x-ratelimit-remaining');

        return is_numeric($value) ? (int) $value : null;
    }

    private function rateLimitReset(Response $response): ?int
    {
        $value = $response->header('x-ratelimit-reset');

        return is_numeric($value) ? (int) $value : null;
    }

    private function assertSegment(string $segment): void
    {
        if ($segment === '' || str_contains($segment, '/') || $segment === '.' || $segment === '..') {
            throw new GitHubException('Invalid request path segment.');
        }
    }
}
