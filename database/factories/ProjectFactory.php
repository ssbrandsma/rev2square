<?php

namespace Database\Factories;

use App\Enums\ProjectActivity;
use App\Enums\ProjectPhase;
use App\Enums\PublicationStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->unique()->words(3, true);

        return [
            'slug' => Str::slug($title).'-'.fake()->unique()->randomNumber(4),
            'owner_id' => User::factory(),
            'title' => $title,
            'pitch' => fake()->sentence(),
            'body' => fake()->paragraphs(2, true),
            'phase' => ProjectPhase::ResearchFeasibility,
            'activity' => ProjectActivity::Active,
            'publication_status' => PublicationStatus::Published,
        ];
    }

    public function draft(): static
    {
        return $this->state(['publication_status' => PublicationStatus::Draft]);
    }
}
