<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploader_id')->constrained('users')->cascadeOnDelete();
            $table->string('original_name', 255);
            $table->unsignedBigInteger('byte_size');
            $table->char('sha256', 64);
            $table->string('storage_key', 64)->unique();
            $table->string('format', 16)->default('unknown');
            $table->string('arch', 16)->nullable();
            $table->string('state', 16)->default('uploaded');
            $table->string('failure_code', 32)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->string('extractor', 64)->nullable();
            $table->string('extraction_schema', 16)->nullable();
            $table->json('warnings')->nullable();
            $table->json('limits_applied')->nullable();
            $table->json('tech_attributes')->nullable();
            $table->timestamp('extracted_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'state']);
            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_artifacts');
    }
};
