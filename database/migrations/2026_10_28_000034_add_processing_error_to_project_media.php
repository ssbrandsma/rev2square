<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_media', function (Blueprint $table) {
            // Machine-readable failure code driving user-facing recovery
            // messaging (retryable vs permanent). NULL = no failure info.
            $table->string('processing_error', 32)->nullable()->after('processing_status');
        });
    }

    public function down(): void
    {
        Schema::table('project_media', function (Blueprint $table) {
            $table->dropColumn('processing_error');
        });
    }
};
