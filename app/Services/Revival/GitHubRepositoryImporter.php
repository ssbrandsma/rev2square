<?php

namespace App\Services\Revival;

use App\Models\Repository;
use App\Services\GitHub\GitHubClient;

/**
 * Orchestrates one bounded repository import: metadata → HEAD → tree →
 * selection → content fetch → manifest persistence.
 */
class GitHubRepositoryImporter
{
    public function __construct(
        private readonly GitHubClient $client,
        private readonly EvidenceManifestBuilder $manifest,
    ) {}

    public static function fromConfig(): self
    {
        return new self(GitHubClient::fromConfig(), new EvidenceManifestBuilder);
    }

    /**
     * @return array{up_to_date: bool, manifest: list<array<string, mixed>>, total_bytes: int, approx_tokens: int, skipped: list<array{path: string, reason: string}>, warnings: list<string>}
     */
    public function import(Repository $repository, ?string $branch = null): array
    {
        $config = config('revival.github');
        $warnings = [];

        $meta = $this->client->getRepo($repository->owner, $repository->name);
        $defaultBranch = $meta['default_branch'] ?? 'main';

        $repository->forceFill([
            'default_branch' => $defaultBranch,
            'meta' => [
                'description' => $meta['description'] ?? null,
                'language' => $meta['language'] ?? null,
                'size_kb' => $meta['size'] ?? null,
                'stars' => $meta['stargazers_count'] ?? null,
                'forks' => $meta['forks_count'] ?? null,
                'open_issues' => $meta['open_issues_count'] ?? null,
                'pushed_at' => $meta['pushed_at'] ?? null,
            ],
        ])->save();

        $sha = $this->client->getBranchSha($repository->owner, $repository->name, $branch ?? $defaultBranch);

        if ($repository->last_commit_sha === $sha && $repository->currentEvidence()->exists()) {
            $repository->forceFill(['last_synced_at' => now(), 'import_status' => 'ready'])->save();

            return [
                'up_to_date' => true,
                'manifest' => [],
                'total_bytes' => 0,
                'approx_tokens' => 0,
                'skipped' => [],
                'warnings' => [],
            ];
        }

        $tree = $this->client->getTree($repository->owner, $repository->name, $sha);

        if ($tree['truncated']) {
            $warnings[] = 'Repository tree was truncated by the API; import covers the returned entries only.';
        }

        ['selected' => $selected, 'skipped' => $skipped] = FileSelector::select($tree['tree'], $config);

        if ($selected === []) {
            $warnings[] = 'No importable files found within the selection rules.';
        }

        $files = [];
        $tokenBudget = (int) $config['token_budget'];
        $approxTokens = 0;

        foreach ($selected as $entry) {
            $fetched = $this->client->getFileContent(
                $repository->owner, $repository->name, $entry['path'], $sha
            );

            if (! mb_check_encoding($fetched['content'], 'UTF-8')) {
                $skipped[] = ['path' => $entry['path'], 'reason' => 'not UTF-8 text'];

                continue;
            }

            // Strip NUL/control-only garbage defensively; text is stored verbatim otherwise.
            if (str_contains($fetched['content'], "\0")) {
                $skipped[] = ['path' => $entry['path'], 'reason' => 'binary content'];

                continue;
            }

            $entryTokens = (int) ceil(strlen($fetched['content']) / 4);

            if ($approxTokens + $entryTokens > $tokenBudget) {
                $skipped[] = ['path' => $entry['path'], 'reason' => 'over token budget'];

                continue;
            }

            $approxTokens += $entryTokens;
            $files[] = [
                'path' => $entry['path'],
                'url' => $fetched['html_url'],
                'content' => $fetched['content'],
                'size' => $fetched['size'],
            ];
        }

        $result = $this->manifest->persist($repository, $sha, $files, $skipped, $warnings);

        return [
            'up_to_date' => false,
            'manifest' => $result['manifest'],
            'total_bytes' => $result['total_bytes'],
            'approx_tokens' => $result['approx_tokens'],
            'skipped' => $skipped,
            'warnings' => $warnings,
        ];
    }
}
