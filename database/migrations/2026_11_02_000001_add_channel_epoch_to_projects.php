<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            // Realtime channel version. Bumped whenever project access
            // changes (visibility, membership, ownership) so sockets
            // authorized earlier stop receiving new messages (P1-2).
            $table->unsignedInteger('channel_epoch')->default(0)->after('discussion_open');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('channel_epoch');
        });
    }
};
