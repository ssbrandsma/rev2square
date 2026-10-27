<?php

namespace Database\Factories;

use App\Enums\AnalysisStatus;
use App\Models\AnalysisRun;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AnalysisRun> */
class AnalysisRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'repository_id' => null,
            'status' => AnalysisStatus::Queued,
            'provider' => 'mock',
            'model' => 'mock',
            'schema_version' => '1.0',
            'input_manifest' => null,
            'input_manifest_hash' => null,
            'token_usage' => null,
            'error_code' => null,
            'error_message' => null,
            'duration_ms' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }
}
