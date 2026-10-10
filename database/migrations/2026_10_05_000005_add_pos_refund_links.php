<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_payments', function (Blueprint $t) {
            $t->foreignId('refund_of_id')->nullable()->after('pos_order_id')->constrained('pos_payments')->nullOnDelete();
            $t->index(['pos_order_id', 'refund_of_id']);
        });
    }

    public function down(): void
    {
        Schema::table('pos_payments', function (Blueprint $t) {
            $t->dropForeign(['refund_of_id']);
            $t->dropIndex(['pos_order_id', 'refund_of_id']);
            $t->dropColumn('refund_of_id');
        });
    }
};
