<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evidence_sources', function (Blueprint $table) {
            $table->foreign('analysis_run_id')->references('id')->on('analysis_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('evidence_sources', function (Blueprint $table) {
            $table->dropForeign(['analysis_run_id']);
        });
    }
};
