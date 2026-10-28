<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observations', function (Blueprint $table): void {
            $table->string('source_url', 2000)->nullable()->after('body');
        });

        Schema::table('test_reports', function (Blueprint $table): void {
            $table->text('environment')->nullable()->after('body');
        });

        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();
            $table->morphs('attachable');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');

        Schema::table('test_reports', function (Blueprint $table): void {
            $table->dropColumn('environment');
        });

        Schema::table('observations', function (Blueprint $table): void {
            $table->dropColumn('source_url');
        });
    }
};
