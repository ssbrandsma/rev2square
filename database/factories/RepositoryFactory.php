<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Repository> */
class RepositoryFactory extends Factory
{
    public function definition(): array
    {
        $owner = fake()->unique()->userName();
        $name = fake()->unique()->slug(2);

        return [
            'project_id' => Project::factory(),
            'provider' => 'github',
            'canonical_url' => 'https://github.com/'.strtolower($owner).'/'.strtolower($name),
            'owner' => strtolower($owner),
            'name' => strtolower($name),
            'default_branch' => 'main',
            'last_commit_sha' => null,
            'import_status' => 'pending',
            'import_error' => null,
            'meta' => null,
            'last_import_summary' => null,
            'last_synced_at' => null,
        ];
    }
}
