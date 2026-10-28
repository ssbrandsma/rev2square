<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discussion_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // No cascade: removing a thread keeps its replies readable as a tombstone.
            $table->foreignId('parent_id')->nullable()->constrained('discussion_posts')->nullOnDelete();
            $table->foreignId('contributor_task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16)->default('post');
            $table->string('title')->nullable();
            $table->text('body');
            $table->string('submission_key', 64)->nullable()->unique();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('last_reply_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'parent_id', 'id']);
            $table->index(['project_id', 'type', 'id']);
        });

        Schema::create('discussion_mutes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discussion_post_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'discussion_post_id']);
        });

        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('reportable');
            $table->text('reason');
            $table->string('status', 16)->default('pending');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['reportable_type', 'reportable_id', 'status']);
        });

        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('room', 64)->index();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->string('client_key', 64)->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['room', 'id']);
        });

        Schema::create('chat_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('room', 64);
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'room']);
        });

        Schema::create('follows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('followable');
            $table->timestamps();

            $table->unique(['user_id', 'followable_type', 'followable_id'], 'follows_unique');
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->boolean('discussion_open')->default(true)->after('publication_status');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('show_presence')->default(false)->after('remember_token');
            $table->json('notification_preferences')->nullable()->after('show_presence');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['show_presence', 'notification_preferences']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('discussion_open');
        });

        Schema::dropIfExists('follows');
        Schema::dropIfExists('chat_reads');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('reports');
        Schema::dropIfExists('discussion_mutes');
        Schema::dropIfExists('discussion_posts');
    }
};
