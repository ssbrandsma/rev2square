<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Partial-evidence provenance. All columns are nullable / defaulted:
        // pre-existing rows read as complete evidence with unknown score.
        Schema::table('evidence_sources', function (Blueprint $table) {
            $table->boolean('is_partial')->default(false)->after('content');
            // [{start:int,end:int}] 1-based inclusive ranges into the original file.
            $table->json('line_ranges')->nullable()->after('is_partial');
            $table->unsignedBigInteger('original_bytes')->nullable()->after('line_ranges');
            $table->unsignedInteger('original_lines')->nullable()->after('original_bytes');
            $table->integer('relevance_score')->nullable()->after('original_lines');
            $table->string('language', 64)->nullable()->after('relevance_score');
            $table->string('blob_sha', 40)->nullable()->after('language');
            $table->string('extraction_note', 255)->nullable()->after('blob_sha');
        });
    }

    public function down(): void
    {
        Schema::table('evidence_sources', function (Blueprint $table) {
            $table->dropColumn([
                'is_partial', 'line_ranges', 'original_bytes', 'original_lines',
                'relevance_score', 'language', 'blob_sha', 'extraction_note',
            ]);
        });
    }
};
