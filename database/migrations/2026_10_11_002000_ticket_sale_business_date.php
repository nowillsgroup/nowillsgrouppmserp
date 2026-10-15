<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v7.38 Recreation outlet accounting — the sale's business day, so the night
 * audit and income audit count counter takings on the right day (a sale rung
 * up after midnight but before the audit roll belongs to the old day).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_ticket_sales', function (Blueprint $t) {
            $t->date('business_date')->nullable()->after('sold_at');
        });
        DB::statement("UPDATE club_ticket_sales SET business_date = date(sold_at) WHERE business_date IS NULL");
        Schema::table('club_ticket_sales', function (Blueprint $t) {
            $t->index(['hotel_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::table('club_ticket_sales', function (Blueprint $t) {
            $t->dropIndex(['hotel_id', 'business_date']);
            $t->dropColumn('business_date');
        });
    }
};
