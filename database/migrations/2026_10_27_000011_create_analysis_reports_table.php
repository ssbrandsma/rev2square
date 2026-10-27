<?php

use App\Enums\ReviewStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Report rows are immutable versions: payload is written once, review
        // transitions status only. Re-analysis creates a new version.
        Schema::create('analysis_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_run_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->foreignId('supersedes_report_id')->nullable()->constrained('analysis_reports')->nullOnDelete();
            $table->json('payload_json');
            $table->string('review_status')->default(ReviewStatus::PendingReview->value);
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_reports');
    }
};
