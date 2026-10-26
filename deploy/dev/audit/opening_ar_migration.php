<?php
/**
 * Opening AR document-level cutover regression harness.
 * All fixtures roll back. It verifies subledger-only balances, source dates,
 * ageing, idempotency, activity guards and the no-new-tax-invoice close path.
 *
 * Run: TINKER='require "/app/deploy/dev/audit/opening_ar_migration.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Http\Controllers\FolioController;
use App\Models\Company;
use App\Models\DataMigrationBatch;
use App\Models\DataMigrationIdMap;
use App\Models\DataMigrationRow;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\GlJournal;
use App\Models\GlLine;
use App\Models\Guest;
use App\Models\Setting;
use App\Services\CreditService;
use App\Services\FolioService;
use App\Services\GstService;
use App\Services\LedgerService;
use App\Services\ManagementReportService;
use App\Services\Migration\ImportContext;
use App\Services\Migration\MigrationService;
use App\Services\PartnerService;
use App\Support\Tenant;
use Illuminate\Http\Request;
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
$makeBatch = function (string $dataset = 'opening_ar') use ($hotel, $token, &$batchNo): DataMigrationBatch {
    $batchNo++;

    return DataMigrationBatch::create([
        'hotel_id' => $hotel->id,
        'code' => 'MIG-AR-'.$token.'-'.$batchNo,
        'dataset' => $dataset,
        'source_name' => 'Opening AR migration harness',
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
$control = fn (string $key, string $date): float => (float) (collect(LedgerService::controls($date))->firstWhere('key', $key)['sub'] ?? 0);
$money = fn (float $amount): string => number_format($amount, 2, '.', '');

DB::beginTransaction();
try {
    Tenant::set($hotel);
    $openingDate = LedgerService::cutoff();
    $lockDate = LedgerService::lockDate();
    if ($lockDate && $openingDate <= $lockDate) {
        throw new \RuntimeException('No eligible open, closed-ledger date is available for the opening AR harness.');
    }
    if ($openingDate >= bdate()->toDateString()) {
        throw new \RuntimeException('Opening AR harness expects a closed-ledger cutoff before the current business date.');
    }
    $guestDocumentDate = date('Y-m-d', strtotime($openingDate.' -15 days'));
    $companyDocumentDate = date('Y-m-d', strtotime($openingDate.' -20 days'));
    $companyDueDate = date('Y-m-d', strtotime($openingDate.' -7 days'));
    $settleGuestDocumentDate = date('Y-m-d', strtotime($openingDate.' -10 days'));
    $newChargeDocumentDate = date('Y-m-d', strtotime($openingDate.' -2 days'));

    $guest = Guest::create(['first_name' => 'Opening AR '.$token, 'last_name' => 'Guest']);
    $company = Company::create(['name' => 'Opening AR '.$token, 'type' => 'corporate', 'is_active' => true, 'direct_billing' => true, 'credit_days' => 60]);
    $guestBatch = $makeBatch('guests');
    $companyBatch = $makeBatch('companies');
    DataMigrationIdMap::create(['hotel_id' => $hotel->id, 'batch_id' => $guestBatch->id, 'dataset' => 'guests', 'external_id' => 'AR-GUEST-'.$token, 'model_type' => Guest::class, 'model_id' => $guest->id]);
    DataMigrationIdMap::create(['hotel_id' => $hotel->id, 'batch_id' => $companyBatch->id, 'dataset' => 'companies', 'external_id' => 'AR-COMPANY-'.$token, 'model_type' => Company::class, 'model_id' => $company->id]);

    $guestPayload = [
        'external_id' => 'AR-GF-'.$token, 'item_type' => 'guest_folio', 'opening_date' => $openingDate,
        'document_date' => $guestDocumentDate, 'due_date' => '', 'source_ref' => 'FOL-AR-'.$token,
        'open_amount' => '120.00', 'guest_external_id' => 'AR-GUEST-'.$token, 'company_external_id' => '', 'notes' => 'Legacy guest folio remainder',
    ];
    $companyPayload = [
        'external_id' => 'AR-CI-'.$token, 'item_type' => 'company_invoice', 'opening_date' => $openingDate,
        'document_date' => $companyDocumentDate, 'due_date' => $companyDueDate, 'source_ref' => 'INV-AR-'.$token,
        'open_amount' => '2450.00', 'guest_external_id' => '', 'company_external_id' => 'AR-COMPANY-'.$token, 'notes' => 'Legacy company invoice remainder',
    ];
    $payloads = [$guestPayload, $companyPayload];
    $guestBefore = $control('guest_ledger', $openingDate);
    $cityBefore = $control('city_ledger', $openingDate);
    $beforeTx = FolioTransaction::count();
    $beforeJournals = GlJournal::count();
    $beforeLines = GlLine::count();
    $importer = MigrationService::importer('opening_ar');

    $assert('opening_ar is apply-capable and ordered after AP but before opening-GL preflight',
        $importer->supportsApply()
            && array_search('opening_ap', MigrationService::ORDER, true) < array_search('opening_ar', MigrationService::ORDER, true)
            && array_search('opening_ar', MigrationService::ORDER, true) < array_search('opening_gl', MigrationService::ORDER, true));
    $assert('template and columns expose document references, source dates and guest/company mappings',
        isset($importer->columns()['source_ref'], $importer->columns()['opening_date'], $importer->columns()['document_date'], $importer->columns()['due_date'])
            && isset($importer->templateRows()[0]['guest_external_id'], $importer->templateRows()[0]['company_external_id']));

    $batch = $makeBatch();
    $records = $addRows($batch, $payloads);
    $dry = MigrationService::run($batch, true);
    foreach ($records as $record) {
        $record->refresh();
    }
    $assert('mapped guest and company documents pass atomic dry-run without inserting folios or GL',
        $dry['created'] === 2 && $dry['failed'] === 0
            && collect($records)->every(fn ($r) => $r->status === 'would_create')
            && FolioTransaction::count() === $beforeTx && GlJournal::count() === $beforeJournals && GlLine::count() === $beforeLines,
        'created='.$dry['created'].' failed='.$dry['failed']);
    $dryReport = MigrationService::report($batch);
    $assert('dry-run reconciliation projects guest and company control balances exactly once',
        abs((float) str_replace(',', '', (string) $dryReport['Projected guest-ledger control after apply']) - ($guestBefore + 120.00)) < 0.005
            && abs((float) str_replace(',', '', (string) $dryReport['Projected city-ledger control after apply']) - ($cityBefore + 2450.00)) < 0.005);

    $applied = MigrationService::run($batch, false);
    $guestMap = DataMigrationIdMap::withoutGlobalScopes()->where('hotel_id', $hotel->id)->where('dataset', 'opening_ar')->where('external_id', $guestPayload['external_id'])->first();
    $companyMap = DataMigrationIdMap::withoutGlobalScopes()->where('hotel_id', $hotel->id)->where('dataset', 'opening_ar')->where('external_id', $companyPayload['external_id'])->first();
    $guestFolio = $guestMap ? Folio::find($guestMap->model_id) : null;
    $companyFolio = $companyMap ? Folio::find($companyMap->model_id) : null;
    $guestOpening = $guestFolio?->transactions()->where('source_type', 'opening_ar')->where('type', 'opening_balance')->first();
    $companyOpening = $companyFolio?->transactions()->where('source_type', 'opening_ar')->where('type', 'opening_balance')->first();
    $assert('apply creates one non-posting subledger row per open document and no GL journals',
        $applied['created'] === 2 && $applied['failed'] === 0
            && FolioTransaction::count() === $beforeTx + 2
            && GlJournal::count() === $beforeJournals && GlLine::count() === $beforeLines);
    $assert('guest opening folio is detached from reservation/stay and preserves source reference and dates',
        $guestFolio && $guestFolio->type === 'walk_in' && $guestFolio->status === 'open'
            && $guestFolio->reservation_id === null && $guestFolio->reservation_room_id === null
            && $guestOpening && $guestOpening->reference === $guestPayload['source_ref']
            && $guestOpening->business_date?->toDateString() === $guestDocumentDate
            && $guestOpening->opening_date?->toDateString() === $openingDate
            && $guestOpening->category === 'opening_ar' && $guestOpening->tax_amount == 0 && $guestOpening->tax_rate == 0);
    $assert('company invoice opening row retains owner, source reference, original due date and city-ledger category',
        $companyFolio && $companyFolio->type === 'master' && $companyFolio->status === 'closed'
            && $companyFolio->company_id === $company->id && $companyOpening
            && $companyOpening->reference === $companyPayload['source_ref']
            && $companyOpening->business_date?->toDateString() === $companyDocumentDate
            && $companyOpening->opening_date?->toDateString() === $openingDate
            && $companyOpening->due_date?->toDateString() === $companyDueDate
            && $companyOpening->category === 'city_ledger' && $companyOpening->tax_amount == 0 && $companyOpening->tax_rate == 0);
    $assert('opening balances stay separate from charges and tax and produce no folio GL journal',
        $guestFolio && abs($guestFolio->totals()['opening'] - 120.00) < 0.005 && $guestFolio->totals()['charges'] == 0
            && $guestFolio->gstTotals()['value'] == 0 && $companyFolio && $companyFolio->totals()['opening'] == 2450.00
            && LedgerService::forFolioTx($guestOpening) === null && LedgerService::forFolioTx($companyOpening) === null);
    $assert('guest and company subledger controls include the opening document balance once',
        abs(($control('guest_ledger', $openingDate) - $guestBefore) - 120.00) < 0.005
            && abs(($control('city_ledger', $openingDate) - $cityBefore) - 2450.00) < 0.005);

    $companyPosition = CreditService::position($company);
    $companyOpenBill = collect($companyPosition['open_bills'])->first(fn ($bill) => $bill['date']->toDateString() === $companyDocumentDate && abs($bill['amount'] - 2450.00) < 0.005);
    $ageingRow = ManagementReportService::arAgeing(\Illuminate\Support\Carbon::parse($openingDate))->firstWhere('company', $company->name);
    $partnerStatement = PartnerService::statement($company);
    $statementRow = collect($partnerStatement['rows'])->first(fn ($row) => $row['ref'] === $companyPayload['source_ref']);
    $assert('company credit ageing uses the imported due date rather than current credit terms',
        $companyOpenBill && $companyOpenBill['due']->toDateString() === $companyDueDate && $companyOpenBill['overdue_days'] >= 7);
    $assert('city-ledger ageing report applies the source due date and company statement exposes source reference/due date',
        $ageingRow && abs($ageingRow['b0'] - 2450.00) < 0.005 && $ageingRow['oldest'] !== '—'
            && $statementRow && $statementRow['due']?->toDateString() === $companyDueDate);

    $repeatBatch = $makeBatch();
    $addRows($repeatBatch, $payloads);
    $repeat = MigrationService::run($repeatBatch, true);
    $assert('re-import with stable external ids and references is unchanged, not duplicated',
        $repeat['skipped'] === 2 && $repeat['created'] === 0 && FolioTransaction::count() === $beforeTx + 2);

    $changedGuest = $guestPayload;
    $changedGuest['open_amount'] = '125.00';
    $updateBatch = $makeBatch();
    $addRows($updateBatch, [$changedGuest]);
    $updateDry = MigrationService::run($updateBatch, true);
    $updateApply = MigrationService::run($updateBatch, false);
    $guestFolio->refresh();
    $assert('unused guest opening amount can be corrected idempotently before live activity',
        $updateDry['updated'] === 1 && $updateApply['updated'] === 1
            && abs($guestFolio->totals()['opening'] - 125.00) < 0.005
            && abs(($control('guest_ledger', $openingDate) - $guestBefore) - 125.00) < 0.005);

    $voidBlocked = false;
    try {
        FolioService::void($guestOpening->fresh(), 'opening AR harness must not void history');
    } catch (\RuntimeException $e) {
        $voidBlocked = str_contains($e->getMessage(), 'Opening receivable');
    }
    $assert('opening receivable cannot be voided as a historical transaction', $voidBlocked);

    $folioCountBeforeMove = Folio::count();
    $moveBlocked = false;
    try {
        $moveRequest = Request::create('/folios/'.$guestFolio->id.'/move', 'POST', [
            'tx' => [$guestOpening->id], 'target' => 'new', 'reason' => 'Opening AR harness safeguard',
        ]);
        (new FolioController)->move($moveRequest, $guestFolio->fresh());
    } catch (\RuntimeException $e) {
        $moveBlocked = str_contains($e->getMessage(), 'Opening receivable balances cannot be split or moved');
    }
    $assert('opening receivable cannot be split or moved, and the rejected move creates no target folio',
        $moveBlocked && Folio::count() === $folioCountBeforeMove);

    $changedCompany = $companyPayload;
    $changedCompany['open_amount'] = '2500.00';
    FolioTransaction::create([
        'hotel_id' => $hotel->id, 'folio_id' => $companyFolio->id, 'business_date' => bdate()->toDateString(),
        'type' => 'payment', 'category' => 'city_ledger', 'description' => 'Subsequent company invoice activity',
        'amount' => 10.00, 'tax_amount' => 0, 'tax_rate' => 0, 'company_id' => $company->id,
        'source_type' => null, 'is_void' => false,
    ]);
    $companyEditError = MigrationService::importer('opening_ar')->validateRow($changedCompany, new ImportContext($repeatBatch, $hotel), 2);
    $assert('company opening balance cannot be rewritten after subsequent city-ledger activity',
        $companyEditError !== null && str_contains($companyEditError, 'subsequent charges, settlements'));

    $settlePayload = [
        'external_id' => 'AR-SETTLE-'.$token, 'item_type' => 'guest_folio', 'opening_date' => $openingDate,
        'document_date' => $settleGuestDocumentDate, 'due_date' => '', 'source_ref' => 'FOL-SETTLE-'.$token,
        'open_amount' => '60.00', 'guest_external_id' => 'AR-GUEST-'.$token, 'company_external_id' => '', 'notes' => 'Opening-only folio settlement check',
    ];
    $settleBatch = $makeBatch();
    $addRows($settleBatch, [$settlePayload]);
    $settleApply = MigrationService::run($settleBatch, false);
    $settleMap = DataMigrationIdMap::withoutGlobalScopes()->where('hotel_id', $hotel->id)->where('dataset', 'opening_ar')->where('external_id', $settlePayload['external_id'])->first();
    $settleFolio = Folio::find($settleMap->model_id);
    $settleOpening = $settleFolio->transactions()->where('source_type', 'opening_ar')->where('type', 'opening_balance')->first();
    FolioTransaction::create([
        'hotel_id' => $hotel->id, 'folio_id' => $settleFolio->id, 'business_date' => bdate()->toDateString(),
        'type' => 'payment', 'category' => 'settlement', 'description' => 'Opening balance settled',
        'amount' => 60.00, 'tax_amount' => 0, 'tax_rate' => 0, 'is_void' => false,
    ]);
    $settleFolio = FolioService::close($settleFolio->fresh());
    $assert('opening-only guest folio closes after settlement without invoice number, invoice date or tax/E-VAT metadata',
        $settleApply['created'] === 1 && $settleFolio->status === 'closed' && $settleFolio->invoice_no === null
            && $settleFolio->invoice_date === null && $settleFolio->irn === null && $settleFolio->supply_type === null
            && GstService::lines($settleFolio)->isEmpty() && LedgerService::forFolioTx($settleOpening) === null);

    $mixedPayload = [
        'external_id' => 'AR-MIXED-'.$token, 'item_type' => 'guest_folio', 'opening_date' => $openingDate,
        'document_date' => $newChargeDocumentDate, 'due_date' => '', 'source_ref' => 'FOL-MIXED-'.$token,
        'open_amount' => '80.00', 'guest_external_id' => 'AR-GUEST-'.$token, 'company_external_id' => '', 'notes' => 'Opening balance plus new charges test',
    ];
    $mixedBatch = $makeBatch();
    $addRows($mixedBatch, [$mixedPayload]);
    $mixedApply = MigrationService::run($mixedBatch, false);
    $mixedMap = DataMigrationIdMap::withoutGlobalScopes()->where('hotel_id', $hotel->id)->where('dataset', 'opening_ar')->where('external_id', $mixedPayload['external_id'])->first();
    $mixedFolio = Folio::find($mixedMap->model_id);
    $mixedOpening = $mixedFolio->transactions()->where('source_type', 'opening_ar')->where('type', 'opening_balance')->first();
    FolioService::charge($mixedFolio, 'misc', 'New post-cutover service', 20.00, false);
    FolioTransaction::create([
        'hotel_id' => $hotel->id, 'folio_id' => $mixedFolio->id, 'business_date' => bdate()->toDateString(),
        'type' => 'payment', 'category' => 'settlement', 'description' => 'Settlement of opening and new charge',
        'amount' => 100.00, 'tax_amount' => 0, 'tax_rate' => 0, 'is_void' => false,
    ]);
    Setting::updateOrCreate(['key' => 'gst_einvoice_auto'], ['value' => '0']);
    $mixedFolio = FolioService::close($mixedFolio->fresh());
    $mixedGst = $mixedFolio->gstTotals();
    $assert('folio with a real new charge invoices only live charges; opening amount is excluded from revenue/tax',
        $mixedApply['created'] === 1 && $mixedFolio->invoice_no !== null && $mixedFolio->invoice_date !== null
            && abs($mixedGst['taxable'] - 20.00) < 0.005 && $mixedGst['tax'] == 0
            && abs($mixedGst['value'] - 20.00) < 0.005 && abs($mixedFolio->totals()['opening'] - 80.00) < 0.005
            && $mixedFolio->totals()['balance'] == 0 && LedgerService::forFolioTx($mixedOpening) === null);

    $assert('AR migration leaves all GL journal/line counts unchanged',
        GlJournal::count() === $beforeJournals && GlLine::count() === $beforeLines);

    $badDue = $companyPayload;
    $badDue['due_date'] = '';
    $badDueError = $importer->validateRow($badDue, new ImportContext($batch, $hotel), 2);
    $assert('company invoice without a source due date is rejected', $badDueError !== null && str_contains($badDueError, 'due_date is required'));
    $badDocumentDate = $companyPayload;
    $badDocumentDate['document_date'] = date('Y-m-d', strtotime($openingDate.' +1 day'));
    $badDocumentError = $importer->validateRow($badDocumentDate, new ImportContext($batch, $hotel), 2);
    $assert('document dated after cutover is rejected', $badDocumentError !== null && str_contains($badDocumentError, 'cannot be after opening_date'));
    $mixedDates = [$guestPayload, $companyPayload];
    $mixedDates[1]['opening_date'] = date('Y-m-d', strtotime($openingDate.' -1 day'));
    $mixedDateError = $importer->validateBatch($mixedDates, new ImportContext($batch, $hotel));
    $assert('mixed opening dates are rejected as an inconsistent snapshot', $mixedDateError !== null && str_contains($mixedDateError, 'Use one opening_date'));
} finally {
    DB::rollBack();
    Tenant::set($hotel);
}

echo PHP_EOL.'Opening AR migration harness: '.$passed.' passed, '.$failed.' failed (all fixtures rolled back).'.PHP_EOL;
if ($failed > 0) {
    throw new \RuntimeException("Opening AR migration harness failed {$failed} assertion(s).");
}
