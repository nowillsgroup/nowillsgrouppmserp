<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7.61 – a tracker's signal window. Devices differ wildly: a hard-wired unit on a
 * dashboard reports every 30 seconds, a battery unit that wakes on movement may report
 * every couple of hours. A property-wide 20-minute window would mark the second kind
 * offline all day, so each device can carry its own threshold; NULL keeps the property
 * default from Fleet settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicle_trackers')) {
            return;
        }
        if (! Schema::hasColumn('vehicle_trackers', 'stale_minutes')) {
            Schema::table('vehicle_trackers', function (Blueprint $table) {
                $table->unsignedSmallInteger('stale_minutes')->nullable()->after('is_active');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vehicle_trackers') && Schema::hasColumn('vehicle_trackers', 'stale_minutes')) {
            Schema::table('vehicle_trackers', function (Blueprint $table) {
                $table->dropColumn('stale_minutes');
            });
        }
    }
};
