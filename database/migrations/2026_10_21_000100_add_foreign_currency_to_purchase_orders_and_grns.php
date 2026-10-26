<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foreign-currency purchase orders and goods receipts, with the local-currency stock/GRN value retained.
 * Existing rows remain home-currency documents (all new FX columns are nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->string('currency', 3)->nullable()->after('store_id');
            $t->decimal('fx_subtotal', 14, 2)->nullable()->after('total');
            $t->decimal('fx_tax', 14, 2)->nullable()->after('fx_subtotal');
            $t->decimal('fx_total', 14, 2)->nullable()->after('fx_tax');
            $t->decimal('fx_rate', 14, 4)->nullable()->after('fx_total');
            $t->date('fx_date')->nullable()->after('fx_rate');
            $t->string('fx_basis', 12)->nullable()->after('fx_date');
        });

        Schema::table('purchase_order_items', function (Blueprint $t) {
            $t->decimal('fx_unit_rate', 14, 4)->nullable()->after('rate');
            $t->decimal('fx_amount', 14, 2)->nullable()->after('amount');
            $t->decimal('fx_tax_amount', 14, 2)->nullable()->after('fx_amount');
        });

        Schema::table('goods_receipts', function (Blueprint $t) {
            $t->string('currency', 3)->nullable()->after('store_id');
            $t->decimal('fx_rate', 14, 4)->nullable()->after('total');
            $t->date('fx_date')->nullable()->after('fx_rate');
            $t->string('fx_basis', 12)->nullable()->after('fx_date');
            $t->decimal('fx_subtotal', 14, 2)->nullable()->after('fx_basis');
            $t->decimal('fx_tax', 14, 2)->nullable()->after('fx_subtotal');
            $t->decimal('fx_total', 14, 2)->nullable()->after('fx_tax');
            $t->decimal('fx_rejected_value', 14, 2)->nullable()->after('rejected_value');
        });

        Schema::table('goods_receipt_lines', function (Blueprint $t) {
            $t->decimal('fx_unit_rate', 14, 4)->nullable()->after('rate');
            $t->decimal('fx_amount', 14, 2)->nullable()->after('amount');
            $t->decimal('fx_tax_amount', 14, 2)->nullable()->after('fx_amount');
        });

        Schema::table('supplier_invoices', function (Blueprint $t) {
            $t->decimal('grn_fx_subtotal', 14, 2)->nullable()->after('grn_total');
            $t->decimal('grn_fx_tax', 14, 2)->nullable()->after('grn_fx_subtotal');
            $t->decimal('grn_fx_total', 14, 2)->nullable()->after('grn_fx_tax');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_invoices', fn (Blueprint $t) => $t->dropColumn(['grn_fx_subtotal', 'grn_fx_tax', 'grn_fx_total']));
        Schema::table('goods_receipt_lines', fn (Blueprint $t) => $t->dropColumn(['fx_unit_rate', 'fx_amount', 'fx_tax_amount']));
        Schema::table('goods_receipts', fn (Blueprint $t) => $t->dropColumn(['currency', 'fx_rate', 'fx_date', 'fx_basis', 'fx_subtotal', 'fx_tax', 'fx_total', 'fx_rejected_value']));
        Schema::table('purchase_order_items', fn (Blueprint $t) => $t->dropColumn(['fx_unit_rate', 'fx_amount', 'fx_tax_amount']));
        Schema::table('purchase_orders', fn (Blueprint $t) => $t->dropColumn(['currency', 'fx_subtotal', 'fx_tax', 'fx_total', 'fx_rate', 'fx_date', 'fx_basis']));
    }
};
