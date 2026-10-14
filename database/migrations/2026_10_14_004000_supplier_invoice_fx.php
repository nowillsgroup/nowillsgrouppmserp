<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7.53 Supplier invoices in a foreign currency — the audit trail of a conversion.
 *
 * A hotel in Accra is billed in dollars and euros all the time: an OTA's commission invoice, a booking engine, a
 * franchise or management fee, a foreign consultant, an overseas repair warranty. Until now `supplier_invoices` had no
 * currency at all, so the only way to record one was to convert it by hand, on a calculator, at whatever rate whoever
 * keyed it chose — and nothing on the bill said which rate that had been. v7.50 gave a channel booking the board rate
 * and kept the channel's own figure beside it; this does the same on the payables side, which is what the gap audit
 * still had open.
 *
 * `subtotal`, `tax` and `total` stay in the hotel currency, because that is what the ledger posts, what the budget
 * check measures, what ageing and allocations sum and what a payment settles. These columns keep the supplier's own
 * document, so a bill can always be read back the way it arrived and re-valued when the rate moves:
 *
 *  currency     the code the supplier billed in (null when it billed in the hotel currency — the common case)
 *  fx_subtotal  the supplier's own net, fx_tax its own tax, fx_total its own document total, in that currency
 *  fx_rate      the board rate it was converted at, on the basis the forex settings choose
 *  fx_date      the date of the board that rate came from
 *  fx_basis     which side of the board it was — 'reference' or 'buy'
 *
 * Lines are stored converted, so the sum of the lines always equals the header the ledger posts; the difference
 * between that and `fx_total * fx_rate` is rounding, and ApService reports it rather than hiding it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $t) {
            $t->string('currency', 3)->nullable()->after('supplier_ref');
            $t->decimal('fx_subtotal', 14, 2)->nullable()->after('total');
            $t->decimal('fx_tax', 14, 2)->nullable()->after('fx_subtotal');
            $t->decimal('fx_total', 14, 2)->nullable()->after('fx_tax');
            $t->decimal('fx_rate', 14, 4)->nullable()->after('fx_total');
            $t->date('fx_date')->nullable()->after('fx_rate');
            $t->string('fx_basis', 12)->nullable()->after('fx_date');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $t) {
            $t->dropColumn(['currency', 'fx_subtotal', 'fx_tax', 'fx_total', 'fx_rate', 'fx_date', 'fx_basis']);
        });
    }
};
