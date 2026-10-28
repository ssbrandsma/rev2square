<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milestones', function (Blueprint $table): void {
            $table->string('origin', 16)->default('ai')->after('manually_edited');
            $table->timestamp('retired_at')->nullable()->after('origin');
        });

        Schema::table('contributor_tasks', function (Blueprint $table): void {
            $table->string('origin', 16)->default('ai')->after('manually_edited');
        });
    }

    public function down(): void
    {
        Schema::table('contributor_tasks', function (Blueprint $table): void {
            $table->dropColumn('origin');
        });

        Schema::table('milestones', function (Blueprint $table): void {
            $table->dropColumn(['origin', 'retired_at']);
        });
    }
};
