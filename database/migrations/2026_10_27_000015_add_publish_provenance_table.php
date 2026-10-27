<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contributor_tasks', function (Blueprint $table) {
            $table->foreignId('origin_report_id')->nullable()->constrained('analysis_reports')->nullOnDelete();
            $table->foreignId('last_seen_report_id')->nullable()->constrained('analysis_reports')->nullOnDelete();
            $table->boolean('manually_edited')->default(false);
        });

        Schema::table('milestones', function (Blueprint $table) {
            $table->string('import_key')->nullable();
            $table->foreignId('origin_report_id')->nullable()->constrained('analysis_reports')->nullOnDelete();
            $table->foreignId('last_seen_report_id')->nullable()->constrained('analysis_reports')->nullOnDelete();
            $table->boolean('manually_edited')->default(false);

            $table->unique(['project_id', 'import_key']);
        });

        Schema::table('analysis_reports', function (Blueprint $table) {
            // Maintainer annotations live here; payload_json is never rewritten.
            $table->text('review_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('analysis_reports', function (Blueprint $table) {
            $table->dropColumn('review_notes');
        });

        Schema::table('milestones', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'import_key']);
            $table->dropConstrainedForeignId('origin_report_id');
            $table->dropConstrainedForeignId('last_seen_report_id');
            $table->dropColumn(['import_key', 'manually_edited']);
        });

        Schema::table('contributor_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origin_report_id');
            $table->dropConstrainedForeignId('last_seen_report_id');
            $table->dropColumn('manually_edited');
        });
    }
};
