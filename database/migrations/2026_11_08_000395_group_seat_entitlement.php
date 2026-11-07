<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v10.2 the second half of the entitlement.
 *
 * A group could already be capped by properties; nothing capped people, so a tenant could staff a
 * thousand properties' worth of accounts against a licence for fifty. max_users is the seat cap: it is
 * enforced where a person is created (the group people screen and a property's own user screen), and
 * the group account prints seats used against seats bought.
 *
 * Null means no cap, exactly like max_properties — an installation that never sets it is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_groups', function (Blueprint $t) {
            if (! Schema::hasColumn('hotel_groups', 'max_users')) {
                $t->unsignedInteger('max_users')->nullable();
            }
            if (! Schema::hasColumn('hotel_groups', 'licence_ends_on')) {
                $t->date('licence_ends_on')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('hotel_groups', function (Blueprint $t) {
            foreach (['max_users', 'licence_ends_on'] as $c) {
                if (Schema::hasColumn('hotel_groups', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
