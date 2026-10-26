<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\ContributorTask;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContributorTask> */
class ContributorTaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'milestone_id' => null,
            'title' => fake()->sentence(5),
            'description' => fake()->optional()->sentence(),
            'skills' => ['research', 'documentation'],
            'status' => TaskStatus::Open,
            'definition_of_done' => fake()->optional()->sentence(),
            'requires_hardware' => false,
            'import_key' => null,
        ];
    }
}
