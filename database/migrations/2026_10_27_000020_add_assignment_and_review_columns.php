<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Task assignment target for approved claims. Null = unassigned.
        Schema::table('contributor_tasks', function (Blueprint $table) {
            $table->foreignId('assigned_to_user_id')->nullable()->after('status')
                ->constrained('users')->nullOnDelete();
        });

        // Review provenance for contribution decisions.
        Schema::table('contribution_offers', function (Blueprint $table) {
            $table->text('decision_reason')->nullable()->after('status');
            $table->foreignId('decided_by_user_id')->nullable()->after('decision_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('decided_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('contribution_offers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decided_by_user_id');
            $table->dropColumn(['decision_reason', 'decided_at']);
        });

        Schema::table('contributor_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to_user_id');
        });
    }
};
