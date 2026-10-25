<?php
/**
 * Inventory-allergen source preflight harness. Every fixture is rolled back;
 * it never changes live allergen declarations, stock, GL, or migration ID maps.
 *
 * Run: TINKER='require "/app/deploy/dev/audit/inventory_allergen_preflight.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Models\DataMigrationBatch;
use App\Models\DataMigrationIdMap;
use App\Models\DataMigrationRow;
use App\Models\GlJournal;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Services\Migration\ImportContext;
use App\Services\Migration\MigrationService;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
$makeBatch = function (string $dataset = 'inventory_allergens') use ($hotel, $token, &$batchNo): DataMigrationBatch {
    $batchNo++;

    return DataMigrationBatch::create([
        'hotel_id' => $hotel->id,
        'code' => 'MIG-AF-'.$token.'-'.$batchNo,
        'dataset' => $dataset,
        'source_name' => 'Inventory allergen preflight harness',
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
    $firstItem = InventoryItem::create([
        'code' => 'AIP-'.$token.'-1',
        'name' => 'Allergen Harness Item One '.$token,
        'unit' => 'kg',
        'last_cost' => 5,
        'allergens' => ['eggs'],
    ]);
    $secondItem = InventoryItem::create([
        'code' => 'AIP-'.$token.'-2',
        'name' => 'Allergen Harness Item Two '.$token,
        'unit' => 'kg',
        'last_cost' => 8,
        'allergens' => null,
    ]);
    $itemBatch = $makeBatch('inventory_items');
    $firstExternal = 'ALLERGEN-ITEM-1-'.$token;
    $secondExternal = 'ALLERGEN-ITEM-2-'.$token;
    foreach ([[$firstExternal, $firstItem], [$secondExternal, $secondItem]] as [$externalId, $item]) {
        DataMigrationIdMap::create([
            'hotel_id' => $hotel->id,
            'dataset' => 'inventory_items',
            'external_id' => $externalId,
            'model_type' => InventoryItem::class,
            'model_id' => $item->id,
            'batch_id' => $itemBatch->id,
        ]);
    }

    $payloads = [
        ['external_id' => 'ALLERGEN-DECL-1-'.$token, 'item_external_id' => $firstExternal, 'allergens' => 'GL|milk'],
        ['external_id' => 'ALLERGEN-DECL-2-'.$token, 'item_external_id' => $secondExternal, 'allergens' => 'none'],
    ];
    $batch = $makeBatch();
    $outcomes = $addRows($batch, $payloads);
    $beforeStock = StockMovement::count();
    $beforeJournals = GlJournal::count();
    $beforeMaps = DataMigrationIdMap::where('hotel_id', $hotel->id)->where('dataset', 'inventory_allergens')->count();
    $importer = MigrationService::importer('inventory_allergens');

    $order = MigrationService::ORDER;
    $assert('inventory_allergens is registered after stock-history preflight and before reservations',
        array_search('inventory_allergens', $order, true) === array_search('historical_stock', $order, true) + 1
            && array_search('reservations', $order, true) > array_search('inventory_allergens', $order, true));
    $assert('inventory allergen data is explicitly preflight-only', ! $importer->supportsApply());
    $assert('template covers stable source ids, mapped item ids and explicit allergens',
        count($importer->templateRows()) === 2
            && isset($importer->columns()['external_id'], $importer->columns()['item_external_id'], $importer->columns()['allergens']));
    $assert('mapped inventory items accept a canonical key, matrix code and explicit none',
        $importer->validateRow($payloads[0], new ImportContext($batch, $hotel), 2) === null
            && $importer->validateRow($payloads[1], new ImportContext($batch, $hotel), 3) === null);

    $tally = MigrationService::run($batch, true);
    foreach ($outcomes as $outcome) {
        $outcome->refresh();
    }
    $firstItem->refresh();
    $secondItem->refresh();
    $report = MigrationService::report($batch);
    $assert('dry-run validates both declarations without changing inventory or creating maps, stock or GL',
        $tally['created'] === 2 && $tally['failed'] === 0
            && collect($outcomes)->every(fn ($row) => $row->status === 'would_create')
            && $firstItem->allergens === ['eggs'] && $secondItem->allergens === null
            && StockMovement::count() === $beforeStock
            && GlJournal::count() === $beforeJournals
            && DataMigrationIdMap::where('hotel_id', $hotel->id)->where('dataset', 'inventory_allergens')->count() === $beforeMaps);
    $assert('report counts canonical allergens and explicit none without claiming verification',
        ($report['Source rows with an explicit none declaration'] ?? null) === 1
            && ($report['Declaration row counts by allergen'] ?? null) === 'Gluten (gluten): 1; Milk (milk): 1'
            && str_contains((string) ($report['Food-safety verification'] ?? ''), 'Not performed'));
    $assert('report states zero allergen updates and no GL impact',
        str_contains((string) ($report['Inventory allergen declarations written'] ?? ''), 'None')
            && str_contains((string) ($report['GL impact'] ?? ''), 'None'));

    $applyBlocked = false;
    try {
        MigrationService::run($batch, false);
    } catch (ValidationException $e) {
        $applyBlocked = str_contains((string) ($e->errors()['batch'][0] ?? ''), 'inventory_allergens is currently preflight-only');
    }
    $assert('service-layer apply is blocked for inventory allergen declarations', $applyBlocked);
    $assert('blocked apply leaves inventory, maps, stock and GL unchanged',
        $firstItem->fresh()->allergens === ['eggs'] && $secondItem->fresh()->allergens === null
            && StockMovement::count() === $beforeStock
            && GlJournal::count() === $beforeJournals
            && DataMigrationIdMap::where('hotel_id', $hotel->id)->where('dataset', 'inventory_allergens')->count() === $beforeMaps);

    $unknown = $importer->validateRow(array_merge($payloads[0], ['allergens' => 'gluten|mystery']), new ImportContext($batch, $hotel), 2);
    $assert('unknown allergen labels are rejected rather than silently discarded', $unknown !== null && str_contains($unknown, 'Unknown allergen value mystery'));

    $duplicateCode = $importer->validateRow(array_merge($payloads[0], ['allergens' => 'gluten|GL']), new ImportContext($batch, $hotel), 2);
    $assert('duplicate canonical/code aliases in one declaration are rejected', $duplicateCode !== null && str_contains($duplicateCode, 'appears more than once'));

    $mixedNone = $importer->validateRow(array_merge($payloads[0], ['allergens' => 'none|milk']), new ImportContext($batch, $hotel), 2);
    $assert('none cannot be mixed with allergen keys', $mixedNone !== null && str_contains($mixedNone, 'none must be the only'));

    $blank = $importer->validateRow(array_merge($payloads[0], ['allergens' => '']), new ImportContext($batch, $hotel), 2);
    $assert('blank declarations are blocked; none must be explicit', $blank !== null && str_contains($blank, 'use none only when the source explicitly records'));

    $missingItem = $importer->validateRow(array_merge($payloads[0], ['item_external_id' => 'NO-ALLERGEN-ITEM']), new ImportContext($batch, $hotel), 2);
    $assert('unmapped inventory items block allergen preflight', $missingItem !== null && str_contains($missingItem, 'is not mapped'));

    $duplicateItemRows = [$payloads[0], array_merge($payloads[1], ['item_external_id' => strtolower($firstExternal)])];
    $duplicateItemError = $importer->validateBatch($duplicateItemRows, new ImportContext($batch, $hotel));
    $assert('duplicate item declarations in a single file are rejected',
        $duplicateItemError !== null && str_contains($duplicateItemError, 'one declaration per inventory item'));

    $duplicateSourceRows = [$payloads[0], array_merge($payloads[1], ['external_id' => $payloads[0]['external_id']])];
    $duplicateSourceError = $importer->validateBatch($duplicateSourceRows, new ImportContext($batch, $hotel));
    $assert('duplicate declaration source ids are rejected',
        $duplicateSourceError !== null && str_contains($duplicateSourceError, 'appears more than once'));

    $assert('all fixture rows roll back without changing live allergen, stock or GL data',
        $firstItem->fresh()->allergens === ['eggs'] && $secondItem->fresh()->allergens === null
            && StockMovement::count() === $beforeStock
            && GlJournal::count() === $beforeJournals
            && DataMigrationIdMap::where('hotel_id', $hotel->id)->where('dataset', 'inventory_allergens')->count() === $beforeMaps);
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL  harness exception — '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')'.PHP_EOL;
} finally {
    DB::rollBack();
}

echo PHP_EOL."Inventory allergen preflight: {$passed} passed, {$failed} failed (all DB changes rolled back; no allergen data was imported or verified).".PHP_EOL;
if ($failed > 0) {
    throw new RuntimeException('Inventory allergen preflight harness failed.');
}
