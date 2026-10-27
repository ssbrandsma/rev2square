<?php

use App\Enums\AnalysisStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default(AnalysisStatus::Queued->value);
            $table->string('provider')->default('meta');
            $table->string('model')->nullable();
            $table->string('schema_version')->default('1.0');
            $table->json('input_manifest')->nullable();
            $table->string('input_manifest_hash', 64)->nullable();
            $table->json('token_usage')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_runs');
    }
};
