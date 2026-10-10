<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** v7.8 complimentary dishes and a reason on a complimentary bill. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_order_items', function (Blueprint $t) {
            $t->boolean('is_complimentary')->default(false)->after('is_cancelled');
            $t->string('comp_reason', 120)->nullable()->after('is_complimentary');
            $t->foreignId('comp_by')->nullable()->after('comp_reason')->constrained('users')->nullOnDelete();
            $t->timestamp('comp_at')->nullable()->after('comp_by');
        });
        Schema::table('pos_orders', function (Blueprint $t) {
            $t->string('comp_reason', 120)->nullable()->after('settlement');
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('comp_by');
            $t->dropColumn(['is_complimentary', 'comp_reason', 'comp_at']);
        });
        Schema::table('pos_orders', fn (Blueprint $t) => $t->dropColumn('comp_reason'));
    }
};

