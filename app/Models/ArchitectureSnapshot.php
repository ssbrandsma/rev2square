<?php

namespace App\Models;

use App\Enums\AnalysisStatus;
use App\Services\Revival\ArchitecturePrompt;
use App\Services\Revival\ArchitectureSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable architecture analysis snapshot for one repository revision.
 *
 * Rows are append-only: a new analysis creates a new row, and stale
 * results are flagged, never rewritten. The validated diagram payload
 * lives in `payload`; provenance in `input_manifest`.
 */
class ArchitectureSnapshot extends Model
{
    protected $fillable = [
        'project_id',
        'repository_id',
        'user_id',
        'status',
        'cache_key',
        'manifest_commit_sha',
        'evidence_identity',
        'input_manifest',
        'input_manifest_hash',
        'schema_version',
        'prompt_version',
        'provider',
        'model',
        'token_usage',
        'duration_ms',
        'payload',
        'error_code',
        'error_message',
        'started_at',
        'finished_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AnalysisStatus::class,
            'input_manifest' => 'array',
            'token_usage' => 'array',
            'payload' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Repository, $this> */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isReady(): bool
    {
        return $this->status === AnalysisStatus::Ready && is_array($this->payload);
    }

    /** @return list<array<string, mixed>> */
    public function components(): array
    {
        $components = is_array($this->payload) ? ($this->payload['components'] ?? []) : [];

        return is_array($components) ? array_values($components) : [];
    }

    /** @return list<array<string, mixed>> */
    public function relationships(): array
    {
        $edges = is_array($this->payload) ? ($this->payload['relationships'] ?? []) : [];

        return is_array($edges) ? array_values($edges) : [];
    }

    /** @return list<array<string, mixed>> */
    public function unknowns(): array
    {
        $unknowns = is_array($this->payload) ? ($this->payload['unknowns'] ?? []) : [];

        return is_array($unknowns) ? array_values($unknowns) : [];
    }

    public function summary(): string
    {
        $summary = is_array($this->payload) ? ($this->payload['summary'] ?? '') : '';

        return is_string($summary) ? $summary : '';
    }

    /**
     * Compare this snapshot against the repository's current evidence.
     *
     * @return null|'stale_commit'|'stale_evidence'|'stale_schema' null means current.
     */
    public function staleness(Repository $repository): ?string
    {
        if (! $this->isReady()) {
            return null;
        }

        if ($this->schema_version !== ArchitectureSchema::VERSION) {
            return 'stale_schema';
        }

        if ($repository->last_commit_sha === null || $this->manifest_commit_sha !== $repository->last_commit_sha) {
            return 'stale_commit';
        }

        // Compare exactly the rows the model saw, recorded in the manifest.
        $included = is_array($this->input_manifest) ? ($this->input_manifest['included'] ?? null) : null;

        if (! is_array($included) || $included === []) {
            return 'stale_evidence';
        }

        $current = EvidenceSource::where('repository_id', $repository->id)
            ->whereIn('id', array_column($included, 'source_id'))
            ->get(['id', 'content_hash'])
            ->map(fn ($row) => ['id' => $row->id, 'content_hash' => $row->content_hash])
            ->all();

        if (count($current) !== count($included)) {
            return 'stale_evidence';
        }

        return hash_equals((string) $this->evidence_identity, self::evidenceIdentity($current)) ? null : 'stale_evidence';
    }

    /**
     * Stable identity over the evidence rows the model saw.
     *
     * @param  list<array{id: mixed, content_hash: mixed}>  $rows
     */
    public static function evidenceIdentity(array $rows): string
    {
        $pairs = [];

        foreach ($rows as $row) {
            $pairs[] = ((int) ($row['id'] ?? 0)).':'.((string) ($row['content_hash'] ?? ''));
        }

        sort($pairs);

        return hash('sha256', implode("\n", $pairs));
    }

    public static function cacheKey(int $projectId, int $repositoryId, string $commitSha): string
    {
        return hash('sha256', implode('|', [
            'architecture',
            $projectId,
            $repositoryId,
            strtolower($commitSha),
            ArchitectureSchema::VERSION,
            ArchitecturePrompt::VERSION,
        ]));
    }
}
