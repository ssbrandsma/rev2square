<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real ingestion stages (R2S-09): resolving revision, downloading
        // archive, inspecting archive, selecting evidence. Null when idle.
        Schema::table('repositories', function (Blueprint $table) {
            $table->string('import_stage', 64)->nullable()->after('next_retry_at');
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn('import_stage');
        });
    }
};
