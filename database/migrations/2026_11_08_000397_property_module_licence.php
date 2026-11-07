<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v10.5 a licence is bought for a group, but a module is used at a property.
 *
 * A group that owns a spa buys "Spa & Activities" once; only the beach property actually runs one.
 * Until now the group licence was the only licence, so switching a module off for one property meant
 * switching it off for the whole group — and a group administrator with no spa had no way to say so.
 *
 * hotels.licensed_modules: null (the default) means "follow the group licence", so this release
 * changes nothing for any existing property. A list narrows the group's list for that one property.
 * It can never widen it: LicenceService intersects the two, so a property can be licensed for less
 * than its group and never for more — widening is a commercial decision, taken on the group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $t) {
            if (! Schema::hasColumn('hotels', 'licensed_modules')) {
                $t->json('licensed_modules')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $t) {
            if (Schema::hasColumn('hotels', 'licensed_modules')) {
                $t->dropColumn('licensed_modules');
            }
        });
    }
};
