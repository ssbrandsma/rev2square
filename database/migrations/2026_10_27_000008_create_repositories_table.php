<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->default('github');
            $table->string('canonical_url');
            $table->string('owner');
            $table->string('name');
            $table->string('default_branch')->nullable();
            $table->string('last_commit_sha', 40)->nullable();
            $table->string('import_status')->default('pending');
            $table->text('import_error')->nullable();
            $table->json('meta')->nullable();
            $table->json('last_import_summary')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            // One tracking row per project: each project's evidence stays isolated.
            $table->unique(['project_id', 'canonical_url']);
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
