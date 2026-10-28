<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Import recovery outlook (R2S-01). Nullable: pre-existing rows read
        // as unknown start/retry, and the sweep only touches stale rows.
        Schema::table('repositories', function (Blueprint $table) {
            $table->timestamp('import_started_at')->nullable()->after('import_error');
            $table->timestamp('next_retry_at')->nullable()->after('import_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn(['import_started_at', 'next_retry_at']);
        });
    }
};
