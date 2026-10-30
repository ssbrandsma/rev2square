<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->string('adapter', 32)->unique();
            $table->boolean('available');
            $table->string('version', 64)->nullable();
            $table->json('targets')->nullable();
            $table->json('formats')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->string('worker', 128)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_capabilities');
    }
};
