<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('category', 32);
            $table->string('title', 200);
            $table->string('summary', 500)->nullable();
            $table->string('publication_status', 16)->default('DRAFT');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('current_rev')->default(1);
            $table->json('body')->nullable();
            $table->text('search_text')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'publication_status']);
            $table->index(['project_id', 'category', 'sort_order']);
        });

        Schema::create('doc_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doc_page_id')->constrained('doc_pages')->cascadeOnDelete();
            $table->unsignedInteger('rev');
            $table->string('title', 200);
            $table->string('summary', 500)->nullable();
            $table->string('category', 32);
            $table->string('publication_status', 16);
            $table->json('body')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['doc_page_id', 'rev']);
        });

        Schema::create('doc_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doc_page_id')->constrained('doc_pages')->cascadeOnDelete();
            $table->unsignedInteger('rev');
            $table->string('status', 16);
            $table->text('statement');
            $table->json('refs')->nullable();
            $table->text('measurement_notes')->nullable();
            $table->foreignId('status_changed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->string('status_change_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['doc_page_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_findings');
        Schema::dropIfExists('doc_revisions');
        Schema::dropIfExists('doc_pages');
    }
};
