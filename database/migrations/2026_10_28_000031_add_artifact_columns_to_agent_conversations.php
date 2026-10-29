<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_conversations', function (Blueprint $table) {
            $table->json('artifact_ids')->nullable()->after('title');
            $table->text('device_context')->nullable()->after('artifact_ids');
            $table->timestamp('artifact_consent_at')->nullable()->after('device_context');
        });
    }

    public function down(): void
    {
        Schema::table('agent_conversations', function (Blueprint $table) {
            $table->dropColumn(['artifact_ids', 'device_context', 'artifact_consent_at']);
        });
    }
};
