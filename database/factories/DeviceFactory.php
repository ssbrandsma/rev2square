<?php

namespace Database\Factories;

use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Device> */
class DeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'manufacturer' => fake()->optional()->company(),
            'model' => fake()->unique()->words(2, true),
            'category' => fake()->optional()->word(),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
