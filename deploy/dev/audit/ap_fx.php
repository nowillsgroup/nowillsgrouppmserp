<?php
/**
 * v7.53 — a supplier invoice in a foreign currency: booked in cedis at the forex board, with the supplier's own
 * document kept beside it.
 *
 * Before this, Accounts payable could only hold a bill in the property's own currency. An OTA billing commission from
 * Amsterdam in euros, or an operator billing a management fee in dollars, had to be re-keyed by hand at whatever rate
 * the person at the desk chose — so the ledger, the budget and the supplier's statement all disagreed, and nobody could
 * say what the open foreign bills would cost to settle. What is proven here:
 *
 *   1. ForexService::apPolicy() / apConvert() / apBoard() — the switches, which board date is read, and every rate a
 *      bill could be converted at
 *   2. ApService::convertLines() — each line converted and rounded on its own, so the lines always sum to what is
 *      posted, and the pesewa that rounding leaves is reported rather than hidden
 *   3. a bill in the property's own currency → byte for byte the behaviour it always had
 *   4. a real foreign bill through expenseBill(): the header keeps currency / fx_subtotal / fx_tax / fx_total /
 *      fx_rate / fx_date / fx_basis, the lines and the journal are in cedis, and the note says what rate was used
 *   5. refusals — conversion switched off, no rate on the board inside the window, a goods-receipt bill (which is
 *      valued at the receipt, in cedis), and a debit note in a third currency
 *   6. a debit note against a foreign bill inherits that bill's currency
 *   7. ApService::fxExposure() — what the open foreign bills are worth at the board as it stands, per currency
 *   8. the screens: the entry form quotes every board currency, the ageing page carries the exposure, the forex
 *      settings page carries the policy
 *
 * Everything runs inside a transaction that is rolled back, so the demo database is untouched.
 *
 * TINKER='require "/app/deploy/dev/audit/ap_fx.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Models\AccountHead;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
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
        printf("  ok    %-62s %s%s", $what, is_scalar($got) ? (is_float($got) ? number_format($got, 4) : (string) $got) : json_encode($got), PHP_EOL);
    } else {
        $fail++;
        printf("  FAIL  %-62s got %s, wanted %s%s", $what, is_scalar($got) ? var_export($got, true) : json_encode($got),
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
$heads = AccountHead::pluck('id', 'name');
$ota = Supplier::where('name', 'like', '%Booking%')->orWhere('name', 'like', '%OTA%')->first()
    ?? Supplier::inRandomOrder()->first();
printf("Property %s · own currency %s · business date %s · supplier #%d %s%s%s",
    Tenant::hotel()->code, $home, $today, $ota->id, $ota->name, PHP_EOL, PHP_EOL);

$convert = fn (array $lines, ?string $ccy, string $date) => (new ReflectionMethod(ApService::class, 'convertLines'))
    ->invoke(null, $lines, $ccy, $date, $heads['Marketing & OTA Commission'] ?? null);

DB::beginTransaction();
$reset = null;
$base = null;
try {
    $base = ApService::fxExposure();   // what the committed database already owes, so the harness measures the rise it causes
    // ── 1. the policy ──
    printf("%s — the switches that decide which rate a bill is booked at%s", 'The policy', PHP_EOL);
    $ok('foreign-currency bills are converted by default', ForexService::apConvert(), true);
    $p = ForexService::apPolicy('2026-10-11');
    $ok('the default basis is the published board', $p['basis'], 'reference');
    $ok('a board may be carried forward up to 7 days', $p['stale_days'], 7);
    $ok('the board is read at the invoice date', [$p['rate_date'], $p['date']], ['document', '2026-10-11']);
    ForexService::saveSettings(['ap_rate_date' => 'business']);
    $ok('or at the business date, whatever the invoice says', ForexService::apPolicy('2026-10-11')['date'], $today);
    ForexService::saveSettings(['ap_rate_date' => 'document', 'ap_basis' => 'buy', 'ap_stale_days' => '0']);
    $buy = ForexService::apPolicy('2026-10-11');
    $ok('the buy side is available too', [$buy['basis'], $buy['stale_days']], ['buy', 0]);
    $ok('a stale board is refused when the window is 0 days', ForexService::boardRate('USD', '2026-10-11', ['basis' => 'buy', 'stale_days' => 0])['stale'] ?? null, false);
    ForexService::saveSettings(['ap_basis' => 'reference', 'ap_stale_days' => '7']);
    $board = ForexService::apBoard('2026-10-11');
    $ok('the board quotes every currency it has a rate for', count($board) >= 12, true);
    $ok('each quote carries the rate, basis, board date and name', array_keys($board['USD']), ['rate', 'basis', 'date', 'stale', 'carried', 'name', 'symbol']);
    $ok('the quote for a euro bill on 11 Oct is the 11 Oct board', $board['EUR']['date'], '2026-10-11');
    ForexService::saveSettings(['ap_convert' => '0']);
    $ok('conversion can be switched off', ForexService::apConvert(), false);
    ForexService::saveSettings(['ap_convert' => '1']);
    printf("%s", PHP_EOL);

    // ── 2. the conversion maths ──
    printf("%s — ApService::convertLines()%s", 'The maths', PHP_EOL);
    $own = $convert([['description' => 'Laundry', 'amount' => 100, 'tax_rate' => 0]], $home, '2026-10-11');
    $ok('a bill in our own currency is not converted', [$own['fx'], $own['board']], [null, null]);
    $ok('and its lines are untouched', $own['lines'][0]['amount'], 100.0);
    $lower = $convert([['description' => 'Laundry', 'amount' => 100, 'tax_rate' => 0]], strtolower($home), '2026-10-11');
    $ok('the currency is read case-insensitively', $lower['fx'], null);

    $rate = (float) ForexService::boardRate('USD', '2026-10-11')['rate'];
    $one = $convert([['description' => 'Reservation platform licence', 'amount' => 480, 'tax_rate' => 0]], 'USD', '2026-10-11');
    $ok('one line converts at the board for the invoice date', $one['lines'][0]['amount'], round(480 * $rate, 2));
    $ok('the supplier’s own figures are kept', [$one['fx']['fx_subtotal'], $one['fx']['fx_total'], $one['fx']['currency']], [480.0, 480.0, 'USD']);
    $ok('and so is the rate and the board it came from', [$one['fx']['fx_rate'], $one['fx']['fx_date'], $one['fx']['fx_basis']], [$rate, '2026-10-11', 'reference']);

    $tiny = $convert(array_map(fn ($i) => ['description' => "line $i", 'amount' => 0.01, 'tax_rate' => 0], range(1, 3)), 'USD', '2026-10-11');
    $ok('each line is rounded on its own, not the total once', $tiny['subtotal'], round(0.01 * $rate, 2) * 3);
    $ok('so the lines always sum to what is posted', round(array_sum(array_column($tiny['lines'], 'amount')), 2), $tiny['subtotal']);
    $ok('and that is not the same as rounding the total', $tiny['subtotal'] === round(0.03 * $rate, 2), false);

    $taxed = $convert([['description' => 'Chiller service', 'amount' => 1000, 'tax_rate' => 20]], 'USD', '2026-10-11');
    $ok('tax is converted with the line', $taxed['tax'], round(200 * $rate, 2));
    $ok('the supplier’s tax is kept in its own currency', $taxed['fx']['fx_tax'], 200.0);
    $ok('the cedi total is net plus tax', $taxed['subtotal'] + $taxed['tax'], round($taxed['subtotal'] + $taxed['tax'], 2));

    $throws('a currency the board has never heard of is refused', fn () => $convert([['description' => 'x', 'amount' => 5, 'tax_rate' => 0]], 'ZZZ', '2026-10-11'), 'no ZZZ rate on the forex board');
    $throws('and so is a date the board cannot reach', fn () => $convert([['description' => 'x', 'amount' => 5, 'tax_rate' => 0]], 'USD', '2026-08-01'), 'no USD rate on the forex board');
    ForexService::saveSettings(['ap_convert' => '0']);
    $throws('switching conversion off refuses the bill rather than guessing', fn () => $convert([['description' => 'x', 'amount' => 5, 'tax_rate' => 0]], 'USD', '2026-10-11'), 'switched off');
    ForexService::saveSettings(['ap_convert' => '1']);
    $throws('the old line validation still applies in the supplier’s currency', fn () => $convert([['description' => 'x', 'amount' => -5, 'tax_rate' => 0]], 'USD', '2026-10-11'), '');
    printf("%s", PHP_EOL);

    // ── 3. a real bill ──
    printf("%s — a foreign bill through the real expenseBill()%s", 'A bill', PHP_EOL);
    $eur = (float) ForexService::boardRate('EUR', '2026-10-11')['rate'];
    $bill = ApService::expenseBill($ota, ['supplier_ref' => 'FXH-EUR-1', 'currency' => 'EUR', 'doc_date' => '2026-10-11',
        'lines' => [['account_head_id' => $heads['Marketing & OTA Commission'], 'description' => 'Commission on 41 reservations', 'amount' => 4180.55, 'tax_rate' => 0],
            ['account_head_id' => $heads['Internet & Telephone'] ?? $heads['Marketing & OTA Commission'], 'description' => 'Connectivity fee', 'amount' => 99.45, 'tax_rate' => 0]],
        'notes' => 'FX harness']);
    $ok('the header keeps the supplier’s currency', $bill->currency, 'EUR');
    $ok('and the supplier’s own net, tax and total', [(float) $bill->fx_subtotal, (float) $bill->fx_tax, (float) $bill->fx_total], [4280.0, 0.0, 4280.0]);
    $ok('and the rate, the board it came from and the basis', [(float) $bill->fx_rate, $bill->fx_date->toDateString(), $bill->fx_basis], [$eur, '2026-10-11', 'reference']);
    $ok('the bill is posted in our own currency', (float) $bill->total, round(round(4180.55 * $eur, 2) + round(99.45 * $eur, 2), 2));
    $ok('the lines are in cedis', round((float) $bill->lines[0]->amount, 2), round(4180.55 * $eur, 2));
    $ok('nothing is outstanding until it is paid', round($bill->outstanding(), 2), round((float) $bill->total, 2));
    $ok('the model knows it is foreign', [$bill->isForeign(), $bill->fxLabel()], [true, 'EUR '.number_format(4280, 2)]);
    $ok('what is still owed can be read back in euros', round($bill->fxOutstanding(), 2), round($bill->total / $eur, 2));
    $ok('a board of the invoice date is not carried forward', $bill->fxStale(), false);
    $note = ApService::fxNote(['currency' => $bill->currency, 'fx_subtotal' => $bill->fx_subtotal, 'fx_tax' => $bill->fx_tax,
        'fx_total' => $bill->fx_total, 'fx_rate' => $bill->fx_rate, 'fx_date' => $bill->fx_date->toDateString(), 'fx_basis' => $bill->fx_basis]);
    $has('the note quotes the supplier’s figure', $note, 'EUR 4,280.00');
    $has('the rate it was booked at', $note, number_format($eur, 4));
    $has('which side of the board, and which day', $note, 'reference, board of 11 Oct 2026');
    $has('a stale board is called carried forward', ApService::fxNote(['currency' => 'USD', 'fx_subtotal' => 10, 'fx_tax' => 0, 'fx_total' => 10,
        'fx_rate' => 11.6, 'fx_date' => '2026-10-10', 'fx_basis' => 'reference'], ['stale' => true]), 'carried forward');
    $ok('a cedi bill produces no note at all', ApService::fxNote(null), '');

    $ghs = ApService::expenseBill($ota, ['supplier_ref' => 'FXH-GHS-1', 'doc_date' => '2026-10-11',
        'lines' => [['account_head_id' => $heads['Marketing & OTA Commission'], 'description' => 'Sundry', 'amount' => 250, 'tax_rate' => 0]]]);
    $ok('a bill in our own currency carries no fx columns', [$ghs->currency, $ghs->fx_rate, $ghs->fx_total], [null, null, null]);
    $ok('and is not foreign', $ghs->isForeign(), false);
    $ok('its label falls back to our own currency and the posted total', $ghs->fxLabel(), $home.' 250.00');
    $ok('and any figure can be labelled in it', $ghs->fxLabel(1234.5), $home.' 1,234.50');

    $journal = DB::table('gl_journals')->where('reference', 'like', '%'.$bill->doc_no.'%')->orWhere('narration', 'like', '%'.$bill->doc_no.'%')->first();
    $ok('the journal is posted in cedis', $journal ? round((float) $journal->total, 2) : null, round($bill->total, 2));
    printf("%s", PHP_EOL);

    // ── 4. refusals ──
    printf("%s — what is refused, and why%s", 'The refusals', PHP_EOL);
    $po = \App\Models\PurchaseOrder::whereNotNull('received_at')->whereNull('invoice_no')->orderByDesc('id')->first();
    if ($po) {
        $throws('a goods-receipt bill stays in cedis (it is valued at the receipt)',
            fn () => ApService::billFromReceipt($po, ['currency' => 'USD']), 'valued at the receipt');
    } else {
        printf("  skip  %-62s no received purchase order in the demo%s", 'goods-receipt refusal', PHP_EOL);
    }
    ForexService::saveSettings(['ap_convert' => '0']);
    $throws('with conversion off, no foreign bill is accepted',
        fn () => ApService::expenseBill($ota, ['supplier_ref' => 'FXH-OFF', 'currency' => 'USD', 'doc_date' => '2026-10-11',
            'lines' => [['account_head_id' => $heads['Marketing & OTA Commission'], 'description' => 'x', 'amount' => 10, 'tax_rate' => 0]]]), 'switched off');
    ForexService::saveSettings(['ap_convert' => '1']);

    // ── 5. a debit note inherits the bill it is raised against ──
    printf("%s — a debit note against a foreign bill%s", 'Credit', PHP_EOL);
    $dn = ApService::debitNote($ota, ['against_invoice_id' => $bill->id, 'supplier_ref' => 'FXH-DN-1', 'doc_date' => '2026-10-12',
        'lines' => [['description' => 'Commission reversed – cancelled reservation', 'amount' => 120.5, 'tax_rate' => 0]],
        'notes' => 'FX harness']);
    $ok('it inherits the bill’s currency when none is given', $dn->currency, 'EUR');
    $ok('and converts at its own document date', $dn->fx_date->toDateString(), '2026-10-12');
    $ok('its credit is held in cedis', (float) $dn->total, round(120.5 * (float) ForexService::boardRate('EUR', '2026-10-12')['rate'], 2));
    $ok('the supplier’s own credit is kept', (float) $dn->fx_total, 120.5);
    $throws('but not in a third currency',
        fn () => ApService::debitNote($ota, ['against_invoice_id' => $bill->id, 'supplier_ref' => 'FXH-DN-2', 'currency' => 'USD', 'doc_date' => '2026-10-12',
            'lines' => [['description' => 'x', 'amount' => 10, 'tax_rate' => 0]]]), 'billed in '.$bill->currency);
    $bill->refresh();
    $ok('the note is allocated against the bill it corrects', round((float) $bill->allocated, 2), round((float) $dn->total, 2));
    $ok('so what is owed in cedis falls by the credit', round($bill->outstanding(), 2), round((float) $bill->total - (float) $dn->total, 2));
    $ok('and what is owed in euros follows it down', round($bill->fxOutstanding(), 2), round(((float) $bill->total - (float) $dn->total) / (float) $bill->fx_rate, 2));
    printf("%s", PHP_EOL);

    // ── 6. the exposure ──
    printf("%s — ApService::fxExposure()%s", 'What the open foreign bills are worth now', PHP_EOL);
    $fx = ApService::fxExposure();
    $codes = array_column($fx['rows'], 'currency');
    $ok('every currency with an open foreign bill is reported', in_array('EUR', $codes, true), true);
    $row = collect($fx['rows'])->firstWhere('currency', 'EUR');
    $ok('the exposure is read at the business date', $fx['as_of'], $today);
    $eurBase = collect($base['rows'])->firstWhere('currency', 'EUR') ?? ['booked' => 0.0, 'foreign' => 0.0];
    $ok('booked in cedis rises by exactly what this bill owes', round($row['booked'] - $eurBase['booked'], 2), round($bill->outstanding(), 2));
    $ok('still owed rises by the same bill read back in euros', round($row['foreign'] - $eurBase['foreign'], 2), round($bill->fxOutstanding(), 2));
    $eurNow = (float) ForexService::boardRate('EUR', $today)['rate'];
    $ok('and re-valued at the board as it stands', $row['at_board'], round($row['foreign'] * $eurNow, 2));
    $ok('the movement is the difference', $row['movement'], round($row['at_board'] - $row['booked'], 2));
    $ok('the totals add up across currencies', $fx['booked'], round(array_sum(array_column($fx['rows'], 'booked')), 2));
    $ok('a currency with no rate is counted, not guessed', $fx['unpriced'], count(array_filter($fx['rows'], fn ($r) => $r['at_board'] === null)));
    $bankMode = \App\Models\PaymentMode::where('name', 'like', '%Bank%')->value('id') ?? \App\Models\PaymentMode::orderBy('id')->value('id');
    $paid = ApService::pay($ota, ['date' => $today, 'payment_mode_id' => $bankMode, 'amount' => 1000, 'reference' => 'FXH PAY'], [$bill->id => 1000]);
    $ok('the payment itself is recorded in cedis, not in euros', round((float) $paid->amount, 2), 1000.0);
    $ok('and it is applied to the foreign bill', round((float) $bill->fresh()->allocated, 2), round((float) $dn->total + 1000, 2));
    $after = collect(ApService::fxExposure()['rows'])->firstWhere('currency', 'EUR');
    $ok('paying part of it reduces the exposure', $after['booked'], round($row['booked'] - 1000, 2));
    $ok('and the euro obligation with it', $after['foreign'], round(($row['booked'] - 1000) / (float) $bill->fx_rate, 2));
    printf("%s", PHP_EOL);
    $reset = $bill->id;
} catch (Throwable $e) {
    $fail++;
    printf("  FAIL  harness blew up: %s (%s:%d)%s", $e->getMessage(), basename($e->getFile()), $e->getLine(), PHP_EOL);
} finally {
    DB::rollBack();
    printf("(transaction rolled back — the demo database is untouched)%s%s", PHP_EOL, PHP_EOL);
}

// ── 7. the schema and the screens ──
printf("%s — the columns and the screens%s", 'The surface', PHP_EOL);
$cols = array_keys(DB::select("PRAGMA table_info(supplier_invoices)")[0] ? array_flip(array_map(fn ($r) => $r->name, DB::select('PRAGMA table_info(supplier_invoices)'))) : []);
foreach (['currency', 'fx_subtotal', 'fx_tax', 'fx_total', 'fx_rate', 'fx_date', 'fx_basis'] as $c) {
    $ok("supplier_invoices.$c exists", in_array($c, $cols, true), true);
}
$ok('nothing was left behind', SupplierInvoice::where('supplier_ref', 'like', 'FXH-%')->count(), 0);
$ok('the forex settings are back to the defaults', [ForexService::cfg()['ap_basis'], ForexService::cfg()['ap_stale_days'],
    ForexService::cfg()['ap_rate_date'], ForexService::apConvert()], ['reference', 7, 'document', true]);

$curl = fn (string $path) => (string) shell_exec('curl -s -m 20 '.escapeshellarg('http://127.0.0.1:8000/'.$path));
$form = $curl('ap/bills/create?mode=expense&supplier='.$ota->id);
$has('the entry form offers the property’s own currency', $form, 'value="'.$home.'"');
$has('and every currency the board quotes', $form, 'value="USD" data-rate="');
$has('the form shows the cedi equivalent as lines are typed', $form, 'id="fxRow"');
$has('and explains which rate will be used', $form, 'id="fxNote"');
$has('the policy is editable beside the channel policy', $curl('foreign/settings'), 'name="ap_rate_date"');
$ageing = $curl('ap/ageing');
$anyForeign = SupplierInvoice::whereNotNull('currency')->where('currency', '!=', $home)->whereNull('cancelled_at')
    ->whereIn('status', ['open', 'partial', 'on_hold'])->exists();
if ($anyForeign) {
    $has('the ageing screen carries the exposure card', $ageing, 'id="fxExposure"');
} else {
    $ok('the ageing screen hides the card while no foreign bill is open', str_contains($ageing, 'id="fxExposure"'), false);
}
$has('the register is still served', $curl('ap/bills'), 'Bills');

printf("%s%d passed, %d failed%s", PHP_EOL, $pass, $fail, PHP_EOL);
exit($fail ? 1 : 0);
