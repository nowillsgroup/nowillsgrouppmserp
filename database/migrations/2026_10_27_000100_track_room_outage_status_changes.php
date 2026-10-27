<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Track whether a reservation-desk room block itself moved the room to
 * out-of-order, so releasing a block never clears a status set for another
 * operational reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('room_outages') || Schema::hasColumn('room_outages', 'room_status_changed_by_block')) {
            return;
        }

        Schema::table('room_outages', function (Blueprint $t) {
            $t->boolean('room_status_changed_by_block')->default(false)->after('status');
        });

        // Preserve the existing release behavior for blocks already active at
        // deployment; ownership can only be recorded reliably for new blocks.
        DB::table('room_outages')->where('status', 'active')
            ->whereExists(fn ($q) => $q->select(DB::raw('1'))->from('rooms')
                ->whereColumn('rooms.id', 'room_outages.room_id')->where('rooms.status', 'out_of_order'))
            ->update(['room_status_changed_by_block' => true]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('room_outages') || ! Schema::hasColumn('room_outages', 'room_status_changed_by_block')) {
            return;
        }

        Schema::table('room_outages', function (Blueprint $t) {
            $t->dropColumn('room_status_changed_by_block');
        });
    }
};
