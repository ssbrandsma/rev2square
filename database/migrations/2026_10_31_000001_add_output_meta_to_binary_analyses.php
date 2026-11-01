<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('binary_analyses', function (Blueprint $table) {
            // Retained tool-output coverage (sha256, bytes, file
            // counts, truncation reason) for the downloadable
            // output.zip stored beside the evidence document. Null
            // for runs from pre-archive runners, non-tree backends,
            // and archives discarded by the coordinator cap.
            $table->json('output_meta')->nullable()->after('evidence_key');
        });
    }

    public function down(): void
    {
        Schema::table('binary_analyses', function (Blueprint $table) {
            $table->dropColumn('output_meta');
        });
    }
};
