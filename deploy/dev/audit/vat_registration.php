<?php
/**
 * v7.52 — per-property VAT registration and the Act 1151 test that says whether the record is the right one.
 *
 * The Value Added Tax Act, 2025 (Act 1151) took effect on 1 January 2026 and made the registration test depend on what
 * a property supplies: a supplier of SERVICES — a hotel, a restaurant, a guest house — must register whatever it turns
 * over, because there is no threshold; a supplier of taxable GOODS registers at GH₵750,000, raised from GH₵200,000.
 * E-invoicing is mandatory for every VAT-registered business with no revenue threshold, so an unregistered property must
 * not submit to the VSDC and a registered one must. Before this, the only thing standing between a property and the VSDC
 * was the `gst_einvoice_enabled` switch, and `hotels` carried a TIN with no registration status beside it.
 *
 * What is proven here:
 *
 *   1. the turnover the test reads is the turnover the filed VAT return reports — to the pesewa, month by month
 *   2. the two twelve-month windows, and that the test uses whichever is larger
 *   3. the status for each supply kind: services with no threshold, goods below / approaching / past GH₵750,000
 *   4. the gate: an unregistered property queues nothing, and a forced submit fails saying why
 *   5. registering again puts documents that were parked on that error back into the queue
 *
 * Everything runs inside a transaction that is rolled back, so the demo database is untouched.
 *
 * TINKER='require "/app/deploy/dev/audit/vat_registration.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Models\Folio;
use App\Services\EInvoiceService;
use App\Services\GstService;
use App\Services\PosEvatService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

$pass = 0;
$fail = 0;
$ok = function (string $what, $got, $want) use (&$pass, &$fail) {
    $same = is_float($got) && is_float($want) ? abs($got - $want) < 0.005 : $got === $want;
    if ($same) {
        $pass++;
        printf("  ok    %-64s %s%s", $what, is_scalar($got) ? (is_float($got) ? number_format($got, 2) : (string) $got) : json_encode($got), PHP_EOL);
    } else {
        $fail++;
        printf("  FAIL  %-64s got %s, wanted %s%s", $what, is_scalar($got) ? var_export($got, true) : json_encode($got),
            is_scalar($want) ? var_export($want, true) : json_encode($want), PHP_EOL);
    }
};

$h = hotel();
// whatever the record is when the harness starts is what it must be when it ends — the seed decides, not this file
$shipped = [(bool) $h->vat_registered, (string) $h->vat_supply_kind, $h->vat_registered_at?->toDateString(), (bool) $h->supports_e_invoicing];
printf("Property #%d %s (%s) · TIN %s · business date %s · registration %s%s%s", $h->id, $h->name, $h->country,
    $h->tax_number ?: '(none)', bdate()->toDateString(), json_encode($shipped), PHP_EOL, PHP_EOL);

/** Put the property into a known registration state, the way the hotel settings form would. */
$set = function (bool $registered, string $kind, ?string $since = null, bool $einvoicing = true) use ($h) {
    $h->forceFill(['vat_registered' => $registered, 'vat_supply_kind' => $kind, 'vat_registered_at' => $since,
        'supports_e_invoicing' => $einvoicing])->save();
    GstService::flushRegistration();

    return $h->fresh();
};

DB::beginTransaction();
try {
    // ── 1. the test reads what the return reports ──
    printf("%s — the turnover behind the test is the filed return's turnover%s", 'Agreement', PHP_EOL);
    $periods = [];
    for ($i = 1; $i <= 4; $i++) {
        $periods[] = bdate()->copy()->subMonthsNoOverflow($i)->format('Y-m');
    }
    foreach ($periods as $period) {
        $return = (float) GstService::vatReturn($period)['totals']['taxable'];
        $ok('  '.$period.' — monthTaxable() equals the VAT return', GstService::monthTaxable($period), $return);
    }
    printf("%s", PHP_EOL);

    // ── 2. two real twelve-month windows ──
    printf("%s — the windows%s", 'Thirteen months, read as two', PHP_EOL);
    $t = GstService::vatTurnover();
    $ok('  thirteen months are scanned', count($t['months']), 13);
    $ok('  the last one is the open business month', [$t['months'][12]['period'], $t['months'][12]['open']], [bdate()->format('Y-m'), true]);
    $ok('  the completed window is the twelve before it', [$t['completed_from'], $t['completed_to']],
        [bdate()->copy()->subMonthsNoOverflow(12)->format('Y-m'), bdate()->copy()->subMonthsNoOverflow(1)->format('Y-m')]);
    $ok('  completed = the sum of those twelve', $t['completed'], round(array_sum(array_column(array_slice($t['months'], 0, 12), 'taxable')), 2));
    $ok('  rolling = the twelve months to today', $t['rolling'], round(array_sum(array_column(array_slice($t['months'], 1, 12), 'taxable')), 2));
    $ok('  the test uses whichever window is larger', $t['test'], max($t['completed'], $t['rolling']));
    printf("        completed %s (%s to %s) · rolling %s (to %s, open) · test %s%s", money($t['completed']), $t['completed_from'],
        $t['completed_to'], money($t['rolling']), $t['open_period'], money($t['test']), PHP_EOL);
    printf("%s", PHP_EOL);

    // ── 3. the status for each supply kind ──
    printf("%s — Act 1151 status by what the property supplies%s", 'The test', PHP_EOL);
    $set($shipped[0], $shipped[1], $shipped[2], $shipped[3]);
    $r = GstService::vatRegistration(true);
    $ok('  the record as seeded reads back as it was written', [$r['registered'], $r['supply_kind'], $r['registered_at'], $r['supports_e_invoicing']], $shipped);
    $ok('  a registered services property has no threshold', [$r['status'], $r['threshold']], ['registered', null]);
    $ok('  …has no threshold, because services have none', $r['threshold_label'], 'None — services must register');
    $ok('  …names the Act and its effective date', $r['act'], 'Value Added Tax Act, 2025 (Act 1151), effective 1 January 2026');
    $ok('  a registered property may submit', EInvoiceService::registrationBlocker(), null);

    $set(false, 'services');
    $r = GstService::vatRegistration(true);
    $ok('  unregistered + services → must register whatever it turns over', $r['status'], 'must_register');
    $ok('  …and the note says there is no threshold', (bool) preg_match('/no registration threshold for services/', $r['note']), true);
    $ok('  …and it must not charge VAT while unregistered', (bool) preg_match('/must not charge VAT/', $r['note']), true);
    printf("        “%s”%s", $r['note'], PHP_EOL);

    $set(false, 'goods');
    $r = GstService::vatRegistration(true);
    $ok('  unregistered + goods → the GH₵750,000 threshold applies', $r['threshold'], GstService::VAT_THRESHOLD_GOODS);
    $ok('  …and the percentage of it is reported', is_float($r['pct']), true);
    $expect = $r['turnover']['test'] >= 750000 ? 'must_register' : ($r['pct'] >= 80 ? 'approaching' : 'below_threshold');
    $ok('  the status follows the turnover', $r['status'], $expect);
    printf("        goods supplier: %s of GH₵ 750,000 (%s%%) → %s%s", money($r['turnover']['test']), $r['pct'], $r['status_label'], PHP_EOL);
    $ok('  the prior Act 870 threshold is still on record', GstService::VAT_THRESHOLD_PRIOR, 200000.0);

    $set(false, 'both');
    $r = GstService::vatRegistration(true);
    $ok('  a mixed supplier falls on the services side', [$r['status'], $r['threshold']], ['must_register', null]);

    $set(true, 'services', '2019-04-01');
    $r = GstService::vatRegistration(true);
    $ok('  a registration date is read back', $r['registered_at'], '2019-04-01');
    $ok('  …and quoted in the note', (bool) preg_match('/since 01 April 2019/', $r['note']), true);

    $set(true, 'services', null, false);
    $ok('  registered but not able to e-invoice is blocked', (bool) preg_match('/not marked as able to e-invoice/', (string) EInvoiceService::registrationBlocker()), true);
    $ok('  …and the note says a registered business has to', (bool) preg_match('/certified invoicing system/', GstService::vatRegistration(true)['note']), true);
    $set(true, 'services', null, true);
    printf("%s", PHP_EOL);

    // ── 4. the gate ──
    printf("%s — an unregistered property submits nothing%s", 'The gate', PHP_EOL);
    $doc = Folio::where('status', 'closed')->whereNotNull('invoice_no')->whereNull('irn')->orderByDesc('id')->first();
    if (! $doc) {
        printf("  (no unstamped closed document in the demo data — the gate checks that need one are skipped)%s", PHP_EOL);
    } else {
        $set(true, 'services');
        $ok('  registered: the document is eligible', EInvoiceService::eligible($doc->fresh()), true);
        $ok('  registered: no registration error among the others', (bool) preg_match('/GRA VAT register/', implode(' ', EInvoiceService::validate($doc->fresh()))), false);
        $set(false, 'services');
        $ok('  unregistered: the document is not eligible', EInvoiceService::eligible($doc->fresh()), false);
        $errors = EInvoiceService::validate($doc->fresh());
        $ok('  unregistered: validation names the register', (bool) preg_match('/not on the GRA VAT register/', implode(' ', $errors)), true);
        $ok('  unregistered: restaurant bills stop too', PosEvatService::enabled(), false);
        $ok('  unregistered: a forced submit fails', EInvoiceService::generate($doc->fresh(), null), false);
        $ok('  …and parks it on that reason', (bool) preg_match('/not on the GRA VAT register/', (string) $doc->fresh()->irn_error), true);
        $ok('  …without stamping it', [$doc->fresh()->irn_status, $doc->fresh()->irn], ['failed', null]);
        printf("        “%s”%s", $doc->fresh()->irn_error, PHP_EOL);

        // ── 5. registering again re-queues what the block parked ──
        printf("%s", PHP_EOL);
        printf("%s — registering again clears the queue%s", 'Recovery', PHP_EOL);
        $parked = Folio::where('status', 'closed')->where('irn_status', 'failed')
            ->where('irn_error', 'like', '%not on the GRA VAT register%')->count();
        $set(true, 'services', bdate()->toDateString());
        $res = EInvoiceService::registrationChanged($h->fresh());
        $ok('  the parked documents are counted', $res['cleared'] >= 1, true);
        $ok('  …and there were some to clear', $res['cleared'], $parked);
        $ok('  they are pending again, not failed', $doc->fresh()->irn_status, 'pending');
        $ok('  with the reason cleared', $doc->fresh()->irn_error, null);
        $ok('  and the retry task will pick them up', (bool) ($doc->fresh()->irn_retryable), true);
        $set(false, 'services');
        $ok('  deregistering stops submission rather than unstamping', EInvoiceService::registrationChanged($h->fresh()), ['cleared' => 0, 'registered' => false]);
    }
    printf("%s", PHP_EOL);
} finally {
    DB::rollBack();
    $h->refresh();                 // Tenant::hotel() memoises the instance; the rollback only restored the row
    GstService::flushRegistration();
    printf("(transaction rolled back — the demo database is untouched)%s%s", PHP_EOL, PHP_EOL);
}

$after = hotel()->fresh();
$ok('the record is back as it was', [(bool) $after->vat_registered, $after->vat_supply_kind, $after->vat_registered_at?->toDateString(), (bool) $after->supports_e_invoicing], $shipped);
$ok('a registered property may submit again', EInvoiceService::registrationBlocker(), null);

printf("%s%d passed, %d failed%s", PHP_EOL, $pass, $fail, PHP_EOL);
exit($fail ? 1 : 0);
