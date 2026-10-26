<?php
/**
 * Opening-stock cutover import and GL reconciliation regression harness.
 * All database work is wrapped in a rollback-only transaction.
 *
 * Run: TINKER='require "/app/deploy/dev/audit/opening_stock_migration.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Models\AccountHead;
use App\Models\DataMigrationBatch;
use App\Models\DataMigrationIdMap;
use App\Models\DataMigrationRow;
use App\Models\GlJournal;
use App\Models\GlLine;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Setting;
use App\Models\Store;
use App\Services\LedgerService;
use App\Services\Migration\ImportContext;
use App\Services\Migration\MigrationService;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
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

    return DataMigrationBatch::create([
        'hotel_id' => $hotel->id,
        'code' => 'MIG-OS-'.$token.'-'.$batchNo,
        'dataset' => $dataset,
        'source_name' => 'Opening stock importer harness',
        'status' => 'registered',
        'rows_total' => 0,
    ]);
};
$addRow = function (DataMigrationBatch $batch, array $payload, int $rowNo) use ($hotel): DataMigrationRow {
    $batch->increment('rows_total');

    return DataMigrationRow::create([
        'hotel_id' => $hotel->id,
        'batch_id' => $batch->id,
        'row_no' => $rowNo,
        'external_id' => $payload['external_id'] ?? null,
        'status' => 'pending',
        'payload' => $payload,
    ]);
};
$activeJournal = function (string $date): ?GlJournal {
    return GlJournal::where('source_type', 'stock')->where('source_key', $date)
        ->whereNull('reversed_by_id')->whereNull('reversal_of_id')->latest('id')->first();
};
$accountNet = function (?GlJournal $journal, int $accountId): float {
    if (! $journal) {
        return 0.0;
    }
    $lines = GlLine::where('gl_journal_id', $journal->id)->where('account_head_id', $accountId);

    return (float) $lines->sum('debit') - (float) $lines->sum('credit');
};
$openingValue = function (string $date): float {
    return StockMovement::with('item')->whereDate('date', $date)->where('type', 'opening')->get()
        ->sum(fn ($movement) => round((float) $movement->qty * ((float) $movement->rate ?: (float) ($movement->item?->last_cost ?? 0)), 2));
};

DB::beginTransaction();
try {
    Tenant::set($hotel);
    $cutoff = LedgerService::cutoff();
    $lockDate = LedgerService::lockDate();
    $date = $cutoff;
    while ($date > ($lockDate ?: '1900-01-01')
        && (StockMovement::whereDate('date', $date)->exists() || $activeJournal($date))) {
        $date = Carbon::parse($date)->subDay()->toDateString();
    }
    if ($date <= ($lockDate ?: '1900-01-01')) {
        throw new \RuntimeException('No unlocked closed-ledger date without existing stock activity is available for the harness.');
    }

    $store = Store::orderBy('id')->first();
    if (! $store) {
        $store = Store::create(['name' => 'Opening Stock Harness Store '.$token, 'type' => 'main']);
    }
    $itemExternalId = 'OS-ITEM-'.$token;
    $itemBatch = $makeBatch('inventory_items');
    $item = InventoryItem::create(['code' => 'OS-'.$token, 'name' => 'Opening Stock Harness Item '.$token, 'unit' => 'case', 'last_cost' => 25.50]);
    DataMigrationIdMap::create([
        'hotel_id' => $hotel->id, 'dataset' => 'inventory_items', 'external_id' => $itemExternalId,
        'model_type' => InventoryItem::class, 'model_id' => $item->id, 'batch_id' => $itemBatch->id,
    ]);

    $itemExternal = 'OS-ITEM-'.$token;
    $openingRows = [
        ['external_id' => 'OS-ROW-A-'.$token, 'item_external_id' => $itemExternal, 'store_name' => $store->name, 'opening_date' => $date, 'quantity' => '4.00', 'unit_cost' => '25.50'],
        ['external_id' => 'OS-ROW-B-'.$token, 'item_external_id' => $itemExternal, 'store_name' => $store->name, 'opening_date' => $date, 'quantity' => '2.00', 'unit_cost' => '10.00'],
    ];
    $batch = $makeBatch('opening_stock');
    $outcomes = [];
    foreach ($openingRows as $i => $payload) {
        $outcomes[] = $addRow($batch, $payload, $i + 2);
    }

    $assert('opening_stock is registered after inventory_items in the cutover order',
        array_search('opening_stock', MigrationService::ORDER, true) === array_search('inventory_items', MigrationService::ORDER, true) + 1);
    $assert('opening-stock dry-run validates mapped item, store, date, quantity and cost',
        MigrationService::importer('opening_stock')->validateRow($openingRows[0], new ImportContext($batch, $hotel), 2) === null);

    $inventoryAccount = AccountHead::sys('inventory');
    $openingEquity = AccountHead::sys('opening_equity');
    $beforeJournal = $activeJournal($date);
    $beforeInventoryNet = $accountNet($beforeJournal, $inventoryAccount);
    $beforeOpeningEquityNet = $accountNet($beforeJournal, $openingEquity);
    $journalCountBefore = GlJournal::count();

    $dryRun = MigrationService::run($batch, true);
    foreach ($outcomes as $outcome) {
        $outcome->refresh();
    }
    $assert('dry-run marks both rows would_create without stock movements',
        $dryRun['created'] === 2 && collect($outcomes)->every(fn ($r) => $r->status === 'would_create')
            && ! StockMovement::where('inventory_item_id', $item->id)->where('reference', 'MIG-OPENING')->exists());
    $assert('dry-run leaves the GL journal count untouched', GlJournal::count() === $journalCountBefore);

    $applied = MigrationService::run($batch, false);
    $movements = StockMovement::where('inventory_item_id', $item->id)->where('reference', 'MIG-OPENING')->orderBy('id')->get();
    $journal = $activeJournal($date);
    $inventoryNet = $accountNet($journal, $inventoryAccount);
    $openingEquityNet = $accountNet($journal, $openingEquity);
    $journalBalance = $journal ? (float) $journal->lines()->sum('debit') - (float) $journal->lines()->sum('credit') : 999999.0;
    $stockLineDetail = $journal?->lines()->with('account')->get()->map(fn ($line) => ($line->account?->system_key ?? $line->account?->name ?? $line->account_head_id).': D '.number_format((float) $line->debit, 2).'/ C '.number_format((float) $line->credit, 2))->implode('; ') ?? 'no journal lines';
    $datedOpeningDetail = StockMovement::whereDate('date', $date)->where('type', 'opening')->get()->map(fn ($m) => $m->id.' '.($m->date?->toDateString() ?? 'null').' qty='.(float) $m->qty.' rate='.(float) $m->rate)->implode('; ') ?: 'no date-matched opening rows';
    $assert('apply creates two dated opening movements and maps their source ids',
        $applied['created'] === 2 && $movements->count() === 2
            && DataMigrationIdMap::where('hotel_id', $hotel->id)->where('dataset', 'opening_stock')->where('batch_id', $batch->id)->count() === 2);
    $assert('opening stock quantities and property-currency values are preserved',
        (float) $movements->sum('qty') === 6.0 && abs($movements->sum(fn ($m) => round((float) $m->qty * (float) $m->rate, 2)) - 122.0) < 0.01);
    $assert('GL debits Inventory by the opening-stock value', abs(($inventoryNet - $beforeInventoryNet) - 122.0) < 0.01,
        'net debit change was '.number_format($inventoryNet - $beforeInventoryNet, 2).'; journal lines: '.$stockLineDetail);
    $assert('GL credits Opening Balance Equity by the same value', abs(($openingEquityNet - $beforeOpeningEquityNet) + 122.0) < 0.01,
        'debit-minus-credit change was '.number_format($openingEquityNet - $beforeOpeningEquityNet, 2));
    $assert('the stock GL journal is balanced and reconciles all opening movements',
        $journal !== null && abs($journalBalance) < 0.01 && abs(-$openingEquityNet - $openingValue($date)) < 0.01,
        'journal '.($journal?->journal_no ?? 'missing').', balance '.number_format($journalBalance, 2).', equity net '.number_format($openingEquityNet, 2).', opening value '.number_format($openingValue($date), 2).', date='.$date.'; rows='.$datedOpeningDetail.'; lines='.$stockLineDetail);

    $report = MigrationService::report($batch);
    $assert('the cutover report includes row count, quantity, value and journal reference',
        $report['Opening stock rows in this batch'] === 2 && (float) $report['Opening stock quantity'] === 6.0
            && abs((float) $report['Opening stock value in property currency'] - 122.0) < 0.01
            && $report['Opening-stock GL journals'] !== 'Missing');

    $activeIdAfterFirstApply = $journal->id;
    $repeatBatch = $makeBatch('opening_stock');
    $repeatRows = [];
    foreach ($openingRows as $i => $payload) {
        $repeatRows[] = $addRow($repeatBatch, $payload, $i + 2);
    }
    $repeatDry = MigrationService::run($repeatBatch, true);
    $repeatApplied = MigrationService::run($repeatBatch, false);
    $repeatJournal = $activeJournal($date);
    $assert('re-importing unchanged external ids does not duplicate stock or GL',
        $repeatDry['skipped'] === 2 && $repeatApplied['skipped'] === 2
            && StockMovement::where('inventory_item_id', $item->id)->where('reference', 'MIG-OPENING')->count() === 2
            && $repeatJournal?->id === $activeIdAfterFirstApply);

    $changedRows = $openingRows;
    $changedRows[0]['quantity'] = '5.00';
    $updateBatch = $makeBatch('opening_stock');
    $updateOutcomes = [];
    foreach ($changedRows as $i => $payload) {
        $updateOutcomes[] = $addRow($updateBatch, $payload, $i + 2);
    }
    $updateDry = MigrationService::run($updateBatch, true);
    $updateApplied = MigrationService::run($updateBatch, false);
    $updateJournal = $activeJournal($date);
    $updateInventoryNet = $accountNet($updateJournal, $inventoryAccount);
    $updateOpeningEquityNet = $accountNet($updateJournal, $openingEquity);
    $assert('re-importing a changed source row updates its existing movement',
        $updateDry['updated'] === 1 && $updateDry['skipped'] === 1 && $updateApplied['updated'] === 1
            && StockMovement::where('inventory_item_id', $item->id)->where('reference', 'MIG-OPENING')->count() === 2
            && (float) StockMovement::where('inventory_item_id', $item->id)->where('reference', 'MIG-OPENING')->sum('qty') === 7.0);
    $assert('the changed opening movement reposts the GL by the revised value',
        abs(($updateInventoryNet - $beforeInventoryNet) - 147.50) < 0.01
            && abs(($updateOpeningEquityNet - $beforeOpeningEquityNet) + 147.50) < 0.01
            && $updateJournal !== null && abs((float) $updateJournal->lines()->sum('debit') - (float) $updateJournal->lines()->sum('credit')) < 0.01,
        'inventory net '.number_format($updateInventoryNet - $beforeInventoryNet, 2).', equity debit-minus-credit '.number_format($updateOpeningEquityNet - $beforeOpeningEquityNet, 2));

    Setting::updateOrCreate(['key' => 'gl_lock_date'], ['value' => $date]);
    $lockedBatch = $makeBatch('opening_stock');
    $lockedRows = [];
    foreach ($changedRows as $i => $payload) {
        $lockedRows[] = $addRow($lockedBatch, $payload, $i + 2);
    }
    $lockedDry = MigrationService::run($lockedBatch, true);
    $lockedApplied = MigrationService::run($lockedBatch, false);
    $lockedJournalId = $activeJournal($date)?->id;
    $assert('an exact unchanged re-import remains safe after the GL lock',
        $lockedDry['skipped'] === 2 && $lockedApplied['skipped'] === 2 && $lockedJournalId === $updateJournal?->id
            && StockMovement::where('inventory_item_id', $item->id)->where('reference', 'MIG-OPENING')->sum('qty') == 7.0);

    $lockedChangeRows = $changedRows;
    $lockedChangeRows[0]['quantity'] = '6.00';
    $lockedChangeBatch = $makeBatch('opening_stock');
    $lockedChangeOutcomes = [];
    foreach ($lockedChangeRows as $i => $payload) {
        $lockedChangeOutcomes[] = $addRow($lockedChangeBatch, $payload, $i + 2);
    }
    $lockedChangeTally = MigrationService::run($lockedChangeBatch, true);
    foreach ($lockedChangeOutcomes as $row) {
        $row->refresh();
    }
    $assert('a changed source row in the GL-locked period is rejected',
        $lockedChangeTally['failed'] === 1 && collect($lockedChangeOutcomes)->where('status', 'failed')->count() === 1
            && collect($lockedChangeOutcomes)->where('status', 'unchanged')->count() === 1);

    $newLockedRow = $changedRows[0];
    $newLockedRow['external_id'] = 'OS-NEW-LOCKED-'.$token;
    $newLockedBatch = $makeBatch('opening_stock');
    $newLockedOutcome = $addRow($newLockedBatch, $newLockedRow, 2);
    $newLockedTally = MigrationService::run($newLockedBatch, true);
    $newLockedOutcome->refresh();
    $assert('a new opening row in the GL-locked period is rejected',
        $newLockedTally['failed'] === 1 && $newLockedOutcome->status === 'failed');

    $equityLine = GlLine::where('gl_journal_id', $lockedJournalId)->where('account_head_id', $openingEquity)->first();
    if ($equityLine) {
        $originalCredit = (float) $equityLine->credit;
        $equityLine->credit = round($originalCredit - 0.01, 2);
        $equityLine->save();
        $badLockedBatch = $makeBatch('opening_stock');
        $badLockedRows = [];
        foreach ($changedRows as $i => $payload) {
            $badLockedRows[] = $addRow($badLockedBatch, $payload, $i + 2);
        }
        $badLockedTally = MigrationService::run($badLockedBatch, true);
        foreach ($badLockedRows as $row) {
            $row->refresh();
        }
        $assert('an unchanged locked row is rejected when its GL journal no longer reconciles',
            $badLockedTally['failed'] === 2 && collect($badLockedRows)->every(fn ($r) => $r->status === 'failed'));
        $equityLine->credit = $originalCredit;
        $equityLine->save();
    } else {
        $assert('locked journal has an Opening Balance Equity line to verify', false);
    }

    $badBatch = $makeBatch('opening_stock');
    $mixedA = $openingRows[0];
    $mixedB = $openingRows[1];
    $mixedB['external_id'] = 'OS-MIXED-'.$token;
    $mixedB['opening_date'] = Carbon::parse($date)->subDay()->toDateString();
    $badRows = [$addRow($badBatch, $mixedA, 2), $addRow($badBatch, $mixedB, 3)];
    $badTally = MigrationService::run($badBatch, true);
    foreach ($badRows as $row) {
        $row->refresh();
    }
    $assert('a mixed-date opening-stock file is rejected before row import',
        $badTally['failed'] === 2 && collect($badRows)->every(fn ($r) => $r->status === 'failed')
            && StockMovement::where('inventory_item_id', $item->id)->where('reference', 'MIG-OPENING')->count() === 2);

    $duplicate = MigrationService::importer('opening_stock')->validateBatch([
        $openingRows[0], array_merge($openingRows[1], ['item_external_id' => $itemExternal, 'external_id' => $openingRows[0]['external_id']]),
    ], new ImportContext($batch, $hotel));
    $assert('duplicate external row ids in one CSV are rejected', $duplicate !== null);

    $overLimit = MigrationService::importer('opening_stock')->validateBatch([
        array_merge($openingRows[0], ['external_id' => 'OS-LIMIT-A-'.$token, 'quantity' => '9999999999.99', 'unit_cost' => '100.00']),
        array_merge($openingRows[1], ['external_id' => 'OS-LIMIT-B-'.$token, 'quantity' => '9999999999.99', 'unit_cost' => '100.00']),
    ], new ImportContext($batch, $hotel));
    $assert('combined opening-stock value beyond GL precision is rejected',
        $overLimit !== null && str_contains($overLimit, 'general-ledger limit'));

    echo PHP_EOL;
} catch (\Throwable $e) {
    $failed++;
    echo '  FAIL  harness exception — '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')'.PHP_EOL;
} finally {
    DB::rollBack();
}

echo "Opening-stock migration: {$passed} passed, {$failed} failed (all DB changes rolled back).".PHP_EOL;
if ($failed > 0) {
    throw new \RuntimeException('Opening-stock migration harness failed.');
}
