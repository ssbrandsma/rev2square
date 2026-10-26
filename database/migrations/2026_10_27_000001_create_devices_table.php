<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('manufacturer')->nullable();
            $table->string('model');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['manufacturer', 'model']);
            $table->index(['manufacturer', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
