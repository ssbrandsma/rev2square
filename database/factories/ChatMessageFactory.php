<?php

namespace Database\Factories;

use App\Models\ChatMessage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChatMessage> */
class ChatMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'room' => 'lounge',
            'project_id' => null,
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
            'client_key' => fake()->unique()->uuid(),
        ];
    }

    public function projectRoom(?Project $project = null): static
    {
        $project ??= Project::factory()->create();

        return $this->state(fn (): array => [
            'room' => ChatMessage::projectRoom($project),
            'project_id' => $project->id,
        ]);
    }
}
