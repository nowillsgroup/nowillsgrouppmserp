<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v7.78 offline ordering.
     *
     * A waiter who opens a table while the connection is down gets a reference
     * generated on the device. Every ticket that follows in the same sitting
     * carries the same reference, so the first queued operation creates the order
     * and the rest attach to it — on the server, in queue order, without the
     * device having to learn the order id first.
     */
    public function up(): void
    {
        if (! Schema::hasTable('pos_orders') || Schema::hasColumn('pos_orders', 'offline_ref')) {
            return;
        }

        Schema::table('pos_orders', function (Blueprint $table) {
            $table->string('offline_ref', 64)->nullable()->after('order_no');
            $table->index(['hotel_id', 'offline_ref'], 'pos_orders_hotel_offline_ref_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pos_orders', 'offline_ref')) {
            return;
        }

        Schema::table('pos_orders', function (Blueprint $table) {
            $table->dropIndex('pos_orders_hotel_offline_ref_index');
            $table->dropColumn('offline_ref');
        });
    }
};
