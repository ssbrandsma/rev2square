<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only finding history. Every create, edit, status
        // change, and delete records the finding's full state plus
        // the page revision it belongs to, so pinned citations
        // resolve immutable evidence and every transition keeps its
        // author and reason. finding_id is deliberately not a
        // foreign key: history survives the finding's deletion.
        Schema::create('doc_finding_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doc_page_id')->constrained('doc_pages')->cascadeOnDelete();
            $table->unsignedBigInteger('finding_id');
            $table->string('action', 16);
            $table->string('status', 16);
            $table->text('statement');
            $table->json('refs')->nullable();
            $table->text('measurement_notes')->nullable();
            $table->unsignedInteger('page_rev');
            $table->foreignId('changed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->index(['doc_page_id', 'finding_id', 'page_rev']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_finding_events');
    }
};
