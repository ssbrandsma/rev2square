<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\DeviceVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeviceVariant> */
class DeviceVariantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'hardware_revision' => fake()->randomElement(['A', 'B', 'C']),
            'firmware_version' => fake()->numerify('#.#.#'),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
