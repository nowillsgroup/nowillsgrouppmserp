<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** v7.10 hold-and-fire: a sent dish can wait until the captain releases it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_order_items', function (Blueprint $t) {
            $t->timestamp('fired_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_items', fn (Blueprint $t) => $t->dropColumn('fired_at'));
    }
};

