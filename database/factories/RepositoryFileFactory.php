<?php

namespace Database\Factories;

use App\Models\Repository;
use App\Models\RepositoryFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RepositoryFile> */
class RepositoryFileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'repository_id' => Repository::factory(),
            'commit_sha' => str_repeat('a', 40),
            'path' => fake()->unique()->word().'.md',
            'entry_hash' => fn (array $attrs) => RepositoryFile::entryHash(
                $attrs['repository_id'] instanceof Repository ? $attrs['repository_id']->id : $attrs['repository_id'],
                $attrs['path'],
                $attrs['commit_sha']
            ),
            'extension' => 'md',
            'language' => 'Markdown',
            'kind' => 'doc',
            'size_bytes' => 100,
            'blob_sha' => null,
            'subsystem' => '(root)',
            'eligible' => true,
            'skip_reason' => null,
            'status' => 'pending',
            'relevance_score' => null,
            'score_reasons' => null,
            'evidence_source_id' => null,
        ];
    }
}
