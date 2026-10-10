<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** v7.9 open / special-order lines that are not on the menu. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_order_items', function (Blueprint $t) {
            $t->boolean('is_open')->default(false);
            $t->string('kitchen_station', 40)->nullable();
            $t->string('food_type', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_items', fn (Blueprint $t) => $t->dropColumn(['is_open', 'kitchen_station', 'food_type']));
    }
};

