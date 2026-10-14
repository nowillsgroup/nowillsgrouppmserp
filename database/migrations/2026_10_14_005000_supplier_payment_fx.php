<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v7.54 Paying a foreign supplier bill in the currency it was billed in — and the exchange difference that produces.
 *
 * v7.53 let a bill be booked in the supplier's own currency, converted at the board for its invoice date. Settling it
 * still meant paying cedis: the bank would debit the hotel's dollar or euro account for what the supplier asked for,
 * at the bank's own rate on the day, and the person at the desk had to work out the cedi figure, type it in, and
 * explain the residue. The residue is the point — a bill booked at 11.6458 and settled at 11.7000 costs more cedis
 * than it was booked at, and that difference is a realised exchange loss that belongs in the ledger, not in the
 * Accounts Payable control account, which has to keep agreeing with the sum of the bills.
 *
 * `amount` stays the cedis figure that left the bank, because that is what the payment register, the bank
 * reconciliation and the cash book all read. These columns keep what the bank actually did:
 *
 *  currency   the currency the payment was made in (null when it was made in the hotel currency — the common case)
 *  fx_amount  the amount paid in that currency, as the bank's advice states it
 *  fx_rate    the rate it was converted at — the board for the payment date, or the bank's deal rate when one is typed
 *  fx_date    the date of the board that rate came from (the payment date for a deal rate)
 *  fx_basis   'reference' or 'buy' for a board rate, 'deal' for one taken from the bank's advice
 *  fx_diff    the realised exchange difference: cedis transferred + GHS WHT/WVAT − cedis allocated to the bills it
 *             settles. Positive is a loss, negative a gain, and it is posted to Exchange Gain / Loss (chart key
 *             `forex_gain`, code 4986), never to the AP control — so Dr AP stays exactly what the bills owed
 *
 * A foreign payment must be allocated to the bills it settles and those bills must be in the same currency: an
 * unallocated remainder in a foreign currency is a treasury position, and treasury is not what this release does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payments', function (Blueprint $t) {
            $t->string('currency', 3)->nullable()->after('payment_mode_id');
            $t->decimal('fx_amount', 14, 2)->nullable()->after('amount');
            $t->decimal('fx_rate', 14, 4)->nullable()->after('fx_amount');
            $t->date('fx_date')->nullable()->after('fx_rate');
            $t->string('fx_basis', 12)->nullable()->after('fx_date');
            $t->decimal('fx_diff', 14, 2)->nullable()->after('fx_basis');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payments', function (Blueprint $t) {
            $t->dropColumn(['currency', 'fx_amount', 'fx_rate', 'fx_date', 'fx_basis', 'fx_diff']);
        });
    }
};
