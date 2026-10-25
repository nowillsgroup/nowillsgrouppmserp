<?php
/**
 * Paid supplier history archive harness. Tests archive-only import, linked
 * source rows, immutability and idempotency; all fixtures are rolled back.
 *
 * Run: TINKER='require "/app/deploy/dev/audit/paid_supplier_history_archive.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Models\ApAllocation;
use App\Models\DataMigrationBatch;
use App\Models\DataMigrationIdMap;
use App\Models\DataMigrationRow;
use App\Models\GlJournal;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPaidHistoryArchive;
use App\Models\SupplierPayment;
use App\Services\LedgerService;
use App\Services\Migration\ImportContext;
use App\Services\Migration\MigrationService;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
$makeBatch = function (string $dataset = 'paid_supplier_history') use ($hotel, $token, &$batchNo): DataMigrationBatch {
    $batchNo++;

    return DataMigrationBatch::create([
        'hotel_id' => $hotel->id,
        'code' => 'MIG-PSH-'.$token.'-'.$batchNo,
        'dataset' => $dataset,
        'source_name' => 'Paid supplier history archive harness',
        'status' => 'registered',
        'rows_total' => 0,
    ]);
};
$addRows = function (DataMigrationBatch $batch, array $payloads) use ($hotel): array {
    $outcomes = [];
    foreach ($payloads as $index => $payload) {
        $outcomes[] = DataMigrationRow::create([
            'hotel_id' => $hotel->id,
            'batch_id' => $batch->id,
            'row_no' => $index + 2,
            'external_id' => $payload['external_id'] ?? null,
            'status' => 'pending',
            'payload' => $payload,
        ]);
    }
    $batch->rows_total = count($outcomes);
    $batch->save();

    return $outcomes;
};

DB::beginTransaction();
try {
    Tenant::set($hotel);
    $cutover = LedgerService::cutoff();
    $lockDate = LedgerService::lockDate();
    if ($lockDate && $cutover <= $lockDate) {
        throw new RuntimeException('No unlocked closed-ledger cutover date is available for the paid-history harness.');
    }
    $billDate = Carbon::parse($cutover)->subDays(30)->toDateString();
    $creditDate = Carbon::parse($cutover)->subDays(25)->toDateString();
    $paymentDate = Carbon::parse($cutover)->subDays(10)->toDateString();
    if ($billDate < '2000-01-01') {
        throw new RuntimeException('A post-2000 date is required by the paid supplier history archive harness.');
    }

    $supplier = Supplier::create(['name' => 'Paid History Harness Supplier '.$token]);
    $supplierBatch = $makeBatch('suppliers');
    $supplierExternalId = 'HIST-SUP-'.$token;
    DataMigrationIdMap::create([
        'hotel_id' => $hotel->id,
        'dataset' => 'suppliers',
        'external_id' => $supplierExternalId,
        'model_type' => Supplier::class,
        'model_id' => $supplier->id,
        'batch_id' => $supplierBatch->id,
    ]);
    $otherSupplier = Supplier::create(['name' => 'Paid History Other Supplier '.$token]);
    $otherSupplierExternalId = 'HIST-SUP-OTHER-'.$token;
    DataMigrationIdMap::create([
        'hotel_id' => $hotel->id,
        'dataset' => 'suppliers',
        'external_id' => $otherSupplierExternalId,
        'model_type' => Supplier::class,
        'model_id' => $otherSupplier->id,
        'batch_id' => $supplierBatch->id,
    ]);

    $billId = 'AP-HIST-BILL-'.$token;
    $paymentId = 'AP-HIST-PAYMENT-'.$token;
    $creditBillId = 'AP-HIST-USD-BILL-'.$token;
    $creditId = 'AP-HIST-CREDIT-'.$token;
    $payloads = [
        ['external_id' => $billId, 'supplier_external_id' => $supplierExternalId, 'cutover_date' => $cutover,
            'record_type' => 'paid_bill', 'document_ref' => 'INV-'.$token, 'document_date' => $billDate,
            'currency' => 'GHS', 'amount' => '1250.00', 'bill_external_id' => '', 'payment_external_id' => '', 'credit_external_id' => '', 'remarks' => 'Paid before cutover'],
        ['external_id' => $paymentId, 'supplier_external_id' => $supplierExternalId, 'cutover_date' => $cutover,
            'record_type' => 'payment', 'document_ref' => 'PAY-'.$token, 'document_date' => $paymentDate,
            'currency' => 'GHS', 'amount' => '1250.00', 'bill_external_id' => '', 'payment_external_id' => '', 'credit_external_id' => '', 'remarks' => 'Historical bank transfer'],
        ['external_id' => 'AP-HIST-ALLOC-'.$token, 'supplier_external_id' => $supplierExternalId, 'cutover_date' => $cutover,
            'record_type' => 'allocation', 'document_ref' => 'PAY-'.$token.' / INV-'.$token, 'document_date' => $paymentDate,
            'currency' => 'GHS', 'amount' => '1250.00', 'bill_external_id' => $billId, 'payment_external_id' => $paymentId, 'credit_external_id' => '', 'remarks' => 'Source allocation'],
        ['external_id' => $creditBillId, 'supplier_external_id' => $supplierExternalId, 'cutover_date' => $cutover,
            'record_type' => 'paid_bill', 'document_ref' => 'USD-INV-'.$token, 'document_date' => $billDate,
            'currency' => 'USD', 'amount' => '100.00', 'bill_external_id' => '', 'payment_external_id' => '', 'credit_external_id' => '', 'remarks' => 'Paid by supplier credit'],
        ['external_id' => $creditId, 'supplier_external_id' => $supplierExternalId, 'cutover_date' => $cutover,
            'record_type' => 'supplier_credit', 'document_ref' => 'USD-CN-'.$token, 'document_date' => $creditDate,
            'currency' => 'USD', 'amount' => '100.00', 'bill_external_id' => '', 'payment_external_id' => '', 'credit_external_id' => '', 'remarks' => 'Historical supplier credit'],
        ['external_id' => 'AP-HIST-CREDIT-APP-'.$token, 'supplier_external_id' => $supplierExternalId, 'cutover_date' => $cutover,
            'record_type' => 'credit_application', 'document_ref' => 'USD-CN-'.$token.' / USD-INV-'.$token, 'document_date' => $paymentDate,
            'currency' => 'USD', 'amount' => '100.00', 'bill_external_id' => $creditBillId, 'payment_external_id' => '', 'credit_external_id' => $creditId, 'remarks' => 'Source credit application'],
    ];
    $batch = $makeBatch();
    $outcomes = $addRows($batch, $payloads);
    $beforeInvoices = SupplierInvoice::count();
    $beforePayments = SupplierPayment::count();
    $beforeAllocations = ApAllocation::count();
    $beforeJournals = GlJournal::count();
    $beforeArchive = SupplierPaidHistoryArchive::where('hotel_id', $hotel->id)->count();
    $beforeMaps = DataMigrationIdMap::where('hotel_id', $hotel->id)->where('dataset', 'paid_supplier_history')->count();
    $importer = MigrationService::importer('paid_supplier_history');

    $assert('supplier-history archive table and dataset are registered after all master/cutover steps',
        Schema::hasTable('supplier_paid_history_archives')
            && isset(MigrationService::DATASETS['paid_supplier_history'])
            && array_search('paid_supplier_history', MigrationService::ORDER, true) === count(MigrationService::ORDER) - 1);
    $assert('paid supplier history supports archive-only apply', $importer->supportsApply());
    $assert('template exposes bill, payment, allocation and credit relations',
        count($importer->templateRows()) === 3
            && isset($importer->columns()['supplier_external_id'], $importer->columns()['record_type'], $importer->columns()['bill_external_id'], $importer->columns()['payment_external_id'], $importer->columns()['credit_external_id']));
    $assert('mapped supplier and all source records validate',
        $importer->validateBatch($payloads, new ImportContext($batch, $hotel)) === null);

    $tally = MigrationService::run($batch, true);
    foreach ($outcomes as $row) {
        $row->refresh();
    }
    $report = MigrationService::report($batch);
    $assert('dry-run validates every source record without archive, AP or GL writes',
        $tally['created'] === count($payloads) && $tally['failed'] === 0
            && collect($outcomes)->every(fn ($row) => $row->status === 'would_create')
            && SupplierPaidHistoryArchive::where('hotel_id', $hotel->id)->count() === $beforeArchive
            && SupplierInvoice::count() === $beforeInvoices
            && SupplierPayment::count() === $beforePayments
            && ApAllocation::count() === $beforeAllocations
            && GlJournal::count() === $beforeJournals
            && DataMigrationIdMap::where('hotel_id', $hotel->id)->where('dataset', 'paid_supplier_history')->count() === $beforeMaps);
    $assert('report separates source totals by type and currency without FX conversion',
        ($report['Historical supplier rows reviewed'] ?? null) === count($payloads)
            && ($report['Unique mapped suppliers referenced'] ?? null) === 1
            && str_contains((string) ($report['Source amount totals by type and currency (not converted)'] ?? ''), 'paid_bill GHS: 1,250.00')
            && str_contains((string) ($report['Source amount totals by type and currency (not converted)'] ?? ''), 'supplier_credit USD: 100.00'));
    $assert('report states no operational AP, GL or supplier-history ID maps',
        str_contains((string) ($report['Operational AP impact'] ?? ''), 'None')
            && str_contains((string) ($report['GL impact'] ?? ''), 'None')
            && str_contains((string) ($report['Supplier-history ID maps'] ?? ''), 'None')
            && str_contains((string) ($report['AP policy status'] ?? ''), 'provisional'));

    $applied = MigrationService::run($batch, false);
    foreach ($outcomes as $row) {
        $row->refresh();
    }
    $archives = SupplierPaidHistoryArchive::where('hotel_id', $hotel->id)->where('batch_id', $batch->id)->orderBy('id')->get();
    $firstArchive = $archives->first();
    $applyReport = MigrationService::report($batch);
    $assert('apply writes archive records only and links source rows to archive identities',
        $applied['created'] === count($payloads) && $applied['failed'] === 0 && $batch->fresh()->status === 'imported'
            && $archives->count() === count($payloads)
            && collect($outcomes)->every(fn ($row) => $row->status === 'created' && $row->model_type === SupplierPaidHistoryArchive::class && $row->model_id !== null));
    $assert('archive retains supplier snapshot, currencies, source references and raw payload',
        $firstArchive !== null
            && $firstArchive->external_id === $billId
            && $firstArchive->supplier_external_id === $supplierExternalId
            && $firstArchive->supplier_name_snapshot === $supplier->name
            && $firstArchive->record_type === 'paid_bill'
            && $firstArchive->currency === 'GHS'
            && $firstArchive->amount === '1250.00'
            && ($firstArchive->source_payload['document_ref'] ?? null) === 'INV-'.$token);
    $mutationBlocked = false;
    if ($firstArchive) {
        $firstArchive->amount = '99.00';
        try {
            $firstArchive->save();
        } catch (LogicException $e) {
            $mutationBlocked = str_contains($e->getMessage(), 'immutable');
        }
        $firstArchive->refresh();
    }
    $assert('archive model rejects direct update attempts', $mutationBlocked && $firstArchive?->amount === '1250.00');
    $assert('apply does not create invoices, payments, allocations, journals or ID maps',
        SupplierInvoice::count() === $beforeInvoices
            && SupplierPayment::count() === $beforePayments
            && ApAllocation::count() === $beforeAllocations
            && GlJournal::count() === $beforeJournals
            && DataMigrationIdMap::where('hotel_id', $hotel->id)->where('dataset', 'paid_supplier_history')->count() === $beforeMaps);
    $assert('post-apply report identifies archive records and preserves the AP-policy gate',
        ($applyReport['Archive rows created by this batch'] ?? null) === count($payloads)
            && str_contains((string) ($applyReport['Preflight and archive status'] ?? ''), 'ARCHIVED')
            && str_contains((string) ($applyReport['AP policy status'] ?? ''), 'provisional'));

    $repeatBatch = $makeBatch();
    $repeatRows = $addRows($repeatBatch, $payloads);
    $repeatTally = MigrationService::run($repeatBatch, false);
    foreach ($repeatRows as $row) {
        $row->refresh();
    }
    $assert('identical re-imports are idempotent and do not create duplicate archive rows',
        $repeatTally['created'] === 0 && $repeatTally['skipped'] === count($payloads) && $repeatTally['failed'] === 0
            && collect($repeatRows)->every(fn ($row) => $row->status === 'unchanged' && $row->model_id !== null)
            && SupplierPaidHistoryArchive::where('hotel_id', $hotel->id)->count() === $beforeArchive + count($payloads));

    $crossBatchAllocation = array_merge($payloads[2], [
        'external_id' => 'AP-HIST-ALLOC-CROSS-'.$token,
        'amount' => '1.00',
        'document_ref' => 'Cross-batch source allocation',
    ]);
    $crossBatch = $makeBatch();
    $crossBatchRows = $addRows($crossBatch, [$crossBatchAllocation]);
    $crossBatchTally = MigrationService::run($crossBatch, false);
    $crossBatchRows[0]->refresh();
    $assert('allocations may link to bill/payment records in an earlier archive batch',
        $crossBatchTally['created'] === 1 && $crossBatchTally['failed'] === 0
            && $crossBatchRows[0]->status === 'created'
            && SupplierPaidHistoryArchive::where('hotel_id', $hotel->id)->count() === $beforeArchive + count($payloads) + 1);

    $duplicateRows = [$payloads[0], array_merge($payloads[1], ['external_id' => strtolower($payloads[0]['external_id'])])];
    $duplicateError = $importer->validateBatch($duplicateRows, new ImportContext($batch, $hotel));
    $assert('duplicate source ids are rejected case-insensitively', $duplicateError !== null && str_contains($duplicateError, 'appears more than once'));

    $correction = array_merge($payloads[0], ['amount' => '1251.00']);
    $correctionError = $importer->validateBatch([$correction], new ImportContext($batch, $hotel));
    $assert('source corrections cannot overwrite immutable paid-history archive rows',
        $correctionError !== null && str_contains($correctionError, 'immutable archive rows cannot be overwritten'));
    $conflictBatch = $makeBatch();
    $conflictRows = $addRows($conflictBatch, [$correction]);
    $conflictTally = MigrationService::run($conflictBatch, false);
    $conflictRows[0]->refresh();
    $assert('conflicting apply fails before archive or operational AP/GL writes',
        $conflictTally['failed'] === 1 && $conflictRows[0]->status === 'failed'
            && SupplierPaidHistoryArchive::where('hotel_id', $hotel->id)->count() === $beforeArchive + count($payloads) + 1
            && SupplierInvoice::count() === $beforeInvoices
            && SupplierPayment::count() === $beforePayments
            && ApAllocation::count() === $beforeAllocations
            && GlJournal::count() === $beforeJournals);

    $wrongType = array_merge($payloads[2], ['external_id' => 'AP-HIST-WRONG-TYPE-'.$token, 'bill_external_id' => $paymentId]);
    $wrongTypeError = $importer->validateBatch([$wrongType], new ImportContext($batch, $hotel));
    $assert('allocations reject a linked source record of the wrong type', $wrongTypeError !== null && str_contains($wrongTypeError, 'must identify a paid_bill record'));

    $orphan = array_merge($payloads[2], ['external_id' => 'AP-HIST-ORPHAN-'.$token, 'bill_external_id' => 'NO-SUCH-BILL']);
    $orphanError = $importer->validateBatch([$orphan], new ImportContext($batch, $hotel));
    $assert('orphan bill/payment references are rejected before apply', $orphanError !== null && str_contains($orphanError, 'does not identify an imported paid_bill'));

    $otherSupplierLink = array_merge($payloads[2], [
        'external_id' => 'AP-HIST-CROSS-SUPPLIER-'.$token,
        'supplier_external_id' => $otherSupplierExternalId,
    ]);
    $supplierMismatch = $importer->validateBatch([$otherSupplierLink], new ImportContext($batch, $hotel));
    $assert('linked records from another supplier are rejected', $supplierMismatch !== null && str_contains($supplierMismatch, 'same supplier'));

    $badCurrency = $importer->validateRow(array_merge($payloads[0], ['currency' => 'US']), new ImportContext($batch, $hotel), 2);
    $badAmount = $importer->validateRow(array_merge($payloads[0], ['amount' => '-1.00']), new ImportContext($batch, $hotel), 2);
    $badPrecision = $importer->validateRow(array_merge($payloads[0], ['amount' => '1.001']), new ImportContext($batch, $hotel), 2);
    $assert('invalid currency codes, negative and over-precision amounts are rejected',
        $badCurrency !== null && str_contains($badCurrency, 'three-letter')
            && $badAmount !== null && str_contains($badAmount, 'positive source-currency')
            && $badPrecision !== null && str_contains($badPrecision, 'no more than two decimal places'));

    $badType = $importer->validateRow(array_merge($payloads[0], ['record_type' => 'invoice']), new ImportContext($batch, $hotel), 2);
    $badDate = $importer->validateRow(array_merge($payloads[0], ['document_date' => $cutover]), new ImportContext($batch, $hotel), 2);
    $longReference = $importer->validateRow(array_merge($payloads[0], ['document_ref' => str_repeat('R', 121)]), new ImportContext($batch, $hotel), 2);
    $missingSupplier = $importer->validateRow(array_merge($payloads[0], ['supplier_external_id' => 'NO-SUCH-SUPPLIER']), new ImportContext($batch, $hotel), 2);
    $assert('bad record types, cutover dates, references and supplier mappings are rejected',
        $badType !== null && str_contains($badType, 'record_type must be one of')
            && $badDate !== null && str_contains($badDate, 'earlier than cutover_date')
            && $longReference !== null && str_contains($longReference, 'document_ref must be 120 characters or fewer')
            && $missingSupplier !== null && str_contains($missingSupplier, 'is not mapped'));

    $badLinkShape = $importer->validateRow(array_merge($payloads[0], ['record_type' => 'allocation']), new ImportContext($batch, $hotel), 2);
    $assert('allocation records require bill and payment link ids', $badLinkShape !== null && str_contains($badLinkShape, 'allocation requires bill_external_id'));

    $atomicBatch = $makeBatch();
    $atomicPayloads = [
        array_merge($payloads[0], ['external_id' => 'AP-HIST-ATOMIC-GOOD-'.$token]),
        array_merge($payloads[1], ['external_id' => 'AP-HIST-ATOMIC-BAD-'.$token, 'record_type' => 'not-a-type']),
    ];
    $atomicRows = $addRows($atomicBatch, $atomicPayloads);
    $atomicTally = MigrationService::run($atomicBatch, false);
    foreach ($atomicRows as $row) {
        $row->refresh();
    }
    $assert('one invalid row blocks the whole paid-history archive batch without partial writes',
        $atomicTally['failed'] === 2
            && collect($atomicRows)->every(fn ($row) => $row->status === 'failed')
            && SupplierPaidHistoryArchive::where('hotel_id', $hotel->id)->where('batch_id', $atomicBatch->id)->count() === 0
            && SupplierPaidHistoryArchive::where('hotel_id', $hotel->id)->count() === $beforeArchive + count($payloads) + 1);

    $assert('all fixture transactions leave the operational AP and GL unchanged before rollback',
        SupplierInvoice::count() === $beforeInvoices
            && SupplierPayment::count() === $beforePayments
            && ApAllocation::count() === $beforeAllocations
            && GlJournal::count() === $beforeJournals
            && DataMigrationIdMap::where('hotel_id', $hotel->id)->where('dataset', 'paid_supplier_history')->count() === $beforeMaps);
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL  harness exception — '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')'.PHP_EOL;
} finally {
    DB::rollBack();
}

echo PHP_EOL."Paid supplier history archive: {$passed} passed, {$failed} failed (all fixtures rolled back; AP policy and GL gate unchanged).".PHP_EOL;
if ($failed > 0) {
    throw new RuntimeException('Paid supplier history archive harness failed.');
}
