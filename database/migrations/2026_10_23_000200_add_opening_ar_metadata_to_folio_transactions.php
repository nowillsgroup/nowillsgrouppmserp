<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Marks cutover receivable balances without replaying their historical GL journals. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folio_transactions', function (Blueprint $table) {
            $table->date('opening_date')->nullable()->after('business_date');
            $table->date('due_date')->nullable()->after('opening_date');
        });
    }

    public function down(): void
    {
        Schema::table('folio_transactions', function (Blueprint $table) {
            $table->dropColumn(['opening_date', 'due_date']);
        });
    }
};
