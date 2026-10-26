<?php
/**
 * Opening AP snapshot regression harness.
 * All fixtures roll back. Opening AP apply must not post GL; the settlement check follows the normal AP payment path.
 *
 * Run: TINKER='require "/app/deploy/dev/audit/opening_ap_migration.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Models\AccountHead;
use App\Models\DataMigrationBatch;
use App\Models\DataMigrationIdMap;
use App\Models\DataMigrationRow;
use App\Models\GlJournal;
use App\Models\GlLine;
use App\Models\PaymentMode;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Services\ApService;
use App\Services\LedgerService;
use App\Services\Migration\ImportContext;
use App\Services\Migration\MigrationService;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

$passed = 0;
$failed = 0;
$assert = function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo '  ok    '.$label.PHP_EOL;
    } else {
        $failed++;
        echo '  FAIL  '.$label.($detail !== '' ? ' — '.$detail : '').PHP_EOL;
    }
};

$hotel = Tenant::hotel();
$token = date('His').random_int(1000, 9999);
$batchNo = 0;
$makeBatch = function (string $dataset) use ($hotel, $token, &$batchNo): DataMigrationBatch {
    $batchNo++;
    $prefix = $dataset === 'suppliers' ? 'SUP' : strtoupper($dataset === 'opening_ap' ? 'AP' : 'GL');

    return DataMigrationBatch::create([
        'hotel_id' => $hotel->id,
        'code' => 'MIG-'.$prefix.'-'.$token.'-'.$batchNo,
        'dataset' => $dataset,
        'source_name' => 'Opening AP migration harness',
        'status' => 'registered',
        'rows_total' => 0,
    ]);
};
$addRows = function (DataMigrationBatch $batch, array $payloads) use ($hotel): array {
    $records = [];
    foreach ($payloads as $index => $payload) {
        $records[] = DataMigrationRow::create([
            'hotel_id' => $hotel->id,
            'batch_id' => $batch->id,
            'row_no' => $index + 2,
            'external_id' => $payload['external_id'] ?? null,
            'status' => 'pending',
            'payload' => $payload,
        ]);
    }
    $batch->rows_total = count($records);
    $batch->save();

    return $records;
};
$money = fn (int $cents): string => number_format($cents / 100, 2, '.', '');

DB::beginTransaction();
try {
    Tenant::set($hotel);
    $openingDate = LedgerService::cutoff();
    $docDate1 = date('Y-m-d', strtotime($openingDate.' -30 days'));
    $docDate2 = date('Y-m-d', strtotime($openingDate.' -10 days'));
    $docDate3 = date('Y-m-d', strtotime($openingDate.' -5 days'));
    $dueDate1 = date('Y-m-d', strtotime($openingDate.' -15 days'));
    $dueDate2 = date('Y-m-d', strtotime($openingDate.' +20 days'));
    $home = strtoupper(trim((string) ($hotel->currency ?: 'GHS')));
    $foreign = $home === 'USD' ? 'EUR' : 'USD';
    $fxRate = '12.3456';

    $supplier = Supplier::create([
        'name' => 'Opening AP harness supplier '.$token,
        'payment_terms_days' => 30,
        'wht_category' => 'none',
        'is_local' => true,
        'is_active' => true,
    ]);
    $supplierBatch = $makeBatch('suppliers');
    DataMigrationIdMap::create([
        'hotel_id' => $hotel->id,
        'batch_id' => $supplierBatch->id,
        'dataset' => 'suppliers',
        'external_id' => 'AP-SUP-'.$token,
        'model_type' => Supplier::class,
        'model_id' => $supplier->id,
    ]);

    $foreignBillHome = (int) round(10000 * (float) $fxRate);
    $foreignCreditHome = (int) round(100 * (float) $fxRate);
    $payloads = [
        ['external_id' => 'AP-BILL-'.$token, 'supplier_external_id' => 'AP-SUP-'.$token, 'item_type' => 'bill',
            'opening_date' => $openingDate, 'document_date' => $docDate1, 'due_date' => $dueDate1,
            'supplier_ref' => 'AP-HOME-BILL-'.$token, 'open_amount' => '100.00', 'currency' => $home],
        ['external_id' => 'AP-CREDIT-'.$token, 'supplier_external_id' => 'AP-SUP-'.$token, 'item_type' => 'credit',
            'opening_date' => $openingDate, 'document_date' => $docDate3, 'due_date' => '',
            'supplier_ref' => 'AP-HOME-CREDIT-'.$token, 'open_amount' => '25.00', 'currency' => $home],
        ['external_id' => 'AP-FX-BILL-'.$token, 'supplier_external_id' => 'AP-SUP-'.$token, 'item_type' => 'bill',
            'opening_date' => $openingDate, 'document_date' => $docDate2, 'due_date' => $dueDate2,
            'supplier_ref' => 'AP-FX-BILL-'.$token, 'open_amount' => $money($foreignBillHome), 'currency' => $foreign,
            'foreign_open_amount' => '100.00', 'fx_rate' => $fxRate, 'fx_date' => $docDate2, 'fx_basis' => 'legacy'],
        ['external_id' => 'AP-FX-CREDIT-'.$token, 'supplier_external_id' => 'AP-SUP-'.$token, 'item_type' => 'credit',
            'opening_date' => $openingDate, 'document_date' => $docDate3, 'due_date' => '',
            'supplier_ref' => 'AP-FX-CREDIT-'.$token, 'open_amount' => $money($foreignCreditHome), 'currency' => $foreign,
            'foreign_open_amount' => '1.00', 'fx_rate' => $fxRate, 'fx_date' => $docDate3, 'fx_basis' => 'legacy'],
    ];
    $netCents = 10000 + $foreignBillHome - 2500 - $foreignCreditHome;
    $beforeApCents = (int) round(ApService::controlBalance($openingDate) * 100);
    $beforeJournals = GlJournal::count();
    $beforeLines = GlLine::count();
    $beforeDocs = SupplierInvoice::where('supplier_id', $supplier->id)->count();
    $importer = MigrationService::importer('opening_ap');

    $assert('opening_ap is apply-capable and ordered before opening_ar and opening_gl',
        $importer->supportsApply()
            && array_search('opening_ap', MigrationService::ORDER, true) < array_search('opening_ar', MigrationService::ORDER, true)
            && array_search('opening_ar', MigrationService::ORDER, true) < array_search('opening_gl', MigrationService::ORDER, true));
    $assert('template includes the source supplier, cutover date and open-amount fields',
        isset($importer->columns()['supplier_external_id'], $importer->columns()['opening_date'], $importer->columns()['open_amount'])
            && isset($importer->templateRows()[0]['item_type']));

    $batch = $makeBatch('opening_ap');
    $rowRecords = $addRows($batch, $payloads);
    $dryRun = MigrationService::run($batch, true);
    foreach ($rowRecords as $row) {
        $row->refresh();
    }
    $assert('complete home/foreign open items pass an atomic dry-run',
        $dryRun['created'] === 4 && $dryRun['failed'] === 0
            && collect($rowRecords)->every(fn ($row) => $row->status === 'would_create'),
        'created='.$dryRun['created'].' failed='.$dryRun['failed']);
    $dryReport = MigrationService::report($batch);
    $projectedDry = (float) str_replace(',', '', (string) ($dryReport['Projected AP subledger control after apply'] ?? '0'));
    $assert('dry-run projects the net supplier balance without inserting AP items or GL journals',
        abs($projectedDry - (($beforeApCents + $netCents) / 100)) < 0.01
            && SupplierInvoice::where('supplier_id', $supplier->id)->count() === $beforeDocs
            && GlJournal::count() === $beforeJournals && GlLine::count() === $beforeLines);

    $applied = MigrationService::run($batch, false);
    $assert('apply creates all AP items atomically and creates no GL journal',
        $applied['created'] === 4 && $applied['failed'] === 0
            && SupplierInvoice::where('supplier_id', $supplier->id)->count() === 4
            && GlJournal::count() === $beforeJournals && GlLine::count() === $beforeLines,
        'created='.$applied['created'].' failed='.$applied['failed']);

    $docs = SupplierInvoice::where('supplier_id', $supplier->id)->get();
    $homeBill = $docs->firstWhere('supplier_ref', 'AP-HOME-BILL-'.$token);
    $homeCredit = $docs->firstWhere('supplier_ref', 'AP-HOME-CREDIT-'.$token);
    $foreignBill = $docs->firstWhere('supplier_ref', 'AP-FX-BILL-'.$token);
    $foreignCredit = $docs->firstWhere('supplier_ref', 'AP-FX-CREDIT-'.$token);
    $afterApCents = (int) round(ApService::controlBalance($openingDate) * 100);
    $assert('AP control includes open bills less unapplied supplier credits exactly once', $afterApCents - $beforeApCents === $netCents);
    $assert('opening records retain cutover date, original dates, references and credit/bill types',
        $homeBill && $homeBill->isOpening() && $homeBill->opening_date?->toDateString() === $openingDate
            && $homeBill->doc_date?->toDateString() === $docDate1 && $homeBill->due_date?->toDateString() === $dueDate1
            && $homeCredit?->isDebitNote() && $homeCredit?->isOpening());
    $assert('foreign opening bill and credit retain source currency, rate and converted balances',
        $foreignBill && $foreignBill->isForeign() && strtoupper((string) $foreignBill->currency) === $foreign
            && abs($foreignBill->fxOutstanding() - 100.0) < 0.005
            && $foreignCredit && $foreignCredit->isDebitNote() && $foreignCredit->isForeign()
            && abs($foreignCredit->outstanding() - ($foreignCreditHome / 100)) < 0.005);
    $assert('opening AP documents build no historical AP journals',
        $docs->every(fn ($doc) => LedgerService::forSupplierInvoice($doc->load('lines', 'supplier', 'purchaseOrder')) === null)
            && GlJournal::count() === $beforeJournals && GlLine::count() === $beforeLines);
    $assert('open bills and supplier credits participate in AP ageing and settlement lists',
        ApService::openBills($supplier->id)->count() === 2
            && abs((float) ApService::ageing($openingDate, $supplier->id)->firstWhere('supplier_id', $supplier->id)['balance'] - ($netCents / 100)) < 0.01);

    $currencyMismatchBlocked = false;
    try {
        ApService::applyCredit($homeBill, 1.00, null, $foreignCredit);
    } catch (RuntimeException $e) {
        $currencyMismatchBlocked = str_contains($e->getMessage(), 'same currency');
    }
    $assert('foreign supplier credits cannot be allocated to a bill in another currency', $currencyMismatchBlocked);

    $reimport = $makeBatch('opening_ap');
    $reimportRows = $addRows($reimport, $payloads);
    $repeat = MigrationService::run($reimport, true);
    foreach ($reimportRows as $row) {
        $row->refresh();
    }
    $assert('external-id re-import is idempotent and does not duplicate supplier documents',
        $repeat['skipped'] === 4 && $repeat['created'] === 0 && SupplierInvoice::where('supplier_id', $supplier->id)->count() === 4);

    $changed = $payloads[0];
    $changed['open_amount'] = '101.00';
    $mappedChangeError = $importer->validateRow($changed, new ImportContext($reimport, $hotel), 2);
    $assert('an unallocated opening item can be corrected before the opening GL is posted', $mappedChangeError === null);
    $updateBatch = $makeBatch('opening_ap');
    $addRows($updateBatch, [$changed]);
    $updateDryRun = MigrationService::run($updateBatch, true);
    $updateApply = MigrationService::run($updateBatch, false);
    $homeBill->refresh();
    $updatedApCents = (int) round(ApService::controlBalance($openingDate) * 100);
    $assert('pre-cutover correction updates the mapped AP item and control without a GL journal',
        $updateDryRun['updated'] === 1 && $updateApply['updated'] === 1
            && (int) round((float) $homeBill->total * 100) === 10100
            && $updatedApCents === $afterApCents + 100
            && GlJournal::count() === $beforeJournals && GlLine::count() === $beforeLines);
    $restoreBatch = $makeBatch('opening_ap');
    $addRows($restoreBatch, [$payloads[0]]);
    $restore = MigrationService::run($restoreBatch, false);
    $homeBill->refresh();
    $restoredApCents = (int) round(ApService::controlBalance($openingDate) * 100);
    $assert('restoring the source balance is idempotent and leaves the cutover control unchanged',
        $restore['updated'] === 1 && (int) round((float) $homeBill->total * 100) === 10000
            && $restoredApCents === $afterApCents);

    $badForeign = $payloads[2];
    $badForeign['open_amount'] = '1234.55';
    $badFxError = $importer->validateRow($badForeign, new ImportContext($batch, $hotel), 2);
    $assert('a foreign balance inconsistent with its source carrying rate is rejected',
        $badFxError !== null && str_contains($badFxError, 'must equal'));
    $badHomeFx = $payloads[0];
    $badHomeFx['foreign_open_amount'] = 'not-a-number';
    $badHomeFxError = $importer->validateRow($badHomeFx, new ImportContext($batch, $hotel), 2);
    $assert('malformed foreign-currency fields on a home-currency item are not silently ignored',
        $badHomeFxError !== null && str_contains($badHomeFxError, 'must be blank'));
    $badGroupedRate = $payloads[2];
    $badGroupedRate['fx_rate'] = '12,34.5678';
    $badGroupedRateError = $importer->validateRow($badGroupedRate, new ImportContext($batch, $hotel), 2);
    $assert('invalid FX thousands grouping is rejected',
        $badGroupedRateError !== null && str_contains($badGroupedRateError, 'fx_rate must'));

    $mixedDate = $payloads;
    $mixedDate[1]['opening_date'] = date('Y-m-d', strtotime($openingDate.' -1 day'));
    $mixedDateError = $importer->validateBatch($mixedDate, new ImportContext($batch, $hotel));
    $assert('mixed AP as-of dates are rejected before any rows can apply',
        $mixedDateError !== null && str_contains($mixedDateError, 'one opening_date'));

    $duplicateRef = [$payloads[0], array_merge($payloads[1], [
        'external_id' => 'AP-DUP-'.$token,
        'item_type' => 'bill',
        'supplier_ref' => $payloads[0]['supplier_ref'],
    ])];
    $duplicateRefError = $importer->validateBatch($duplicateRef, new ImportContext($batch, $hotel));
    $assert('duplicate supplier/reference pairs in the same AP snapshot are blocked atomically',
        $duplicateRefError !== null && str_contains($duplicateRefError, 'appears more than once'));

    $atomicPayloads = [$payloads[0], $payloads[1]];
    $atomicPayloads[0]['external_id'] = 'AP-ATOMIC-VALID-'.$token;
    $atomicPayloads[0]['supplier_ref'] = 'AP-ATOMIC-VALID-'.$token;
    $atomicPayloads[1]['external_id'] = 'AP-ATOMIC-INVALID-'.$token;
    $atomicPayloads[1]['supplier_external_id'] = 'AP-SUPPLIER-NOT-MAPPED-'.$token;
    $atomicBatch = $makeBatch('opening_ap');
    $addRows($atomicBatch, $atomicPayloads);
    $beforeAtomicDocs = SupplierInvoice::where('supplier_id', $supplier->id)->count();
    $atomicTally = MigrationService::run($atomicBatch, false);
    $assert('an invalid supplier mapping blocks the complete apply without partially creating a valid row',
        $atomicTally['created'] === 0 && $atomicTally['failed'] === 2
            && SupplierInvoice::where('supplier_id', $supplier->id)->count() === $beforeAtomicDocs);

    $sourceRows = [];
    foreach (['INVENTORY', 'AP', 'OPENING-EQUITY'] as $index => $legacyCode) {
        $sourceRows[] = [
            'external_id' => 'AP-TB-'.$token.'-'.$index,
            'source_account_code' => 'LEG-'.$legacyCode,
            'source_account_name' => 'Opening AP control test',
            'opening_date' => $openingDate,
            'account_code' => '',
            'debit_balance' => '0.00',
            'credit_balance' => '0.00',
        ];
    }
    $apHead = AccountHead::where('system_key', 'accounts_payable')->first();
    $inventoryHead = AccountHead::where('system_key', 'inventory')->first();
    $equityHead = AccountHead::where('system_key', 'opening_equity')->first();
    $inventoryControl = collect(LedgerService::controls($openingDate))->firstWhere('key', 'inventory');
    $inventoryCents = (int) round((float) ($inventoryControl['sub'] ?? 0) * 100);
    $apBalanceCents = (int) round(ApService::controlBalance($openingDate) * 100);
    $assert('the AP control, Inventory and Opening Equity heads exist for the trial-balance reconciliation check',
        $apHead && $inventoryHead && $equityHead && $inventoryControl !== null);
    if ($apHead && $inventoryHead && $equityHead && $inventoryControl !== null) {
        $signed = [
            $inventoryHead->id => $inventoryCents,
            $apHead->id => -$apBalanceCents,
        ];
        $signed[$equityHead->id] = -array_sum($signed);
        foreach ($sourceRows as $index => &$sourceRow) {
            $head = $index === 0 ? $inventoryHead : ($index === 1 ? $apHead : $equityHead);
            $net = $signed[$head->id];
            $sourceRow['account_code'] = $head->code;
            $sourceRow['debit_balance'] = $money(max(0, $net));
            $sourceRow['credit_balance'] = $money(max(0, -$net));
        }
        unset($sourceRow);
        $glBatch = $makeBatch('opening_gl');
        $glRows = $addRows($glBatch, $sourceRows);
        $glTally = MigrationService::run($glBatch, true);
        foreach ($glRows as $row) {
            $row->refresh();
        }
        $glReport = MigrationService::report($glBatch);
        $pendingApKeys = array_filter(array_keys($glReport), fn ($key) => str_starts_with($key, 'Pending control: Accounts payable'));
        $assert('opening GL preflight no longer reports an AP variance when the source control matches open items',
            $glTally['failed'] === 0 && $pendingApKeys === []
                && str_contains((string) ($glReport['Preflight status'] ?? ''), 'PASS'));
        $assert('AP/GL control reconciliation preflight still creates no journals',
            GlJournal::count() === $beforeJournals && GlLine::count() === $beforeLines);
    }

    $paymentMode = PaymentMode::where('is_active', true)->whereIn('type', ['cash', 'bank', 'card', 'wallet'])->first();
    if (! $paymentMode) {
        $paymentMode = PaymentMode::create(['name' => 'Opening AP harness bank', 'type' => 'bank', 'is_active' => true]);
    }
    $settlementDate = bdate()->toDateString();
    $beforeSettlementCents = (int) round(ApService::controlBalance($settlementDate) * 100);
    $payment = ApService::pay($supplier, [
        'date' => $settlementDate,
        'amount' => '10.00',
        'payment_mode_id' => $paymentMode->id,
        'reference' => 'AP-HARNESS-'.$token,
    ], [$homeBill->id => 10.00]);
    $homeBill->refresh();
    $afterSettlementCents = (int) round(ApService::controlBalance($settlementDate) * 100);
    $paymentJournal = LedgerService::forSupplierPayment($payment->fresh(['supplier', 'paymentMode']));
    $assert('opening bills settle through the normal AP payment workflow and reduce AP control',
        $payment->allocations()->count() === 1 && $homeBill->status === 'partial'
            && abs($homeBill->outstanding() - 90.00) < 0.005
            && $beforeSettlementCents - $afterSettlementCents === 1000
            && is_array($paymentJournal) && count($paymentJournal['lines']) >= 2);
    $afterActivityError = $importer->validateRow($changed, new ImportContext($reimport, $hotel), 2);
    $assert('an opening item cannot be rewritten after an allocation has occurred',
        $afterActivityError !== null && str_contains($afterActivityError, 'allocations or subsequent activity'));
    $cancelBlocked = false;
    try {
        ApService::cancel($homeBill, 'opening-item harness');
    } catch (RuntimeException $e) {
        $cancelBlocked = str_contains($e->getMessage(), 'Opening AP items');
    }
    $assert('opening supplier balances cannot be cancelled through the historical-bill workflow', $cancelBlocked);
    $paymentJournalExists = GlJournal::where('source_type', 'ap_pay')->where('source_key', (string) $payment->id)
        ->whereNull('reversed_by_id')->whereNull('reversal_of_id')->exists();
    $settlementShouldPostNow = $settlementDate <= LedgerService::cutoff();
    $assert('opening balances create no journal while any eligible payment journal uses the normal AP source',
        $paymentJournalExists === $settlementShouldPostNow
            && $docs->every(fn ($doc) => LedgerService::forSupplierInvoice($doc->load('lines', 'supplier', 'purchaseOrder')) === null)
            && GlJournal::count() === $beforeJournals + ($paymentJournalExists ? 1 : 0));
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL  harness exception — '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')'.PHP_EOL;
} finally {
    DB::rollBack();
}

echo PHP_EOL."Opening AP migration: {$passed} passed, {$failed} failed (all fixtures rolled back; opening AP apply remains non-posting).".PHP_EOL;
if ($failed > 0) {
    throw new RuntimeException('Opening AP migration regression harness failed.');
}
