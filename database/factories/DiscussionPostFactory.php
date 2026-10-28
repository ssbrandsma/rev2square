<?php

namespace Database\Factories;

use App\Enums\DiscussionType;
use App\Models\DiscussionPost;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DiscussionPost> */
class DiscussionPostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'parent_id' => null,
            'contributor_task_id' => null,
            'type' => DiscussionType::Post,
            'title' => null,
            'body' => fake()->paragraphs(2, true),
            'submission_key' => fake()->unique()->uuid(),
        ];
    }

    public function reply(): static
    {
        return $this->state(fn (): array => ['parent_id' => DiscussionPost::factory()]);
    }

    public function announcement(): static
    {
        return $this->state(fn (): array => ['type' => DiscussionType::Announcement]);
    }

    public function update(): static
    {
        return $this->state(fn (): array => [
            'type' => DiscussionType::Update,
            'title' => fake()->sentence(4),
        ]);
    }
}
