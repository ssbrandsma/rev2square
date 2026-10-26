<?php

namespace Database\Factories;

use App\Models\EvidenceSource;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EvidenceSource> */
class EvidenceSourceFactory extends Factory
{
    public function definition(): array
    {
        $content = fake()->paragraphs(2, true);

        return [
            'project_id' => null,
            'analysis_run_id' => null,
            'repository_id' => Repository::factory(),
            'source_type' => 'github_file',
            'url' => fake()->url(),
            'path' => fake()->unique()->word().'.md',
            'commit_sha' => fake()->sha1(),
            'content_hash' => hash('sha256', $content),
            'identity_hash' => fn (array $attrs) => EvidenceSource::identityHash(
                $attrs['repository_id'] instanceof Repository ? $attrs['repository_id']->id : $attrs['repository_id'],
                $attrs['path'],
                $attrs['commit_sha']
            ),
            'content' => $content,
            'visibility' => 'public',
            'fetched_at' => now(),
            'ingestion_status' => 'ok',
            'error_message' => null,
        ];
    }
}
