<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Presentation media library: serves cover, inline story images,
        // gallery, and supporting files. Distinct from observation
        // attachments (Attachment) and private research uploads
        // (ProjectArtifact); rows here are never parsed as evidence.
        Schema::create('project_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 16);
            $table->string('category', 32)->default('other');
            $table->string('storage_key', 64);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->char('sha256', 64);
            $table->char('original_sha256', 64);
            $table->string('title', 255)->nullable();
            $table->string('caption', 500)->nullable();
            $table->string('alt', 500)->nullable();
            $table->string('credit', 255)->nullable();
            $table->string('license', 255)->nullable();
            $table->unsignedTinyInteger('focal_x')->default(50);
            $table->unsignedTinyInteger('focal_y')->default(50);
            $table->unsignedInteger('gallery_order')->default(0);
            $table->boolean('in_gallery')->default(false);
            $table->string('processing_status', 16)->default('ready');
            $table->timestamps();

            $table->index(['project_id', 'kind']);
            $table->index(['project_id', 'in_gallery', 'gallery_order']);
        });

        Schema::table('projects', function (Blueprint $table) {
            // Versioned rich-story document (JSON, see StoryDocument).
            // NULL + story_format=plain keeps every existing story byte
            // for byte on the legacy path; no data rewrite happens here.
            $table->json('story_document')->nullable()->after('body');
            $table->string('story_format', 16)->default('plain')->after('story_document');
            $table->foreignId('cover_media_id')->nullable()->after('story_format')
                ->constrained('project_media')->nullOnDelete();
            // Optimistic concurrency for story/media edits.
            $table->unsignedInteger('presentation_version')->default(0)->after('cover_media_id');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cover_media_id');
            $table->dropColumn(['story_document', 'story_format', 'presentation_version']);
        });

        Schema::dropIfExists('project_media');
    }
};
