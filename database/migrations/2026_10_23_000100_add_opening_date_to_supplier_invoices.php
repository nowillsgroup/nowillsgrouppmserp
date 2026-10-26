<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cutover AP snapshot items carry their own as-of date and never replay historical GL. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->date('opening_date')->nullable()->after('doc_date');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropColumn('opening_date');
        });
    }
};
