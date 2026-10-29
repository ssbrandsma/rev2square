<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('binary_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_artifact_id')->constrained('project_artifacts')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('adapter', 32);
            $table->string('adapter_version', 64)->nullable();
            $table->string('target', 64)->nullable();
            $table->json('config')->nullable();
            $table->json('overrides')->nullable();
            $table->string('status', 24)->default('queued');
            $table->string('progress_note', 255)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->string('evidence_key', 64)->nullable();
            $table->string('dedupe_key', 96)->unique();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['project_artifact_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('binary_analyses');
    }
};
