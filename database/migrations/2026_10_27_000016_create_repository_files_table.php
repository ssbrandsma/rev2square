<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Complete repository inventory per (repository, commit): every blob
        // discovered at the imported commit, independent of whether it was
        // downloaded into AI evidence. History is never overwritten.
        Schema::create('repository_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('commit_sha', 40);
            $table->string('path', 1024);
            // sha256(repository_id|path|commit_sha): idempotency key (a direct
            // composite unique key would exceed the index length limit).
            $table->string('entry_hash', 64)->unique();
            $table->string('extension', 32)->nullable();
            $table->string('language', 64)->nullable();
            $table->string('kind', 32);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('blob_sha', 40)->nullable();
            $table->string('subsystem', 255)->default('(root)');
            $table->boolean('eligible')->default(false);
            $table->string('skip_reason', 255)->nullable();
            // pending → selected → downloaded → stored, or skipped / failed.
            $table->string('status', 32)->default('pending');
            $table->integer('relevance_score')->nullable();
            $table->json('score_reasons')->nullable();
            $table->foreignId('evidence_source_id')->nullable()->constrained('evidence_sources')->nullOnDelete();
            $table->timestamps();

            $table->index(['repository_id', 'commit_sha']);
            $table->index(['repository_id', 'commit_sha', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repository_files');
    }
};
