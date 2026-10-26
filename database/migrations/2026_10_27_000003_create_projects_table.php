<?php

use App\Enums\ProjectActivity;
use App\Enums\ProjectPhase;
use App\Enums\PublicationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->string('pitch', 500)->nullable();
            $table->longText('body')->nullable();
            $table->string('phase')->default(ProjectPhase::ResearchFeasibility->value);
            $table->string('activity')->default(ProjectActivity::Active->value);
            $table->string('publication_status')->default(PublicationStatus::Draft->value);
            $table->timestamps();

            $table->index(['phase', 'activity', 'publication_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
