<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A day use is sold by the hour.
     *
     * The booking desk asks how many hours the room is needed for and what those hours
     * cost per hour; the room line keeps both, so the reservation, the guest card and
     * the folio can say what was sold instead of printing a nightly rate. The charge
     * itself stays in `rate` — hours × hourly rate, posted once, exactly like every
     * other day use the front desk already takes.
     *
     * Nothing is backfilled: an overnight stay has no hours and no hourly rate.
     */
    public function up(): void
    {
        if (! Schema::hasTable('reservation_rooms')) {
            return;
        }

        Schema::table('reservation_rooms', function (Blueprint $table) {
            if (! Schema::hasColumn('reservation_rooms', 'day_use_hours')) {
                $table->unsignedSmallInteger('day_use_hours')->nullable()->after('day_use_until');
            }
            if (! Schema::hasColumn('reservation_rooms', 'day_use_rate')) {
                $table->decimal('day_use_rate', 12, 2)->nullable()->after('day_use_hours');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('reservation_rooms')) {
            return;
        }

        Schema::table('reservation_rooms', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('reservation_rooms', 'day_use_hours') ? 'day_use_hours' : null,
                Schema::hasColumn('reservation_rooms', 'day_use_rate') ? 'day_use_rate' : null,
            ]));
            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};
