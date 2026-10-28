<?php

namespace Database\Factories;

use App\Enums\TestOutcome;
use App\Models\Project;
use App\Models\TestReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TestReport> */
class TestReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'contributor_task_id' => null,
            'device_variant_id' => null,
            'title' => fake()->sentence(4),
            'outcome' => TestOutcome::Worked,
            'body' => fake()->paragraphs(2, true),
        ];
    }
}
