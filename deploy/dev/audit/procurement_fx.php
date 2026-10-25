<?php
/**
 * G4 — rollback-only verification for foreign-currency POs, GRNs and matched supplier bills.
 *
 * Exercises the approved receipt-date policy with actual service/model/ledger objects:
 *   - manual PO saved in supplier currency, with local approval value at PO-date FX;
 *   - each GRN separately revalues accepted stock and the AP accrual at its receipt-date board;
 *   - rejected stock is excluded from inventory/AP and retained in the rejection snapshot;
 *   - a matched bill is entered in PO currency at its invoice-date board, even when direct AP policy uses business date;
 *   - receipt-to-invoice FX is kept separate from price/tax variance and posted to Exchange Gain / Loss;
 *   - price/tax variance beyond AP tolerance is held for review; and home-currency POs stay unchanged.
 *
 * Everything runs in one transaction and is rolled back. Run:
 *   TINKER='require "/app/deploy/dev/audit/procurement_fx.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Http\Controllers\ApController;
use App\Http\Controllers\PurchaseController;
use App\Models\AccountHead;
use App\Models\Currency;
use App\Models\ForexRate;
use App\Models\GlJournal;
use App\Models\GoodsReceipt;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Services\ApService;
use App\Services\ForexService;
use App\Services\LedgerService;
use App\Services\ProcurementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$passed = 0;
$failed = [];
$check = function (string $label, bool $ok, $got = null, $want = null) use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        printf("  ok    %s%s", $label, PHP_EOL);

        return;
    }
    $failed[] = $label;
    printf("  FAIL  %s%s", $label, PHP_EOL);
    if ($got !== null || $want !== null) {
        printf("        got %s; wanted %s%s", json_encode($got), json_encode($want), PHP_EOL);
    }
};
$near = fn ($a, $b, float $eps = 0.005): bool => is_numeric($a) && is_numeric($b) && abs((float) $a - (float) $b) < $eps;
$throws = function (string $label, callable $fn, string $contains) use ($check): void {
    try {
        $fn();
        $check($label, false, 'no exception', $contains);
    } catch (RuntimeException $e) {
        $check($label, str_contains($e->getMessage(), $contains), $e->getMessage(), $contains);
    }
};

$before = [
    'po' => PurchaseOrder::count(), 'grn' => GoodsReceipt::count(), 'stock' => StockMovement::count(),
    'bill' => SupplierInvoice::count(), 'journal' => GlJournal::count(),
];
DB::beginTransaction();
try {
    $user = User::query()->orderBy('id')->first();
    $supplier = Supplier::where('is_active', true)->orderBy('id')->first();
    $store = Store::query()->orderBy('id')->first();
    $item = InventoryItem::where('is_active', true)->orderBy('id')->first();
    $usd = Currency::where('code', 'USD')->where('is_active', true)->first();
    if (! $user || ! $supplier || ! $store || ! $item || ! $usd) {
        throw new RuntimeException('The G4 harness needs a seeded user, active supplier, store, stock item and USD Forex Desk currency.');
    }
    auth()->login($user);
    $home = strtoupper(trim((string) (hotel()?->currency ?: 'GHS')));
    $today = bdate()->toDateString();
    $datePo = bdate()->copy()->subDays(2)->toDateString();
    $dateReceipt1 = bdate()->copy()->subDays(1)->toDateString();
    $dateReceipt2 = $today;
    $random = strtoupper(Str::random(8));
    printf("G4 procurement FX · %s property currency · %s business date · %s / %s / %s%s",
        $home, $today, $datePo, $dateReceipt1, $dateReceipt2, PHP_EOL);

    $writeRate = function (string $date, float $reference, ?float $buy = null) use ($usd, $user): void {
        $row = ForexRate::where('currency_id', $usd->id)->whereDate('date', $date)->first();
        $values = ['reference_rate' => $reference, 'buy_rate' => $buy ?? $reference, 'carried' => false, 'set_by' => $user->id];
        if ($row) {
            $row->forceFill($values)->save();
        } else {
            ForexRate::create(['currency_id' => $usd->id, 'date' => $date] + $values);
        }
    };
    $writeRate($datePo, 10.0000, 9.5000);
    $writeRate($dateReceipt1, 12.0000, 11.5000);
    $writeRate($dateReceipt2, 13.0000, 12.5000);
    ForexService::saveSettings(['ap_convert' => '1', 'ap_basis' => 'reference', 'ap_stale_days' => '7', 'ap_rate_date' => 'document']);

    $quote = ProcurementService::poRate('USD', $datePo);
    $check('PO date quote carries the existing reference board and provenance',
        $near($quote['rate'] ?? null, 10.0) && ($quote['basis'] ?? null) === 'reference' && ($quote['date'] ?? null) === $datePo,
        $quote, ['rate' => 10, 'basis' => 'reference', 'date' => $datePo]);
    ForexService::saveSettings(['ap_basis' => 'buy']);
    $buyQuote = ProcurementService::poRate('USD', $datePo);
    $check('PO/GRN conversion respects the AP Forex Desk basis setting', $near($buyQuote['rate'] ?? null, 9.5), $buyQuote['rate'] ?? null, 9.5);
    ForexService::saveSettings(['ap_basis' => 'reference']);
    $throws('an unsupported currency is refused before a PO is saved', fn () => ProcurementService::poRate('ZZZ', $datePo), 'not an active currency');
    $throws('a missing historical board rate is refused rather than guessed', fn () => ProcurementService::poRate('USD', '1900-01-01'), 'There is no USD rate');
    ForexService::saveSettings(['ap_convert' => '0']);
    $throws('turning off AP conversion blocks a foreign PO/GRN quote', fn () => ProcurementService::poRate('USD', $datePo), 'switched off');
    ForexService::saveSettings(['ap_convert' => '1', 'ap_rate_date' => 'document']);

    $makePo = function (string $date, string $currency, float $unitRate, float $qty = 1.0, float $taxRate = 0.0) use ($supplier, $store, $item): PurchaseOrder {
        return ProcurementService::createPurchaseOrder([
            'supplier_id' => $supplier->id, 'store_id' => $store->id, 'po_date' => $date, 'currency' => $currency,
            'notes' => 'G4 rollback-only verification',
            'lines' => [['item_id' => $item->id, 'qty' => $qty, 'rate' => $unitRate, 'tax_rate' => $taxRate]],
        ]);
    };
    $po = $makePo($datePo, 'USD', 10.0, 2.0, 15.0);
    $poLine = $po->items->first();
    $check('foreign PO preserves USD figures and the GHS PO-date approval value',
        $po->isForeign() && $po->currency === 'USD' && $near($po->fx_subtotal, 20.0) && $near($po->fx_tax, 3.0)
            && $near($po->total, 230.0) && $near($po->fx_rate, 10.0),
        [$po->currency, $po->fx_subtotal, $po->fx_tax, $po->total, $po->fx_rate], ['USD', 20, 3, 230, 10]);
    $check('PO line retains foreign unit price separately from its local value',
        $near($poLine->fx_unit_rate, 10.0) && $near($poLine->rate, 100.0) && $near($poLine->fx_amount, 20.0),
        [$poLine->fx_unit_rate, $poLine->rate, $poLine->fx_amount], [10, 100, 20]);
    $po = ProcurementService::approve($po);
    $lineId = (int) $po->items->first()->id;

    $grn1 = ProcurementService::receive($po, [
        'date' => $dateReceipt1, 'delivery_note' => 'G4-DEL-1',
        'lines' => [$lineId => ['delivered' => 1, 'rejected' => 0]],
    ]);
    $check('first GRN uses its receipt-date board for stock/AP, not the PO-date rate',
        $grn1->isForeign() && $near($grn1->fx_rate, 12.0) && $grn1->fx_date->toDateString() === $dateReceipt1
            && $near($grn1->fx_subtotal, 10.0) && $near($grn1->fx_tax, 1.5) && $near($grn1->total, 138.0),
        [$grn1->fx_rate, $grn1->fx_date?->toDateString(), $grn1->fx_total, $grn1->total], [12, $dateReceipt1, 11.5, 138]);
    $firstMovement = StockMovement::where('reference', $grn1->grn_no)->first();
    $check('first stock movement is valued in property currency at receipt-date cost', $firstMovement && $near($firstMovement->rate, 120.0), $firstMovement?->rate, 120.0);

    $grn2 = ProcurementService::receive($po->fresh(['items.item']), [
        'date' => $dateReceipt2, 'delivery_note' => 'G4-DEL-2',
        'lines' => [$lineId => ['delivered' => 1.25, 'rejected' => 0.25, 'reason' => 'G4 rejected sample']],
    ]);
    $check('second GRN independently uses its own receipt-date board',
        $near($grn2->fx_rate, 13.0) && $grn2->fx_date->toDateString() === $dateReceipt2
            && $near($grn2->fx_subtotal, 10.0) && $near($grn2->fx_total, 11.5) && $near($grn2->total, 149.5),
        [$grn2->fx_rate, $grn2->fx_date?->toDateString(), $grn2->fx_total, $grn2->total], [13, $dateReceipt2, 11.5, 149.5]);
    $check('rejected quantity/value is excluded from stock and the GRN accrual',
        $near($grn2->fx_rejected_value, 2.5) && $near($grn2->rejected_value, 32.5)
            && (float) StockMovement::where('reference', $grn2->grn_no)->sum('qty') === 1.0,
        [$grn2->fx_rejected_value, $grn2->rejected_value, StockMovement::where('reference', $grn2->grn_no)->sum('qty')], [2.5, 3.25, 1]);
    $check('GRN journal accrues the local stock and AP values',
        (float) collect(LedgerService::forGoodsReceipt($grn1)['lines'])->first(fn ($line) => $line[0] === AccountHead::sys('accounts_payable'))[1] === -138.0,
        LedgerService::forGoodsReceipt($grn1)['lines'], 'Accounts Payable credit of 138');
    $po = $po->fresh(['items', 'supplier']);
    $check('all accepted GRNs match the PO quantity and foreign subtotal/tax',
        $po->status === 'received' && $near($po->receivedForeignSplit()[0], 20.0) && $near($po->receivedForeignSplit()[1], 3.0)
            && $near($po->receivedValue(), 287.5),
        [$po->status, $po->receivedForeignSplit(), $po->receivedValue()], ['received', [20, 3], 287.5]);

    $bill = ApService::billFromReceipt($po, [
        'currency' => 'USD', 'supplier_ref' => 'G4-'.Str::upper(Str::random(10)), 'doc_date' => $dateReceipt2,
        'subtotal' => 20.0, 'tax' => 3.0,
    ]);
    $check('matched bill is converted at invoice-date board and retains the invoice currency',
        $bill->isForeign() && $bill->currency === 'USD' && $near($bill->fx_rate, 13.0)
            && $bill->fx_date->toDateString() === $dateReceipt2 && $near($bill->total, 299.0),
        [$bill->currency, $bill->fx_rate, $bill->fx_date?->toDateString(), $bill->fx_total, $bill->total], ['USD', 13, $dateReceipt2, 23, 299]);
    $check('foreign amounts match while receipt-date FX is not misclassified as price variance',
        $near($bill->grn_fx_subtotal, 20.0) && $near($bill->grn_fx_tax, 3.0) && $near($bill->variance(), 0.0)
            && $bill->status === 'open' && $near($bill->fxDifference(), 11.5),
        [$bill->grn_fx_subtotal, $bill->grn_fx_tax, $bill->variance(), $bill->status, $bill->fxDifference()], [20, 3, 0, 'open', 11.5]);
    $poRateResponse = (new PurchaseController())->fxRate(Request::create('/inventory/purchases/fx-rate', 'GET', ['currency' => 'USD', 'date' => $dateReceipt2]));
    $poRatePayload = $poRateResponse->getData(true);
    $check('PO FX endpoint returns the receipt-date Forex Desk quote',
        $poRateResponse->getStatusCode() === 200 && $near($poRatePayload['rate'] ?? null, 13.0)
            && ($poRatePayload['date'] ?? null) === $dateReceipt2 && ($poRatePayload['basis'] ?? null) === 'reference',
        $poRatePayload, ['rate' => 13, 'date' => $dateReceipt2, 'basis' => 'reference']);
    $apRateResponse = (new ApController())->fxRate(Request::create('/ap/bills/fx-rate', 'GET', [
        'purchase_order_id' => $po->id, 'currency' => 'USD', 'date' => $dateReceipt2,
    ]));
    $apRatePayload = $apRateResponse->getData(true);
    $check('matched AP FX endpoint returns the GRN bridge, local value and separate FX movement',
        $apRateResponse->getStatusCode() === 200 && $near($apRatePayload['grn_fx_total'] ?? null, 23.0)
            && $near($apRatePayload['grn_local'] ?? null, 287.5) && $near($apRatePayload['grn_at_invoice_date'] ?? null, 299.0)
            && $near($apRatePayload['fx_movement'] ?? null, 11.5),
        $apRatePayload, ['GRN USD' => 23, 'receipt-date GHS' => 287.5, 'invoice-date equivalent GHS' => 299, 'FX' => 11.5]);
    $billJournal = LedgerService::forSupplierInvoice($bill->fresh(['supplier', 'purchaseOrder']));
    $fxLine = collect($billJournal['lines'] ?? [])->first(fn ($line) => $line[0] === AccountHead::sys('forex_gain'));
    $varianceLines = collect($billJournal['lines'] ?? [])->filter(fn ($line) => $line[0] === AccountHead::sys('price_variance'));
    $check('FX loss posts separately to Exchange Gain / Loss, not Purchase Price Variance',
        $billJournal && $fxLine && $near($fxLine[1], 11.5) && $varianceLines->isEmpty(),
        [$fxLine[1] ?? null, $varianceLines->count()], [11.5, 0]);
    $check('matched-bill FX journal balances and adjusts AP by the FX movement only',
        $billJournal && $near(array_sum(array_column($billJournal['lines'], 1)), 0.0)
            && $near(collect($billJournal['lines'])->filter(fn ($line) => $line[0] === AccountHead::sys('accounts_payable'))->sum(1), -11.5),
        $billJournal['lines'] ?? null, 'balanced; AP credit 11.50');
    $check('foreign bill shows invoice-date FX provenance and a non-stale rate',
        ! $bill->fxStale() && $bill->fxLabel() === 'USD 23.00' && $near($bill->grnAtInvoiceRate(), 299.0),
        [$bill->fxStale(), $bill->fxLabel(), $bill->grnAtInvoiceRate()], [false, 'USD 23.00', 299]);

    // Render the actual screens against the transaction fixtures, so the UI assertions also roll back.
    view()->share('errors', new \Illuminate\Support\ViewErrorBag()); // the HTTP middleware normally shares this validation bag
    view()->share('currentHotel', hotel()); // property context normally comes from the request middleware
    $poFormHtml = (new PurchaseController())->create()->render();
    $check('PO creation screen offers currency choice and a PO-date FX endpoint',
        str_contains($poFormHtml, 'id="poCurrency"') && str_contains($poFormHtml, '/inventory/purchases/fx-rate')
            && str_contains($poFormHtml, 'Approval value in '.$home), null, 'currency picker, quote endpoint and local approval value');
    $poPageHtml = (new PurchaseController())->show($po)->render();
    $check('PO detail shows the supplier currency and receipt-date revaluation guidance',
        str_contains($poPageHtml, 'USD 23.00') && str_contains($poPageHtml, 'Each GRN is revalued at its own receipt-date board')
            && str_contains($poPageHtml, 'id="poReceipts"'), null, 'USD PO, GRN-date policy and receipt register');
    $grnPageHtml = (new PurchaseController())->grn($grn2)->render();
    $receiptDateLabel = \Illuminate\Support\Carbon::parse($dateReceipt2)->format('d M Y');
    $check('GRN printout shows receipt-date FX provenance and rejected goods',
        str_contains($grnPageHtml, 'valued for stock and GRN accrual') && str_contains($grnPageHtml, $receiptDateLabel)
            && str_contains($grnPageHtml, 'Rejected &amp; returned'), null, 'receipt-date local value and rejection detail');
    $receiptListHtml = view('ap.bill-create', [
        'po' => null, 'supplier' => null, 'mode' => 'goods', 'suppliers' => collect(), 'heads' => collect(),
        'receipts' => collect([$po]), 'againstOptions' => collect(), 'tolerance' => null,
        'home' => $home, 'fxOn' => true, 'fxBoard' => [], 'fxPolicy' => [], 'fxRateUrl' => route('ap.bills.fx-rate'),
    ])->render();
    $check('unbilled-receipt list keeps the foreign total beside the home GRN value',
        str_contains($receiptListHtml, 'USD 23.00') && str_contains($receiptListHtml, 'Select'),
        null, 'original PO-currency total and match action');
    $apRequest = Request::create('/ap/bills/create', 'GET', ['po' => $po->id]);
    $apFormHtml = (new ApController())->create($apRequest)->render();
    $check('matched-bill form fixes PO currency and exposes invoice-date FX preview',
        str_contains($apFormHtml, 'name="currency" value="USD"') && str_contains($apFormHtml, 'data-foreign="1"')
            && str_contains($apFormHtml, '/ap/bills/fx-rate') && str_contains($apFormHtml, 'receipt-to-invoice FX'),
        null, 'USD-only matched form, invoice-date preview and GRN FX comparison');
    $quoteRequestAt = strpos($apFormHtml, 'const current = ++requestNo;');
    $fetchAt = $quoteRequestAt === false ? false : strpos($apFormHtml, 'const response = await fetch', $quoteRequestAt);
    $successGuardAt = $fetchAt === false ? false : strpos($apFormHtml, 'if (current !== requestNo) return;', $fetchAt);
    $quoteAssignmentAt = $successGuardAt === false ? false : strpos($apFormHtml, 'quote = data;', $successGuardAt);
    $catchAt = $quoteAssignmentAt === false ? false : strpos($apFormHtml, 'catch (error) {', $quoteAssignmentAt);
    $errorGuardAt = $catchAt === false ? false : strpos($apFormHtml, 'if (current !== requestNo) return;', $catchAt);
    $quoteRaceGuard = $quoteRequestAt !== false && $fetchAt !== false && $successGuardAt !== false
        && $quoteAssignmentAt !== false && $catchAt !== false && $errorGuardAt !== false
        && $quoteRequestAt < $fetchAt && $fetchAt < $successGuardAt && $successGuardAt < $quoteAssignmentAt
        && $quoteAssignmentAt < $catchAt && $catchAt < $errorGuardAt;
    $check('late invoice-date FX responses cannot replace the latest quote', $quoteRaceGuard, null,
        'request-number guards precede success assignment and also cover error responses');
    $billPageHtml = (new ApController())->show($bill)->render();
    $check('matched-bill detail separates receipt value, invoice value, price variance and FX movement',
        str_contains($billPageHtml, 'receipt-date value') && str_contains($billPageHtml, 'invoice-date equivalent')
            && str_contains($billPageHtml, 'Price/tax variance') && str_contains($billPageHtml, 'FX movement')
            && str_contains($billPageHtml, 'Exchange Gain / Loss'),
        null, 'separate valuation columns and accounting explanation');
    $billsPageHtml = (new ApController())->bills(Request::create('/ap/bills', 'GET'))->render();
    $check('AP register labels matched receipt-to-invoice movement as a loss or gain',
        str_contains($billsPageHtml, 'FX loss 11.50'), null, 'FX loss shown beside the foreign bill');

    // Direct-bill settings may use business date; matched goods bills are always invoice-date translated.
    ForexService::saveSettings(['ap_rate_date' => 'business']);
    $dateModePo = $makePo($datePo, 'USD', 10.0, 1.0, 15.0);
    $dateModePo = ProcurementService::approve($dateModePo);
    $receivePageHtml = (new PurchaseController())->show($dateModePo)->render();
    $check('approved foreign PO opens the receipt-date quote panel before GRN posting',
        str_contains($receivePageHtml, 'id="grnFxInfo"') && str_contains($receivePageHtml, '/inventory/purchases/fx-rate')
            && str_contains($receivePageHtml, 'Estimated stock and GRN accrual') && str_contains($receivePageHtml, 'button.disabled = true'),
        null, 'receipt-date quote, local estimate and rate-required post guard');
    $dateLine = (int) $dateModePo->items->first()->id;
    $dateGrn = ProcurementService::receive($dateModePo, ['date' => $dateReceipt1, 'lines' => [$dateLine => ['delivered' => 1, 'rejected' => 0]]]);
    $dateBill = ApService::billFromReceipt($dateModePo->fresh(), [
        'currency' => 'USD', 'supplier_ref' => 'G4-DATE-'.Str::upper(Str::random(8)), 'doc_date' => $dateReceipt1,
        'subtotal' => 10.0, 'tax' => 1.5,
    ]);
    $check('matched goods bill uses invoice-date board even with AP direct-bill date set to business',
        ForexService::apPolicy($dateReceipt1)['date'] === $today && $near($dateBill->fx_rate, 12.0)
            && $dateBill->fx_date->toDateString() === $dateReceipt1 && $near($dateBill->fxDifference(), 0.0),
        [ForexService::apPolicy($dateReceipt1)['date'], $dateBill->fx_rate, $dateBill->fx_date?->toDateString(), $dateBill->fxDifference()],
        [$today, 12, $dateReceipt1, 0]);
    ForexService::saveSettings(['ap_rate_date' => 'document']);

    $variancePo = $makePo($datePo, 'USD', 10.0, 1.0, 15.0);
    $variancePo = ProcurementService::approve($variancePo);
    $varianceLine = (int) $variancePo->items->first()->id;
    ProcurementService::receive($variancePo, ['date' => $dateReceipt1, 'lines' => [$varianceLine => ['delivered' => 1, 'rejected' => 0]]]);
    $varianceBill = ApService::billFromReceipt($variancePo->fresh(), [
        'currency' => 'USD', 'supplier_ref' => 'G4-VAR-'.Str::upper(Str::random(8)), 'doc_date' => $dateReceipt2,
        'subtotal' => 11.0, 'tax' => 1.65,
    ]);
    $check('genuine price/tax variance alone exceeds AP tolerance and holds the bill',
        $varianceBill->status === 'on_hold' && $near($varianceBill->variance(), 14.95)
            && $near($varianceBill->fxDifference(), 11.5) && $near($varianceBill->grnAtInvoiceRate(), 149.5),
        [$varianceBill->status, $varianceBill->variance(), $varianceBill->fxDifference(), $varianceBill->grnAtInvoiceRate()],
        ['on_hold', 14.95, 11.5, 149.5]);
    $check('an on-hold goods bill posts neither FX nor price/tax journal lines before AP approval',
        LedgerService::forSupplierInvoice($varianceBill->fresh(['supplier', 'purchaseOrder'])) === null, null, null);
    $varianceRegisterHtml = (new ApController())->bills(Request::create('/ap/bills', 'GET'))->render();
    $check('AP register keeps price/tax variance distinct from the matched-bill FX loss',
        str_contains($varianceRegisterHtml, 'price/tax var 14.95') && str_contains($varianceRegisterHtml, 'FX loss 11.50'),
        null, 'separate AP review variance and FX movement labels');

    $homePo = $makePo($today, $home, 5.0, 1.0, 0.0);
    $check('home-currency PO preserves the legacy local-currency path with null FX snapshots',
        ! $homePo->isForeign() && $homePo->currency === null && $homePo->fx_rate === null
            && $homePo->fx_subtotal === null && $near($homePo->total, 5.0),
        [$homePo->currency, $homePo->fx_rate, $homePo->fx_subtotal, $homePo->total], [null, null, null, 5]);

    $check('the migration added PO, line, GRN, receipt-line and invoice receipt-snapshot columns',
        collect([
            'purchase_orders' => ['currency', 'fx_subtotal', 'fx_tax', 'fx_total', 'fx_rate', 'fx_date', 'fx_basis'],
            'purchase_order_items' => ['fx_unit_rate', 'fx_amount', 'fx_tax_amount'],
            'goods_receipts' => ['currency', 'fx_rate', 'fx_date', 'fx_basis', 'fx_subtotal', 'fx_tax', 'fx_total', 'fx_rejected_value'],
            'goods_receipt_lines' => ['fx_unit_rate', 'fx_amount', 'fx_tax_amount'],
            'supplier_invoices' => ['grn_fx_subtotal', 'grn_fx_tax', 'grn_fx_total'],
        ])->every(fn ($columns, $table) => collect(DB::select('PRAGMA table_info('.$table.')'))->pluck('name')->intersect($columns)->count() === count($columns)),
        null, 'all expected nullable FX columns');

    printf("G4 transaction checks so far: %d passed, %d failed%s", $passed, count($failed), PHP_EOL);
} catch (Throwable $e) {
    $failed[] = 'Harness exception: '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')';
    printf("  FAIL  harness exception: %s (%s:%d)%s", $e->getMessage(), basename($e->getFile()), $e->getLine(), PHP_EOL);
} finally {
    DB::rollBack();
    auth()->logout();
}

$after = [
    'po' => PurchaseOrder::count(), 'grn' => GoodsReceipt::count(), 'stock' => StockMovement::count(),
    'bill' => SupplierInvoice::count(), 'journal' => GlJournal::count(),
];
$check('rollback restores all PO, GRN, stock, supplier-bill and journal rows', $after === $before, $after, $before);
$check('Forex Desk AP settings have been restored by rollback',
    ForexService::cfg()['ap_basis'] === 'reference' && ForexService::cfg()['ap_rate_date'] === 'document'
        && ForexService::apConvert() === true,
    [ForexService::cfg()['ap_basis'], ForexService::cfg()['ap_rate_date'], ForexService::apConvert()], ['reference', 'document', true]);

printf("G4 procurement FX checks: %d passed, %d failed%s", $passed, count($failed), PHP_EOL);
foreach ($failed as $failure) {
    printf("  - %s%s", $failure, PHP_EOL);
}
if ($failed) {
    throw new RuntimeException('G4 procurement FX harness failed.');
}
