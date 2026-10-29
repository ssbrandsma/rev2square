<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 'needs_configuration' (19 chars) exceeds the original
        // varchar(16).
        Schema::table('project_artifacts', function (Blueprint $table) {
            $table->string('state', 32)->default('uploaded')->change();
        });
    }

    public function down(): void
    {
        Schema::table('project_artifacts', function (Blueprint $table) {
            $table->string('state', 16)->default('uploaded')->change();
        });
    }
};
