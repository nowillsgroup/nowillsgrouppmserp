<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v10.3 the licence says what the tenant bought.
 *
 * licensed_modules: null means "everything, including modules shipped later" — the default for every
 * group that exists today, so nothing loses a screen on upgrade. Set to a list, the modules that are
 * not in it are refused at the route, not merely hidden from the sidebar.
 *
 * seat_basis: how seats are counted. 'active' (the default) frees a seat the moment somebody is
 * switched off; 'named' counts every account, because a named-user licence is bought per person, not
 * per person-in-work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_groups', function (Blueprint $t) {
            if (! Schema::hasColumn('hotel_groups', 'licensed_modules')) {
                $t->json('licensed_modules')->nullable();
            }
            if (! Schema::hasColumn('hotel_groups', 'seat_basis')) {
                $t->string('seat_basis', 10)->default('active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hotel_groups', function (Blueprint $t) {
            foreach (['licensed_modules', 'seat_basis'] as $c) {
                if (Schema::hasColumn('hotel_groups', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
