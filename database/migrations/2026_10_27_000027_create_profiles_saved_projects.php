<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('handle', 30)->nullable()->unique()->after('name');
            $table->text('bio')->nullable()->after('handle');
            $table->json('skills')->nullable()->after('bio');
            $table->json('links')->nullable()->after('skills');
            $table->string('availability', 255)->nullable()->after('links');
            $table->text('hardware_interests')->nullable()->after('availability');
            $table->string('avatar_path')->nullable()->after('hardware_interests');
        });

        // Previous handles keep resolving so renaming never breaks a
        // shared profile link. Handles are never recycled to another user.
        Schema::create('user_handles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('handle', 30)->unique();
            $table->timestamps();
        });

        Schema::create('saved_projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'project_id']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->foreignId('created_by_user_id')->nullable()->after('owner_id')
                ->constrained('users')->nullOnDelete();
        });

        // Historical creator attribution for pre-existing projects. There is
        // no ownership-transfer feature yet; when one lands, owner_id moves
        // and this stays put.
        DB::table('projects')->whereNull('created_by_user_id')->update([
            'created_by_user_id' => DB::raw('owner_id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by_user_id');
        });

        Schema::dropIfExists('saved_projects');
        Schema::dropIfExists('user_handles');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['handle', 'bio', 'skills', 'links', 'availability', 'hardware_interests', 'avatar_path']);
        });
    }
};
