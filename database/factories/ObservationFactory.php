<?php

namespace Database\Factories;

use App\Models\Observation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Observation> */
class ObservationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'contributor_task_id' => null,
            'device_variant_id' => null,
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
        ];
    }
}
