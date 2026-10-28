<?php

namespace App\Services\GitHub;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Downloads one official GitHub source archive per immutable revision and
 * caches it on the local (private) disk.
 *
 * - Source URL pattern: https://github.com/{owner}/{repo}/archive/{sha}.zip
 * - Redirects are followed manually, at most 3 hops, only onto the
 *   GitHub-owned hosts below (codeload serves the actual bytes).
 * - No Authorization header is sent: source archives are public and the
 *   download host does not accept API tokens.
 * - Bodies stream to disk (sink); an archive is never held fully in memory.
 * - Nothing here is unlimited: upstream throttling, timeouts, and the
 *   configured byte budgets all apply and surface as typed errors.
 */
class ArchiveDownloader
{
    /** Hosts a download redirect may land on. Nothing else is followed. */
    public const REDIRECT_HOSTS = ['github.com', 'codeload.github.com'];

    private const MAX_REDIRECTS = 3;

    public function __construct(
        private readonly string $userAgent,
        private readonly int $timeout,
        private readonly int $maxArchiveBytes,
        private readonly int $archiveCacheDays,
    ) {}

    public static function fromConfig(): self
    {
        $config = config('revival.github');

        return new self(
            $config['user_agent'],
            (int) $config['archive_timeout'],
            (int) $config['max_archive_bytes'],
            (int) $config['archive_cache_days'],
        );
    }

    /**
     * @return array{path: string, sha256: string, bytes: int, cached: bool, downloaded_at: ?string, source_url: string}
     */
    public function get(string $owner, string $name, string $sha): array
    {
        $this->assertSegment($owner);
        $this->assertSegment($name);

        if (! preg_match('/^[0-9a-f]{40}$/', $sha)) {
            throw new GitHubException('Invalid commit SHA.');
        }

        $key = "import-archives/{$owner}/{$name}/{$sha}.zip";

        if (Storage::disk('local')->exists($key)) {
            return $this->describeCached($key, $owner, $name, $sha);
        }

        $url = "https://github.com/{$owner}/{$name}/archive/{$sha}.zip";
        $tmp = 'import-archives/tmp/'.Str::random(40).'.zip';
        Storage::disk('local')->makeDirectory('import-archives/tmp');

        try {
            $this->download($url, Storage::disk('local')->path($tmp));
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($tmp);

            throw $e;
        }

        $absolute = Storage::disk('local')->path($tmp);
        $bytes = filesize($absolute);

        if ($bytes === false || $bytes > $this->maxArchiveBytes) {
            Storage::disk('local')->delete($tmp);

            throw new ArchiveInvalidException(sprintf(
                'Repository archive exceeds the %s compressed-byte budget; it cannot be imported.',
                number_format($this->maxArchiveBytes)
            ));
        }

        $sha256 = hash_file('sha256', $absolute);

        if ($sha256 === false) {
            Storage::disk('local')->delete($tmp);

            throw new GitHubException('Could not checksum the downloaded archive.');
        }

        Storage::disk('local')->makeDirectory("import-archives/{$owner}/{$name}");

        if (! Storage::disk('local')->move($tmp, $key)) {
            Storage::disk('local')->delete($tmp);

            throw new GitHubException('Could not store the downloaded archive.');
        }

        $downloadedAt = now()->toIso8601String();
        Storage::disk('local')->put($key.'.json', json_encode([
            'sha256' => $sha256,
            'bytes' => $bytes,
            'downloaded_at' => $downloadedAt,
            'source_url' => $url,
        ]));

        $this->prune();

        return [
            'path' => Storage::disk('local')->path($key),
            'sha256' => $sha256,
            'bytes' => $bytes,
            'cached' => false,
            'downloaded_at' => $downloadedAt,
            'source_url' => $url,
        ];
    }

    private function describeCached(string $key, string $owner, string $name, string $sha): array
    {
        $absolute = Storage::disk('local')->path($key);
        $sidecar = json_decode((string) Storage::disk('local')->get($key.'.json'), true);

        return [
            'path' => $absolute,
            'sha256' => is_string($sidecar['sha256'] ?? null)
                ? $sidecar['sha256']
                : (string) hash_file('sha256', $absolute),
            'bytes' => (int) (filesize($absolute) ?: 0),
            'cached' => true,
            'downloaded_at' => $sidecar['downloaded_at'] ?? null,
            'source_url' => "https://github.com/{$owner}/{$name}/archive/{$sha}.zip",
        ];
    }

    private function download(string $url, string $sink): void
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            try {
                $response = Http::withHeaders([
                    'Accept' => 'application/octet-stream',
                    'User-Agent' => $this->userAgent,
                ])
                    ->withOptions(['allow_redirects' => false, 'sink' => $sink])
                    ->timeout($this->timeout)
                    ->get($current);
            } catch (ConnectionException $e) {
                throw new GitHubTimeoutException('GitHub archive download timed out.', 0, $e);
            }

            // Fakes bypass transfer options, so the sink stays empty under
            // Http::fake(): persist the received body in that case only.
            if ((! is_file($sink) || filesize($sink) === 0) && $response->body() !== '') {
                file_put_contents($sink, $response->body());
            }

            $status = $response->status();

            if ($status >= 300 && $status < 400) {
                $current = $this->followRedirect($current, $response);

                continue;
            }

            $this->throwForStatus($response);

            $length = $response->header('Content-Length');

            if (is_numeric($length) && (int) $length > $this->maxArchiveBytes) {
                throw new ArchiveInvalidException(sprintf(
                    'Repository archive exceeds the %s compressed-byte budget; it cannot be imported.',
                    number_format($this->maxArchiveBytes)
                ));
            }

            return;
        }

        throw new GitHubException('GitHub archive download exceeded the redirect limit.');
    }

    private function followRedirect(string $current, Response $response): string
    {
        $location = (string) $response->header('Location');

        if ($location === '') {
            throw new GitHubException('GitHub archive redirect without a destination.');
        }

        if (str_starts_with($location, '/')) {
            $parts = parse_url($current);
            $location = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'github.com').$location;
        }

        $parts = parse_url($location);

        if (($parts['scheme'] ?? null) !== 'https' || ! in_array($parts['host'] ?? null, self::REDIRECT_HOSTS, true)) {
            throw new GitHubException('Archive redirect to untrusted destination rejected.');
        }

        return $location;
    }

    private function throwForStatus(Response $response): void
    {
        $status = $response->status();

        if ($status === 404) {
            throw new RepositoryNotFoundException('Repository archive not found or not public.');
        }

        if (GitHubClient::isRateLimited($response)) {
            throw new RateLimitedException(
                'GitHub download rate limit exceeded. Try again later.',
                GitHubClient::rateLimitResetsAt($response)
            );
        }

        if (! $response->successful()) {
            throw new GitHubException("GitHub archive download failed with status {$status}.");
        }
    }

    /**
     * Opportunistic retention enforcement: every stored download prunes
     * archives (and sidecars) older than the cache window.
     */
    private function prune(): void
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subDays($this->archiveCacheDays)->timestamp;

        foreach ($disk->allFiles('import-archives') as $file) {
            if (str_contains($file, 'import-archives/tmp/')) {
                continue;
            }

            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
            }
        }
    }

    private function assertSegment(string $segment): void
    {
        if ($segment === '' || str_contains($segment, '/') || $segment === '.' || $segment === '..') {
            throw new GitHubException('Invalid request path segment.');
        }
    }
}
