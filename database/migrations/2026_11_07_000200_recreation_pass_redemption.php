<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admitting a ticket at the gate.
 *
 * The outlet sells entry tickets and prints a stub per pass, but until now nothing recorded
 * that the visitor actually came in — a pass could be shown twice and the day report could
 * not say how many admissions the counter's takings bought. These columns record the gate
 * check (when, by whom, how many pax) against the pass that was sold.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('club_day_passes')) {
            return;
        }

        Schema::table('club_day_passes', function (Blueprint $t) {
            if (! Schema::hasColumn('club_day_passes', 'redeemed_at')) {
                $t->dateTime('redeemed_at')->nullable()->after('cancelled_at');
            }
            if (! Schema::hasColumn('club_day_passes', 'redeemed_by')) {
                $t->foreignId('redeemed_by')->nullable()->after('redeemed_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('club_day_passes', 'redeemed_pax')) {
                $t->unsignedSmallInteger('redeemed_pax')->nullable()->after('redeemed_by');
            }
            if (! Schema::hasColumn('club_day_passes', 'redeemed_note')) {
                $t->string('redeemed_note', 120)->nullable()->after('redeemed_pax');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('club_day_passes')) {
            return;
        }

        Schema::table('club_day_passes', function (Blueprint $t) {
            foreach (['redeemed_note', 'redeemed_pax', 'redeemed_by', 'redeemed_at'] as $column) {
                if (Schema::hasColumn('club_day_passes', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};
