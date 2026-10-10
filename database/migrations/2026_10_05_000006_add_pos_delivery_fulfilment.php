<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $t) {
            $t->string('delivery_address', 255)->nullable()->after('customer_phone');
            $t->string('delivery_status', 24)->default('not_applicable')->after('delivery_address');
            $t->decimal('delivery_fee', 12, 2)->default(0)->after('delivery_status');
            $t->string('delivery_notes', 190)->nullable()->after('delivery_fee');
            $t->foreignId('delivery_driver_id')->nullable()->after('delivery_notes')->constrained('drivers')->nullOnDelete();
            $t->timestamp('delivered_at')->nullable()->after('delivery_driver_id');
            $t->index(['hotel_id', 'order_type', 'delivery_status']);
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $t) {
            $t->dropForeign(['delivery_driver_id']);
            $t->dropIndex(['hotel_id', 'order_type', 'delivery_status']);
            $t->dropColumn(['delivery_address', 'delivery_status', 'delivery_fee', 'delivery_notes', 'delivery_driver_id', 'delivered_at']);
        });
    }
};
