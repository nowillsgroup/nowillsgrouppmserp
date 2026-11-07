<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Day use.
     *
     * A day-use guest takes the room for a few hours and leaves the same day, so the
     * stay has no nights: arrival and departure fall on the business date. The flag
     * keeps that from being mistaken for a one-night stay (check-in no longer pushes
     * the departure to tomorrow) and the time records until when the room is held.
     *
     * Nothing is backfilled: every existing row stays an overnight stay.
     */
    public function up(): void
    {
        if (! Schema::hasTable('reservation_rooms') || Schema::hasColumn('reservation_rooms', 'is_day_use')) {
            return;
        }

        Schema::table('reservation_rooms', function (Blueprint $table) {
            $table->boolean('is_day_use')->default(false)->after('room_id');
            $table->time('day_use_until')->nullable()->after('is_day_use');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('reservation_rooms', 'is_day_use')) {
            return;
        }

        Schema::table('reservation_rooms', function (Blueprint $table) {
            $table->dropColumn(['is_day_use', 'day_use_until']);
        });
    }
};
