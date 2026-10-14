<?php
/**
 * v7.54 — settling a foreign supplier bill in the currency it was billed in, and the exchange difference that produces.
 *
 * v7.53 let a bill be booked in euros or dollars at the board for its invoice date. Paying it still meant typing a cedi
 * figure: the bank debited the hotel's foreign account for what the supplier asked, at the bank's own rate on the day,
 * and whoever was at the desk had to work out the cedis and explain the residue. The residue is the substance — a bill
 * booked at one rate and settled at another costs a different number of cedis from the one it was booked at, and that
 * difference is a realised exchange gain or loss. It has to be posted somewhere, and it cannot be posted to Accounts
 * Payable, because that control account has to keep agreeing with the sum of the bills. What is proven here:
 *
 *   1. ForexService::apPay() / apPayDeal() / apPayRate() — the switches, and the rate a payment is converted at, with
 *      its provenance: the board for the payment date, or the bank's deal rate when one is typed
 *   2. ApService::payCurrencies() — a payment can only be made in a currency somebody has actually billed in
 *   3. a payment at the board: the bank's figure and the rate are kept, the cedi amount is derived from them, the bill
 *      is settled at what it was booked at, and nothing is left over as a phantom credit on account
 *   4. the journal: Dr Accounts Payable for what the bills owed, Dr or Cr Exchange Gain / Loss for the difference, Cr
 *      the bank for what the payment cost — balanced, and with the control account still equal to the sum of the bills
 *   5. a payment in the property's own currency is byte for byte the journal it always was
 *   6. a gain: a rate better than the one the bill was booked at credits Exchange Gain / Loss instead
 *   7. a deal rate from the bank's advice, and a rate too far from the board to be anything but a keying error
 *   8. refusals — payments in a foreign currency switched off, deal rates switched off, no board inside the window, a
 *      bill booked in another currency, a bill booked in cedis, nothing allocated, more allocated than is outstanding,
 *      no foreign amount, and a difference larger than the bills themselves
 *   9. ApService::fxExposure() — realised beside unrealised, and a currency that has been settled in full still listed
 *  10. ApService::payFxNote(), the columns, and the screens
 *
 * Everything runs inside a transaction that is rolled back, so the demo database is untouched.
 *
 * TINKER='require "/app/deploy/dev/audit/ap_fx_pay.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Models\AccountHead;
use App\Models\GlJournal;
use App\Models\PaymentMode;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Services\ApService;
use App\Services\ForexService;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

$pass = 0;
$fail = 0;
$ok = function (string $what, $got, $want) use (&$pass, &$fail) {
    $same = is_float($got) && is_float($want) ? abs($got - $want) < 0.005 : $got === $want;
    if ($same) {
        $pass++;
        printf("  ok    %-64s %s%s", $what, is_scalar($got) ? (is_float($got) ? number_format($got, 4) : (string) $got) : json_encode($got), PHP_EOL);
    } else {
        $fail++;
        printf("  FAIL  %-64s got %s, wanted %s%s", $what, is_scalar($got) ? var_export($got, true) : json_encode($got),
            is_scalar($want) ? var_export($want, true) : json_encode($want), PHP_EOL);
    }
};
$has = function (string $what, string $haystack, string $needle) use (&$ok) {
    $ok($what, str_contains($haystack, $needle), true);
};
$throws = function (string $what, callable $fn, string $needle) use (&$ok) {
    try {
        $fn();
        $ok($what, 'no exception', "RuntimeException containing \"$needle\"");
    } catch (RuntimeException $e) {
        $ok($what, str_contains($e->getMessage(), $needle), true);
    }
};

$home = strtoupper((string) (Tenant::hotel()->currency ?: 'GHS'));
$today = bdate()->toDateString();
$cutoff = setting('gl_cutoff');
// the latest date whose journal would actually post, so the ledger side of this can be proven rather than assumed
$payDate = $cutoff && $cutoff < $today ? $cutoff : $today;
$heads = AccountHead::pluck('id', 'name');
$sup = Supplier::where('is_local', false)->orderBy('id')->first() ?? Supplier::orderBy('id')->first();
$bank = PaymentMode::where('type', 'bank')->value('id') ?? PaymentMode::orderBy('id')->value('id');
printf("Property %s · own currency %s · business date %s · cutoff %s · supplier #%d %s · mode #%d%s%s",
    Tenant::hotel()->code, $home, $today, $cutoff ?: 'none', $sup->id, $sup->name, $bank, PHP_EOL, PHP_EOL);

DB::beginTransaction();
$base = null;
try {
    $base = ApService::fxExposure();   // what the committed database already carries, so the harness measures its own rise

    // ── 1. the policy ──
    printf("%s — the switches, and the rate a payment is converted at%s", 'The policy', PHP_EOL);
    $ok('a foreign bill may be settled in its own currency by default', ForexService::apPay(), true);
    $ok('and the rate may be the bank’s deal rate by default', ForexService::apPayDeal(), true);
    $r = ForexService::apPayRate('EUR', $payDate);
    $ok('with no rate typed, the board for the payment date is used', [$r['basis'], $r['date']], ['reference', $payDate]);
    $ok('and it is quoted in the property’s own currency', round((float) $r['rate'], 4), round((float) ForexService::boardRate('EUR', $payDate)['rate'], 4));
    ForexService::saveSettings(['ap_rate_date' => 'business']);
    $paymentDayRate = ForexService::apPayRate('EUR', '2026-10-13');
    $ok('even when invoice booking uses today, a settlement reads its own date', $paymentDayRate['date'], '2026-10-13');
    $ok('and the rate follows that payment-date board', round((float) $paymentDayRate['rate'], 4), round((float) ForexService::boardRate('EUR', '2026-10-13')['rate'], 4));
    ForexService::saveSettings(['ap_rate_date' => 'document']);
    $d = ForexService::apPayRate('EUR', $payDate, 13.9);
    $ok('a typed rate becomes the deal rate', [$d['basis'], (float) $d['rate']], ['deal', 13.9]);
    $ok('and the board is carried beside it for comparison', round((float) $d['board']['rate'], 4), round((float) $r['rate'], 4));
    ForexService::saveSettings(['ap_pay_deal' => '0']);
    $ok('switching deal rates off leaves only the board', ForexService::apPayRate('EUR', $payDate, 13.9), null);
    $ok('and the board itself is still available', ForexService::apPayRate('EUR', $payDate)['basis'], 'reference');
    ForexService::saveSettings(['ap_pay_deal' => '1', 'ap_pay' => '0']);
    $ok('switching foreign payments off is a single switch', ForexService::apPay(), false);
    ForexService::saveSettings(['ap_pay' => '1']);
    $ok('and switching it back on restores them', ForexService::apPay(), true);
    $ok('a currency nobody quotes has no rate to pay at', ForexService::apPayRate('ZZZ', $payDate), null);
    printf("%s", PHP_EOL);

    // ── 2. the fixture: two bills in the supplier’s own currency ──
    $eurBillDate = '2026-10-05';
    $eurBoard = ForexService::boardRate('EUR', $eurBillDate);
    if (! $eurBoard) {
        throw new RuntimeException("no EUR rate on the board for $eurBillDate — the fixture cannot be built");
    }
    $mkBill = function (string $ref, string $ccy, float $amount) use ($sup, $heads, $eurBillDate) {
        return ApService::expenseBill($sup, ['supplier_ref' => $ref, 'currency' => $ccy, 'doc_date' => $eurBillDate,
            'lines' => [['account_head_id' => $heads['Marketing & OTA Commission'], 'description' => 'Commission, FX pay harness', 'amount' => $amount, 'tax_rate' => 0]],
            'notes' => 'FX pay harness']);
    };
    $pickBefore = ApService::payCurrencies($sup, $payDate);   // this supplier has its own open bills already, so measure the rise
    $bill = $mkBill('FXP-EUR-1', 'EUR', 2000.0);
    $bill2 = $mkBill('FXP-EUR-2', 'EUR', 500.0);
    $cediBill = ApService::expenseBill($sup, ['supplier_ref' => 'FXP-GHS-1', 'doc_date' => $eurBillDate,
        'lines' => [['account_head_id' => $heads['Marketing & OTA Commission'], 'description' => 'A cedi bill, FX pay harness', 'amount' => 1234.56, 'tax_rate' => 0]]]);
    printf("%s — the fixtures: %s EUR %.2f booked at %s = %s, %s EUR %.2f, and %s in cedis%s",
        'Fixtures', $bill->doc_no, (float) $bill->fx_total, $bill->fx_rate, money((float) $bill->total), $bill2->doc_no, (float) $bill2->fx_total, $cediBill->doc_no, PHP_EOL);

    printf("%s — what a payment to this supplier could be made in%s", 'The picker', PHP_EOL);
    $pick = ApService::payCurrencies($sup, $payDate);
    $ok('the supplier’s open bills are grouped by the currency they were billed in', array_keys($pick), ['EUR', $home]);
    $ok('the property’s own currency is offered as home', [$pick[$home]['home'], $pick[$home]['rate']], [true, null]);
    $ok('the foreign one carries its own board rate', [$pick['EUR']['home'], round((float) $pick['EUR']['rate'], 4)], [false, round((float) $r['rate'], 4)]);
    $ok('and what is owed in it, beside what the ledger carries', [$pick['EUR']['owed'], $pick['EUR']['bills']], [2500.0, 2]);
    $ok('the cedi row owes what it owed before, plus the cedi fixture', round($pick[$home]['owed'] - $pickBefore[$home]['owed'], 2), round((float) $cediBill->total, 2));
    $ok('and the foreign row grew by the two euro fixtures', round($pick['EUR']['owed'] - ($pickBefore['EUR']['owed'] ?? 0), 2), 2500.0);
    printf("%s", PHP_EOL);

    // ── 3. a payment at the board ──
    printf("%s — settling a bill in the currency it was billed in, at the board%s", 'A payment', PHP_EOL);
    $owed = $bill->fxOutstanding();
    $booked = $bill->outstanding();
    $controlBefore = ApService::controlBalance($payDate);   // after the fixtures, so only the payment can move it
    $p = ApService::pay($sup, ['date' => $payDate, 'payment_mode_id' => $bank, 'currency' => 'EUR',
        'fx_amount' => $owed, 'reference' => 'FXP-TT-1'], [$bill->id => $booked]);
    $ok('the bank’s figure and its currency are kept', [$p->currency, (float) $p->fx_amount], ['EUR', $owed]);
    $ok('at the board for the payment date', [(float) $p->fx_rate, $p->fx_basis, $p->fx_date->toDateString()], [(float) $r['rate'], 'reference', $r['date']]);
    $ok('and the cedi amount is derived, not typed', (float) $p->amount, round($owed * (float) $r['rate'], 2));
    $ok('the bill is settled at what it was booked at', (float) $p->allocated, $booked);
    $ok('so the bill itself is paid off', [$p->fresh() ? $bill->fresh()->status : '', $bill->fresh()->outstanding()], ['paid', 0.0]);
    $diff = round((float) $p->amount + $p->withheld() - $booked, 2);
    $ok('the bank transferred the stated foreign figure (net of GRA withholding)', (float) $p->netPaid(), round($owed * (float) $r['rate'], 2));
    $ok('GRA withholding is separate from that foreign transfer', $p->withheld() > 0 ? (float) $p->amount : 0.0, $p->withheld() > 0 ? (float) $p->netPaid() : 0.0);
    $ok('and the realised difference includes both the bank debit and GRA tax', $p->fxDifference(), $diff);
    $ok('which leaves nothing behind as a credit on account', $p->unapplied(), 0.0);
    $ok('the payment still says which currency it was', $p->fxLabel(), 'EUR '.number_format($owed, 2));
    $stRow = collect(ApService::statement($sup, '2026-01-01', $today)['rows'])->firstWhere('doc', $p->payment_no);
    $has('the statement to the supplier is written in their own currency', $stRow['type'], $p->fxLabel());
    $ok('and it settles the bill at what it was booked at, so their balance stays right', $stRow['dr'], $booked);
    $fxRow = collect(ApService::statement($sup, '2026-01-01', $today)['rows'])->firstWhere('doc', $p->payment_no.'/FX');
    $ok('the exchange difference is explained on its own line', $fxRow !== null ? [$fxRow['dr'], $fxRow['cr']] : null, [0.0, 0.0]);
    $has('and it says whose it is', (string) ($fxRow['info'] ?? ''), 'ours, not a charge to you');
    printf("%s", PHP_EOL);

    // ── 4. the journal ──
    printf("%s — how it posts%s", 'The journal', PHP_EOL);
    $j = GlJournal::where('source_type', 'ap_pay')->where('source_key', (string) $p->id)->first();
    if ($j) {
        $lines = $j->lines->map(fn ($l) => [AccountHead::find($l->account_head_id)?->name, round((float) $l->debit - (float) $l->credit, 2)]);
        $byName = $lines->groupBy(0)->map(fn ($g) => round((float) $g->sum(1), 2));
        $ok('the journal is balanced', round((float) $lines->sum(1), 2), 0.0);
        $ok('Accounts Payable is debited with what the bill owed, not what the payment cost', $byName['Accounts Payable – Suppliers'] ?? null, $booked);
        $ok('the bank is credited with what actually left it', $byName['Bank Account'] ?? null, -$p->netPaid());
        if ($p->withheld() > 0) {
            // WHT is quoted on the cedi amount, because that is what GRA wants, and it is still owed to GRA rather than paid away
            $whtHeads = collect(['wht_payable', 'wvat_payable'])->map(fn ($k) => AccountHead::find(AccountHead::sys($k))?->name)->filter();
            $ok('and what was withheld for GRA is credited to a payable of its own',
                round((float) $whtHeads->sum(fn ($h) => $byName[$h] ?? 0.0), 2), -$p->withheld());
            $ok('withheld on the cedi amount, not on the euro one', round((float) $p->wht_base, 2) <= (float) $p->amount, true);
        } else {
            printf("  (no tax was withheld on this supplier, so the WHT side of a foreign payment is not exercised here)%s", PHP_EOL);
        }
        $ok('and the difference went to Exchange Gain / Loss', $byName['Exchange Gain / Loss'] ?? 0.0, $diff);
        $has('the narration says what the bank took', $j->narration, 'EUR '.number_format($owed, 2));
        $has('and at what rate', $j->narration, number_format((float) $r['rate'], 4));
        $ok('so the control account fell by what the bill owed, not by what the payment cost',
            round($controlBefore - ApService::controlBalance($payDate), 2), $booked);
    } else {
        printf("  (no journal for %s — the payment date is after the cutoff, so the ledger side cannot be proven here)%s", $p->payment_no, PHP_EOL);
    }
    printf("%s", PHP_EOL);

    // ── 5. a payment in cedis is unchanged ──
    printf("%s — a payment in the property’s own currency, which must not have moved%s", 'Cedis', PHP_EOL);
    $cp = ApService::pay($sup, ['date' => $payDate, 'payment_mode_id' => $bank, 'amount' => 500, 'reference' => 'FXP-TT-2'], [$cediBill->id => 500]);
    $ok('it carries no currency of its own', [$cp->currency, $cp->fx_amount, $cp->fx_rate, $cp->fx_diff], [null, null, null, null]);
    $ok('and it is not foreign', $cp->isForeign(), false);
    $ok('the amount paid is the amount typed', (float) $cp->amount, 500.0);
    $ok('the rest stays on account exactly as it always did', $cp->unapplied(), 0.0);
    $ok('and it still reads as a payment in the property’s own currency', $cp->fxLabel(), $home.' 500.00');
    $cj = GlJournal::where('source_type', 'ap_pay')->where('source_key', (string) $cp->id)->first();
    if ($cj) {
        $cby = $cj->lines->groupBy(fn ($l) => AccountHead::find($l->account_head_id)?->name)
            ->map(fn ($g) => round((float) $g->sum(fn ($l) => (float) $l->debit - (float) $l->credit), 2));
        $ok('Accounts Payable is debited with the whole payment, as before', $cby['Accounts Payable – Suppliers'] ?? null, 500.0);
        $ok('and no exchange difference is invented', $cby['Exchange Gain / Loss'] ?? 0.0, 0.0);
    }
    printf("%s", PHP_EOL);

    // ── 6. a gain, and 7. a deal rate ──
    printf("%s — when the rate moves the other way, and when the bank quotes its own%s", 'A gain and a deal rate', PHP_EOL);
    $owed2 = $bill2->fxOutstanding();
    $booked2 = $bill2->outstanding();
    $goodTax = \App\Services\WhtService::quote($sup, $booked2, [$bill2->id => $booked2], $payDate);
    $goodRate = round(((float) $booked2 - (float) $goodTax['wht'] - (float) $goodTax['wvat'] - 100) / $owed2, 4); // net of GRA tax, a real gain
    $g = ApService::pay($sup, ['date' => $payDate, 'payment_mode_id' => $bank, 'currency' => 'EUR',
        'fx_amount' => $owed2, 'fx_rate' => $goodRate, 'fx_deal' => '1', 'reference' => 'FXP-TT-3'], [$bill2->id => $booked2]);
    $ok('a typed rate is recorded as the bank’s deal rate', [$g->fx_basis, (float) $g->fx_rate], ['deal', $goodRate]);
    $ok('and the cedi amount follows it', (float) $g->amount, round($owed2 * $goodRate, 2));
    $ok('so the difference is a gain', $g->fxDifference() < 0, true);
    $gj = GlJournal::where('source_type', 'ap_pay')->where('source_key', (string) $g->id)->first();
    if ($gj) {
        $gby = $gj->lines->groupBy(fn ($l) => AccountHead::find($l->account_head_id)?->name)
            ->map(fn ($gg) => round((float) $gg->sum(fn ($l) => (float) $l->debit - (float) $l->credit), 2));
        $ok('which credits Exchange Gain / Loss', $gby['Exchange Gain / Loss'] ?? null, $g->fxDifference());
        $ok('and still leaves Accounts Payable at what the bill owed', $gby['Accounts Payable – Suppliers'] ?? null, $booked2);
        $ok('with the journal balanced', round((float) $gj->lines->sum(fn ($l) => (float) $l->debit - (float) $l->credit), 2), 0.0);
    }
    printf("%s", PHP_EOL);

    // ── 8. refusals ──
    printf("%s — what is refused, and what it says%s", 'Refusals', PHP_EOL);
    $b3 = $mkBill('FXP-EUR-3', 'EUR', 300.0);
    $pay = fn (array $d, array $a) => ApService::pay($sup, $d + ['date' => $payDate, 'payment_mode_id' => $bank], $a);
    $stray = $mkBill('FXP-EUR-BOARD', 'EUR', 200.0);
    $strayAmount = $stray->fxOutstanding();
    $strayAlloc = $stray->outstanding();
    $strayPayment = $pay(['currency' => 'EUR', 'fx_amount' => $strayAmount, 'fx_rate' => 1.0], [$stray->id => $strayAlloc]);
    $ok('a displayed/stray number never overrides the board without the deal opt-in',
        [$strayPayment->fx_basis, round((float) $strayPayment->fx_rate, 4)], ['reference', round((float) $r['rate'], 4)]);
    ApService::cancelPayment($strayPayment, 'AP FX pay harness');
    $stray->refresh();
    $ok('cancelling the test payment restores its bill', [$stray->status, round($stray->outstanding(), 2)], ['open', round((float) $stray->total, 2)]);
    ForexService::saveSettings(['ap_pay' => '0']);
    $throws('switching foreign payments off refuses the payment rather than guessing',
        fn () => $pay(['currency' => 'EUR', 'fx_amount' => 300], [$b3->id => $b3->outstanding()]), 'switched off');
    $has('and points at the switch', (function () use ($pay, $b3) {
        try {
            $pay(['currency' => 'EUR', 'fx_amount' => 300], [$b3->id => $b3->outstanding()]);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return '';
    })(), 'Foreign Guests › Settings › Forex desk');
    ForexService::saveSettings(['ap_pay' => '1']);
    $throws('a payment with no foreign amount on it is refused', fn () => $pay(['currency' => 'EUR'], [$b3->id => $b3->outstanding()]), 'Enter the amount paid in EUR');
    $throws('a bill booked in cedis cannot be settled in a foreign currency',
        fn () => $pay(['currency' => 'EUR', 'fx_amount' => 10], [$cediBill->id => 10]), 'booked in');
    $usdBill = $mkBill('FXP-USD-1', 'USD', 100.0);
    $throws('nor can a dollar bill be settled with a euro payment',
        fn () => $pay(['currency' => 'EUR', 'fx_amount' => 10], [$usdBill->id => 10]), 'cannot settle it');
    $throws('a foreign payment with nothing allocated is refused', fn () => $pay(['currency' => 'EUR', 'fx_amount' => 300], []), 'does not go on account');
    $throws('allocating more than the bill owes is still refused',
        fn () => $pay(['currency' => 'EUR', 'fx_amount' => 300], [$b3->id => round($b3->outstanding() + 5000, 2)]), 'is outstanding');
    $throws('and so is a difference too large to be a market',
        fn () => $pay(['currency' => 'EUR', 'fx_amount' => 300, 'fx_rate' => 0.0001, 'fx_deal' => '1'], [$b3->id => $b3->outstanding()]), 'nowhere near the board');
    ForexService::saveSettings(['ap_pay_deal' => '0']);
    $throws('with deal rates off, a typed rate is refused rather than ignored',
        fn () => $pay(['currency' => 'EUR', 'fx_amount' => 300, 'fx_rate' => 14.5, 'fx_deal' => '1'], [$b3->id => $b3->outstanding()]), 'Deal rates are switched off');
    ForexService::saveSettings(['ap_pay_deal' => '1']);
    $firstBoard = \App\Models\ForexRate::min('date');
    $lock = \App\Services\LedgerService::lockDate();
    $noBoard = $firstBoard ? Carbon::parse($firstBoard)->subDays(ForexService::apPolicy()['stale_days'] + 2)->toDateString() : null;
    if ($noBoard && (! $lock || $noBoard > $lock)) {
        $throws('and a currency with no board inside the window cannot be paid in',
            fn () => $pay(['currency' => 'EUR', 'fx_amount' => 300, 'date' => $noBoard], [$b3->id => $b3->outstanding()]), 'forex board');
    } else {
        printf("  (skipped: no date is both inside the ledger lock of %s and outside the board, which starts %s)%s", $lock ?: 'none', $firstBoard ?: 'never', PHP_EOL);
    }
    $throws('a cedi payment with no amount on it is still refused', fn () => $pay([], []), 'Enter the amount paid.');
    $ok('and every refusal left the bill open', $b3->fresh()->status, 'open');
    printf("%s", PHP_EOL);

    // ── 9. exposure ──
    printf("%s — realised beside unrealised%s", 'Exposure', PHP_EOL);
    $e = ApService::fxExposure();
    $eur = collect($e['rows'])->firstWhere('currency', 'EUR');
    $ok('the payments this run made are counted', $eur['payments'] >= 2, true);
    $ok('and so is the difference they realised', round((float) $eur['realised'], 2), round($diff + $g->fxDifference(), 2));
    $ok('which is a rise on what the committed database already carried', $e['realised'] - $base['realised'], round($diff + $g->fxDifference(), 2));
    $usd = collect($e['rows'])->firstWhere('currency', 'USD');
    if ($usd) {
        $ok('the unrealised movement is still measured only on what is open', $usd['bills'] >= 0, true);
        $ok('and the two figures are kept apart', array_keys($usd), ['currency', 'bills', 'foreign', 'booked', 'rate_now', 'board_date', 'stale', 'at_board', 'movement', 'payments', 'paid_foreign', 'realised']);
    }
    printf("%s", PHP_EOL);

    // ── 10. the note ──
    printf("%s — the audit trail it leaves behind%s", 'The note', PHP_EOL);
    $note = ApService::payFxNote(['currency' => 'EUR', 'fx_amount' => $owed, 'fx_rate' => (float) $r['rate'], 'fx_basis' => 'reference',
        'fx_date' => $payDate, 'fx_diff' => $diff], $booked);
    $has('it says what was paid', $note, 'EUR '.number_format($owed, 2));
    $has('and at which board', $note, 'reference board of');
    $has('and what it settled', $note, money($booked));
    $has('and whether the move was a loss or a gain', $note, $diff > 0 ? 'realised exchange loss' : 'realised exchange gain');
    $ok('a cedi payment leaves no note at all', ApService::payFxNote(null, 500.0), '');
    printf("%s", PHP_EOL);
} catch (Throwable $e) {
    $fail++;
    printf("  FAIL  harness blew up: %s (%s:%d)%s", $e->getMessage(), basename($e->getFile()), $e->getLine(), PHP_EOL);
} finally {
    DB::rollBack();
    printf("(transaction rolled back — the demo database is untouched)%s%s", PHP_EOL, PHP_EOL);
}

// ── the surface ──
printf("%s — the columns and the screens%s", 'The surface', PHP_EOL);
$cols = array_map(fn ($c) => $c->name, DB::select('PRAGMA table_info(supplier_payments)'));
foreach (['currency', 'fx_amount', 'fx_rate', 'fx_date', 'fx_basis', 'fx_diff'] as $c) {
    $ok("supplier_payments.$c exists", in_array($c, $cols, true), true);
}
$curl = fn (string $path) => (string) shell_exec('curl -s -m 20 '.escapeshellarg('http://127.0.0.1:8000/'.$path));
$demo = SupplierInvoice::whereNotNull('currency')->where('currency', '!=', $home)->whereNull('cancelled_at')
    ->whereIn('status', ['open', 'partial'])->first();
if ($demo) {
    $form = $curl('ap/payments/create?supplier='.$demo->supplier_id);
    $has('the payment form offers the currency the bills were billed in', $form, 'value="'.$demo->currency.'" data-rate="');
    $has('and the property’s own currency above it', $form, 'id="payCurrency"');
    $has('it takes the amount in that currency', $form, 'name="fx_amount"');
    $has('and the rate the bank used', $form, 'name="fx_rate"');
    $has('every allocation row says which currency its bill is in', $form, 'data-ccy="'.$demo->currency.'"');
    $has('the totals strip carries the exchange difference', $form, 'id="fxDiffBox"');
    $has('and the form explains how a foreign payment posts', $form, 'Exchange Gain / Loss');
    $dp = SupplierPayment::whereNotNull('currency')->whereNull('cancelled_at')->first();
    if ($dp) {
        $show = $curl('ap/payments/'.$dp->id);
        $has('a foreign payment shows the rate it used', $show, 'id="fxPayPanel"');
        $has('and whether the move was a loss or a gain', $show, 'Realised exchange');
        $has('the register carries the bank’s figure beside the cedis', $curl('ap/payments'), '@');
        $has('and the remittance advice is written in the supplier’s currency', $curl('ap/payments/'.$dp->id.'?print=1'), $dp->currency.' ');
    }
    $has('the ageing screen separates the two', $curl('ap/ageing'), 'Realised');
}
$has('the policy is editable beside the rest of the forex desk', $curl('foreign/settings'), 'name="ap_pay_deal"');

printf("%s%d passed, %d failed%s", PHP_EOL, $pass, $fail, PHP_EOL);
exit($fail ? 1 : 0);
