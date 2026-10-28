<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('architecture_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->default('QUEUED');
            $table->string('cache_key', 64)->index();
            $table->string('manifest_commit_sha', 64)->nullable();
            $table->string('evidence_identity', 64)->nullable();
            $table->json('input_manifest')->nullable();
            $table->string('input_manifest_hash', 64)->nullable();
            $table->string('schema_version', 16)->default('1.0');
            $table->string('prompt_version', 16)->default('1.0');
            $table->string('provider', 64)->nullable();
            $table->string('model', 128)->nullable();
            $table->json('token_usage')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('payload')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 2000)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['repository_id', 'status']);
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('architecture_snapshots');
    }
};
