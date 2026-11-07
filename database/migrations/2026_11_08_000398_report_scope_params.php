<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A saved report remembers what it was showing, not only when.
 *
 * v10.4 gave a view a report, a date range, columns and a text filter. That is enough for a report
 * whose whole state is its period — a trial balance is a trial balance. It is not enough for the
 * consolidated report, whose figures answer for a *slice* of the portfolio: "Takoradi, month to
 * date" could not be saved, so the person who reads it every Monday rebuilt it every Monday, and a
 * schedule of it e-mailed the whole group instead of the city it was named after.
 *
 * `params` holds the report's own parameters (a slice dimension and value) as JSON, so the shape of
 * a slice is the report's business and not this table's. A schedule copies them from its view, or
 * carries its own when it has no view; both are read by ReportController::param() at run time,
 * whether the run came from a browser or from the scheduler.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_views', function (Blueprint $t) {
            if (! Schema::hasColumn('report_views', 'params')) {
                $t->json('params')->nullable();
            }
        });

        Schema::table('report_schedules', function (Blueprint $t) {
            if (! Schema::hasColumn('report_schedules', 'params')) {
                $t->json('params')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('report_views', function (Blueprint $t) {
            if (Schema::hasColumn('report_views', 'params')) {
                $t->dropColumn('params');
            }
        });

        Schema::table('report_schedules', function (Blueprint $t) {
            if (Schema::hasColumn('report_schedules', 'params')) {
                $t->dropColumn('params');
            }
        });
    }
};
