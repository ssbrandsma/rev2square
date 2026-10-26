<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rows are immutable per (repository, path, commit): re-imports at a
        // new commit create new rows; history is never overwritten.
        // analysis_run_id gets its foreign key in WP3 when analysis_runs exists.
        Schema::create('evidence_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('analysis_run_id')->nullable();
            $table->foreignId('repository_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type')->default('github_file');
            $table->text('url');
            $table->string('path', 1024)->nullable();
            $table->string('commit_sha', 40)->nullable();
            $table->string('content_hash', 64);
            // sha256(repository_id|path|commit_sha): idempotency key (a direct
            // composite unique key would exceed the index length limit).
            $table->string('identity_hash', 64)->unique();
            $table->longText('content')->nullable();
            $table->string('visibility')->default('public');
            $table->timestamp('fetched_at')->nullable();
            $table->string('ingestion_status')->default('ok');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['repository_id', 'commit_sha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_sources');
    }
};
