<?php

use App\Enums\TaskStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contributor_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('skills')->nullable();
            $table->string('status')->default(TaskStatus::Open->value);
            $table->text('definition_of_done')->nullable();
            $table->boolean('requires_hardware')->default(false);
            // Cross-version stable identity (WP4 publisher provenance columns land in WP4).
            $table->string('import_key')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'import_key']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contributor_tasks');
    }
};
