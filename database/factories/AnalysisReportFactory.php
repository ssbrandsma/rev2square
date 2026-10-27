<?php

namespace Database\Factories;

use App\Enums\ReviewStatus;
use App\Models\AnalysisReport;
use App\Models\AnalysisRun;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AnalysisReport> */
class AnalysisReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'analysis_run_id' => AnalysisRun::factory(),
            // Tests needing run/report project consistency set both explicitly.
            'project_id' => Project::factory(),
            'version_number' => 1,
            'supersedes_report_id' => null,
            'payload_json' => ['schema_version' => '1.0'],
            'review_status' => ReviewStatus::PendingReview,
            'reviewer_id' => null,
            'reviewed_at' => null,
            'published_at' => null,
        ];
    }
}
