<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Idempotency key for replay-safe normalized bridge events. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('robita_events') || Schema::hasColumn('robita_events', 'source_event_id')) {
            return;
        }

        Schema::table('robita_events', function (Blueprint $table) {
            $table->string('source_event_id', 128)->nullable();
            $table->unique(['hotel_id', 'robita_device_id', 'source_event_id'], 'robita_event_source_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('robita_events') || ! Schema::hasColumn('robita_events', 'source_event_id')) {
            return;
        }

        Schema::table('robita_events', function (Blueprint $table) {
            $table->dropUnique('robita_event_source_unique');
            $table->dropColumn('source_event_id');
        });
    }
};
