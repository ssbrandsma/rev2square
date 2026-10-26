<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_variant_id')->nullable()->constrained()->nullOnDelete();

            $table->unique(['project_id', 'device_id', 'device_variant_id'], 'project_devices_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_devices');
    }
};
