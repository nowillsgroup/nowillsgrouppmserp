<?php
/**
 * Historical stock archive-only import harness. It exercises dry-run, apply,
 * idempotency and immutable correction guards; every fixture is rolled back.
 *
 * Run: TINKER='require "/app/deploy/dev/audit/historical_stock_preflight.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Models\DataMigrationBatch;
use App\Models\DataMigrationIdMap;
use App\Models\DataMigrationRow;
use App\Models\GlJournal;
use App\Models\HistoricalStockMovementArchive;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Store;
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
$makeBatch = function (string $dataset = 'historical_stock') use ($hotel, $token, &$batchNo): DataMigrationBatch {
    $batchNo++;

    return DataMigrationBatch::create([
        'hotel_id' => $hotel->id,
        'code' => 'MIG-HS-'.$token.'-'.$batchNo,
        'dataset' => $dataset,
        'source_name' => 'Historical stock archive harness',
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
        throw new RuntimeException('No unlocked closed-ledger cutover date is available for the archive harness.');
    }
    $firstMovementDate = Carbon::parse($cutover)->subDays(12)->toDateString();
    $secondMovementDate = Carbon::parse($cutover)->subDays(5)->toDateString();
    if ($firstMovementDate < '2000-01-01') {
        throw new RuntimeException('A post-2000 date is required by the historical movement archive harness.');
    }

    $store = Store::create(['name' => 'Historical Stock Harness Store '.$token, 'type' => 'main']);
    $item = InventoryItem::create([
        'code' => 'HSM-'.$token,
        'name' => 'Historical Movement Harness Item '.$token,
        'unit' => 'kg',
        'last_cost' => 12.50,
    ]);
    $itemBatch = $makeBatch('inventory_items');
    $itemExternalId = 'HIST-ITEM-'.$token;
    DataMigrationIdMap::create([
        'hotel_id' => $hotel->id,
        'dataset' => 'inventory_items',
        'external_id' => $itemExternalId,
        'model_type' => InventoryItem::class,
        'model_id' => $item->id,
        'batch_id' => $itemBatch->id,
    ]);

    $payloads = [
        ['external_id' => 'HIST-RECEIPT-'.$token, 'item_external_id' => $itemExternalId, 'store_name' => $store->name,
            'cutover_date' => $cutover, 'movement_date' => $firstMovementDate, 'movement_type' => 'purchase',
            'quantity' => '24.000', 'unit_cost' => '12.50', 'reference' => 'GRN-HIST-1', 'department' => '', 'remarks' => 'Receipt'],
        ['external_id' => 'HIST-ISSUE-'.$token, 'item_external_id' => $itemExternalId, 'store_name' => $store->name,
            'cutover_date' => $cutover, 'movement_date' => $secondMovementDate, 'movement_type' => 'issue',
            'quantity' => '-3.250', 'unit_cost' => '12.50', 'reference' => 'ISS-HIST-2', 'department' => 'Kitchen', 'remarks' => 'Issue'],
    ];
    $batch = $makeBatch();
    $outcomes = $addRows($batch, $payloads);
    $beforeMovements = StockMovement::count();
    $beforeJournals = GlJournal::count();
    $beforeArchive = HistoricalStockMovementArchive::where('hotel_id', $hotel->id)->count();
    $beforeMaps = DataMigrationIdMap::where('hotel_id', $hotel->id)->where('dataset', 'historical_stock')->count();
    $importer = MigrationService::importer('historical_stock');

    $assert('archive table exists and historical-stock follows opening GL before allergen preflight',
        Schema::hasTable('historical_stock_movement_archives')
            && array_search('historical_stock', MigrationService::ORDER, true) === array_search('opening_gl', MigrationService::ORDER, true) + 1
            && array_search('inventory_allergens', MigrationService::ORDER, true) === array_search('historical_stock', MigrationService::ORDER, true) + 1
            && array_search('reservations', MigrationService::ORDER, true) === array_search('inventory_allergens', MigrationService::ORDER, true) + 1);
    $assert('historical archive supports apply without enabling operational stock or GL posting', $importer->supportsApply());
    $assert('template and column contract contain source mapping and movement fields',
        count($importer->templateRows()) === 2
            && isset($importer->columns()['item_external_id'], $importer->columns()['store_name'], $importer->columns()['movement_date'], $importer->columns()['movement_type'], $importer->columns()['quantity']));
    $assert('mapped item/store and signed historical rows validate',
        $importer->validateRow($payloads[0], new ImportContext($batch, $hotel), 2) === null
            && $importer->validateRow($payloads[1], new ImportContext($batch, $hotel), 3) === null);
    $assert('unit cost accepts legacy currency markers and remains optional',
        $importer->validateRow(array_merge($payloads[0], ['unit_cost' => 'GH₵ 12.50']), new ImportContext($batch, $hotel), 2) === null
            && $importer->validateRow(array_merge($payloads[1], ['unit_cost' => '']), new ImportContext($batch, $hotel), 3) === null);

    $tally = MigrationService::run($batch, true);
    foreach ($outcomes as $outcome) {
        $outcome->refresh();
    }
    $report = MigrationService::report($batch);
    $assert('dry-run validates both rows without writing archive, stock, maps or GL',
        $tally['created'] === 2 && $tally['failed'] === 0
            && collect($outcomes)->every(fn ($row) => $row->status === 'would_create')
            && HistoricalStockMovementArchive::where('hotel_id', $hotel->id)->count() === $beforeArchive
            && StockMovement::count() === $beforeMovements
            && GlJournal::count() === $beforeJournals
            && DataMigrationIdMap::where('hotel_id', $hotel->id)->where('dataset', 'historical_stock')->count() === $beforeMaps);
    $assert('report totals movement types and gross property-currency value without summing quantities across units',
        ($report['Historical source rows reviewed'] ?? null) === 2
            && ($report['Movement type row counts'] ?? null) === 'issue: 1; purchase: 1'
            && ($report['Rows without unit cost'] ?? null) === 0
            && ($report['Gross value for costed rows (property currency)'] ?? null) === '340.63'
            && ($report['Archive rows created by this batch'] ?? null) === 0);
    $assert('pre-apply report states archive-only storage and zero operational/GL impact',
        str_contains((string) ($report['Preflight and archive status'] ?? ''), 'PASS')
            && str_contains((string) ($report['Operational stock impact'] ?? ''), 'None')
            && str_contains((string) ($report['GL impact'] ?? ''), 'None')
            && str_contains((string) ($report['Historical-stock ID maps'] ?? ''), 'None'));

    $applied = MigrationService::run($batch, false);
    foreach ($outcomes as $outcome) {
        $outcome->refresh();
    }
    $archives = HistoricalStockMovementArchive::where('hotel_id', $hotel->id)->where('batch_id', $batch->id)->orderBy('id')->get();
    $firstArchive = $archives->first();
    $applyReport = MigrationService::report($batch);
    $assert('apply writes exactly two immutable archive rows and records archive identities on source outcomes',
        $applied['created'] === 2 && $applied['failed'] === 0 && $batch->fresh()->status === 'imported'
            && $archives->count() === 2
            && collect($outcomes)->every(fn ($row) => $row->status === 'created' && $row->model_type === HistoricalStockMovementArchive::class && $row->model_id !== null));
    $assert('archive preserves source values, item snapshots and original CSV payload without truncation',
        $firstArchive !== null
            && $firstArchive->external_id === $payloads[0]['external_id']
            && $firstArchive->quantity === '24.000'
            && $firstArchive->unit_cost === '12.50'
            && $firstArchive->item_external_id === $itemExternalId
            && $firstArchive->item_name_snapshot === $item->name
            && $firstArchive->item_code_snapshot === $item->code
            && ($firstArchive->source_payload['reference'] ?? null) === 'GRN-HIST-1');
    $mutationBlocked = false;
    if ($firstArchive) {
        $firstArchive->quantity = '99.000';
        try {
            $firstArchive->save();
        } catch (LogicException $e) {
            $mutationBlocked = str_contains($e->getMessage(), 'immutable');
        }
        $firstArchive->refresh();
    }
    $assert('archive model rejects direct update attempts', $mutationBlocked && $firstArchive?->quantity === '24.000');
    $assert('archive apply leaves stock_movements, GL journals and historical-stock ID maps unchanged',
        StockMovement::count() === $beforeMovements
            && GlJournal::count() === $beforeJournals
            && DataMigrationIdMap::where('hotel_id', $hotel->id)->where('dataset', 'historical_stock')->count() === $beforeMaps);
    $assert('post-apply report identifies immutable archive rows while keeping live stock and GL impact at zero',
        ($applyReport['Archive rows created by this batch'] ?? null) === 2
            && str_contains((string) ($applyReport['Preflight and archive status'] ?? ''), 'ARCHIVED')
            && str_contains((string) ($applyReport['Operational stock impact'] ?? ''), 'None')
            && str_contains((string) ($applyReport['GL impact'] ?? ''), 'None'));

    $repeatBatch = $makeBatch();
    $repeatRows = $addRows($repeatBatch, $payloads);
    $repeatTally = MigrationService::run($repeatBatch, false);
    foreach ($repeatRows as $row) {
        $row->refresh();
    }
    $assert('reimporting identical source rows is idempotent and creates no duplicate archives',
        $repeatTally['created'] === 0 && $repeatTally['skipped'] === 2 && $repeatTally['failed'] === 0
            && collect($repeatRows)->every(fn ($row) => $row->status === 'unchanged' && $row->model_id !== null)
            && HistoricalStockMovementArchive::where('hotel_id', $hotel->id)->count() === $beforeArchive + 2);

    $duplicateRows = [$payloads[0], array_merge($payloads[1], ['external_id' => strtolower($payloads[0]['external_id'])])];
    $duplicateError = $importer->validateBatch($duplicateRows, new ImportContext($batch, $hotel));
    $assert('duplicate source IDs are rejected case-insensitively', $duplicateError !== null && str_contains($duplicateError, 'appears more than once'));

    $correction = array_merge($payloads[0], ['quantity' => '25.000']);
    $correctionError = $importer->validateBatch([$correction], new ImportContext($batch, $hotel));
    $assert('source corrections cannot overwrite an immutable archive row',
        $correctionError !== null && str_contains($correctionError, 'immutable archive rows cannot be overwritten'));
    $conflictBatch = $makeBatch();
    $conflictRows = $addRows($conflictBatch, [$correction]);
    $conflictTally = MigrationService::run($conflictBatch, false);
    $conflictRows[0]->refresh();
    $assert('conflicting archive apply fails before any archive, stock, map or GL write',
        $conflictTally['failed'] === 1 && $conflictRows[0]->status === 'failed'
            && HistoricalStockMovementArchive::where('hotel_id', $hotel->id)->count() === $beforeArchive + 2
            && StockMovement::count() === $beforeMovements
            && GlJournal::count() === $beforeJournals
            && DataMigrationIdMap::where('hotel_id', $hotel->id)->where('dataset', 'historical_stock')->count() === $beforeMaps);

    $mixedCutover = [$payloads[0], array_merge($payloads[1], ['cutover_date' => Carbon::parse($cutover)->subDay()->toDateString()])];
    $mixedError = $importer->validateBatch($mixedCutover, new ImportContext($batch, $hotel));
    $assert('mixed cutover dates are rejected atomically', $mixedError !== null && str_contains($mixedError, 'exactly one cutover_date'));

    $onCutover = $importer->validateRow(array_merge($payloads[0], ['movement_date' => $cutover]), new ImportContext($batch, $hotel), 2);
    $assert('movement on cutover date is rejected to avoid an ambiguous live-stock boundary',
        $onCutover !== null && str_contains($onCutover, 'earlier than cutover_date'));

    $badType = $importer->validateRow(array_merge($payloads[0], ['movement_type' => 'unknown']), new ImportContext($batch, $hotel), 2);
    $assert('unsupported movement types are rejected', $badType !== null && str_contains($badType, 'movement_type must be one of'));
    $badSign = $importer->validateRow(array_merge($payloads[0], ['movement_type' => 'issue', 'quantity' => '3.250']), new ImportContext($batch, $hotel), 2);
    $assert('inbound and outbound movement types require matching signed quantities',
        $badSign !== null && str_contains($badSign, 'quantity sign does not match issue'));

    $badPrecision = $importer->validateRow(array_merge($payloads[0], ['quantity' => '1.2345']), new ImportContext($batch, $hotel), 2);
    $assert('quantities above stock-ledger precision are rejected', $badPrecision !== null && str_contains($badPrecision, 'three decimal places'));

    $badCost = $importer->validateRow(array_merge($payloads[0], ['unit_cost' => '-0.01']), new ImportContext($batch, $hotel), 2);
    $assert('negative historical unit costs are rejected', $badCost !== null && str_contains($badCost, 'unit_cost must be blank or'));

    $missingItem = $importer->validateRow(array_merge($payloads[0], ['item_external_id' => 'NO-SUCH-ITEM']), new ImportContext($batch, $hotel), 2);
    $assert('unmapped inventory items block preflight', $missingItem !== null && str_contains($missingItem, 'is not mapped'));

    $longId = $importer->validateRow(array_merge($payloads[0], ['external_id' => str_repeat('x', 101)]), new ImportContext($batch, $hotel), 2);
    $longStore = $importer->validateRow(array_merge($payloads[0], ['store_name' => str_repeat('S', 81)]), new ImportContext($batch, $hotel), 2);
    $longDepartment = $importer->validateRow(array_merge($payloads[0], ['department' => str_repeat('D', 81)]), new ImportContext($batch, $hotel), 2);
    $assert('overlong archive source IDs, store names and departments are rejected rather than truncated',
        $longId !== null && str_contains($longId, 'external_id must be 100 characters or fewer')
            && $longStore !== null && str_contains($longStore, 'store_name must be 80 characters or fewer')
            && $longDepartment !== null && str_contains($longDepartment, 'department must be 80 characters or fewer'));

    $atomicBatch = $makeBatch();
    $atomicPayloads = [
        array_merge($payloads[0], ['external_id' => 'HIST-ATOMIC-GOOD-'.$token]),
        array_merge($payloads[1], ['external_id' => 'HIST-ATOMIC-BAD-'.$token, 'movement_type' => 'not-a-type']),
    ];
    $atomicRows = $addRows($atomicBatch, $atomicPayloads);
    $atomicTally = MigrationService::run($atomicBatch, false);
    foreach ($atomicRows as $row) {
        $row->refresh();
    }
    $assert('one invalid row blocks the whole archive batch without partial writes',
        $atomicTally['failed'] === 2
            && collect($atomicRows)->every(fn ($row) => $row->status === 'failed')
            && HistoricalStockMovementArchive::where('hotel_id', $hotel->id)->where('batch_id', $atomicBatch->id)->count() === 0
            && HistoricalStockMovementArchive::where('hotel_id', $hotel->id)->count() === $beforeArchive + 2);

    $assert('all archive fixtures remain non-operational before rollback',
        StockMovement::count() === $beforeMovements
            && GlJournal::count() === $beforeJournals
            && DataMigrationIdMap::where('hotel_id', $hotel->id)->where('dataset', 'historical_stock')->count() === $beforeMaps);
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL  harness exception — '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')'.PHP_EOL;
} finally {
    DB::rollBack();
}

echo PHP_EOL."Historical stock archive import: {$passed} passed, {$failed} failed (all fixtures rolled back; no operational stock or GL writes).".PHP_EOL;
if ($failed > 0) {
    throw new RuntimeException('Historical stock archive harness failed.');
}
