<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectMedia;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProjectMediaFactory extends Factory
{
    protected $model = ProjectMedia::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'kind' => ProjectMedia::KIND_IMAGE,
            'storage_key' => Str::random(40),
            'original_name' => 'photo.jpg',
            'mime' => 'image/jpeg',
            'size' => 1024,
            'width' => 800,
            'height' => 600,
            'sha256' => hash('sha256', Str::random()),
            'original_sha256' => hash('sha256', Str::random()),
            'title' => fake()->words(3, true),
            'processing_status' => ProjectMedia::STATUS_READY,
        ];
    }

    public function file(): static
    {
        return $this->state([
            'kind' => ProjectMedia::KIND_FILE,
            'original_name' => 'notes.pdf',
            'mime' => 'application/pdf',
            'width' => null,
            'height' => null,
        ]);
    }

    public function processing(): static
    {
        return $this->state(['processing_status' => ProjectMedia::STATUS_PROCESSING]);
    }

    public function failed(): static
    {
        return $this->state([
            'processing_status' => ProjectMedia::STATUS_FAILED,
            'processing_error' => ProjectMedia::ERROR_PROCESSING_UNAVAILABLE,
        ]);
    }
}
