<?php

use App\Models\DocFindingEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DocFindingEvent::backfillLegacy();
    }

    public function down(): void
    {
        // Remove exactly what up() added: legacy import events are
        // only ever created by this migration. Query builder (not
        // model) delete: event rows are otherwise immutable.
        DB::table('doc_finding_events')->where('action', DocFindingEvent::ACTION_LEGACY)->delete();
    }
};
