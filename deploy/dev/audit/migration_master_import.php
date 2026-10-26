<?php
/**
 * Supplier and inventory master import regression harness.
 * Everything runs in a transaction which is rolled back, leaving the demo DB unchanged.
 *
 * Run: TINKER='require "/app/deploy/dev/audit/migration_master_import.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Models\DataMigrationBatch;
use App\Models\DataMigrationRow;
use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Models\Supplier;
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

    return DataMigrationBatch::create([
        'hotel_id' => $hotel->id,
        'code' => 'MIG-TST-'.$token.'-'.$batchNo,
        'dataset' => $dataset,
        'source_name' => 'Disposable master importer harness',
        'status' => 'registered',
        'rows_total' => 1,
    ]);
};
$addRow = function (DataMigrationBatch $batch, array $payload): DataMigrationRow {
    return DataMigrationRow::create([
        'hotel_id' => $batch->hotel_id,
        'batch_id' => $batch->id,
        'row_no' => 2,
        'external_id' => $payload['external_id'] ?? null,
        'status' => 'pending',
        'payload' => $payload,
    ]);
};

DB::beginTransaction();
try {
    $supplierExternal = 'AUD-SUP-'.$token;
    $supplierName = 'Migration Harness Supplier '.$token;
    $supplierTin = 'TEST-TIN-'.$token;
    $supplierRow = [
        'external_id' => $supplierExternal,
        'name' => $supplierName,
        'contact_person' => 'Test Contact',
        'phone' => '+233200000000',
        'email' => 'migration-'.$token.'@example.com',
        'tax_number' => $supplierTin,
        'payment_terms_days' => '45',
        'wht_category' => 'goods',
        'is_local' => '1',
        'is_active' => '1',
    ];
    $supplierBatch = $makeBatch('suppliers');
    $supplierOutcome = $addRow($supplierBatch, $supplierRow);
    $supplierImporter = MigrationService::importer('suppliers');
    $supplierCtx = new \App\Services\Migration\ImportContext($supplierBatch, $hotel);
    $assert('Supplier WHT category rejects unknown value', $supplierImporter->validateRow(array_merge($supplierRow, ['wht_category' => 'invalid']), $supplierCtx, 2) !== null);
    $assert('Supplier rejects malformed active flags', $supplierImporter->validateRow(array_merge($supplierRow, ['is_active' => 'active']), $supplierCtx, 2) !== null);
    MigrationService::run($supplierBatch, true);
    $supplierOutcome->refresh();
    $assert('Supplier dry-run records would_create', $supplierOutcome->status === 'would_create');
    $assert('Supplier dry-run creates nothing', ! Supplier::where('tax_number', $supplierTin)->exists());
    $supplierTally = MigrationService::run($supplierBatch, false);
    $supplierOutcome->refresh();
    $supplier = Supplier::where('tax_number', $supplierTin)->first();
    $assert('Supplier apply creates one row', $supplierTally['created'] === 1 && $supplierOutcome->status === 'created' && $supplier !== null);
    $assert('Supplier fields map to terms, WHT and local-sourcing attributes', $supplier && (int) $supplier->payment_terms_days === 45 && $supplier->wht_category === 'goods' && $supplier->is_local === true);
    $blankSupplier = ['external_id' => $supplierExternal, 'name' => $supplierName, 'contact_person' => '', 'phone' => '', 'email' => '', 'address' => '', 'tax_number' => '', 'payment_terms_days' => '', 'wht_category' => '', 'wht_exempt_ref' => '', 'wht_exempt_until' => '', 'is_local' => '', 'is_active' => ''];
    $blankSupplierBatch = $makeBatch('suppliers');
    $blankSupplierOutcome = $addRow($blankSupplierBatch, $blankSupplier);
    MigrationService::run($blankSupplierBatch, true);
    $blankSupplierOutcome->refresh();
    MigrationService::run($blankSupplierBatch, false);
    $supplier->refresh();
    $assert('Blank supplier cells preserve existing TIN, payment terms, WHT and status', $blankSupplierOutcome->status === 'unchanged' && $supplier->tax_number === $supplierTin && (int) $supplier->payment_terms_days === 45 && $supplier->wht_category === 'goods' && $supplier->is_local === true);

    $itemExternal = 'AUD-ITEM-'.$token;
    $itemCode = 'AUD-'.$token;
    $itemName = 'Migration Harness Item '.$token;
    $categoryName = 'Migration Harness Category '.$token;
    $itemRow = [
        'external_id' => $itemExternal,
        'name' => $itemName,
        'code' => $itemCode,
        'category' => $categoryName,
        'unit' => 'bottle',
        'reorder_level' => '24',
        'last_cost' => '12.50',
        'tax_rate' => '15',
        'yield_pct' => '92.5',
        'is_active' => '1',
    ];
    $itemBatch = $makeBatch('inventory_items');
    $itemOutcome = $addRow($itemBatch, $itemRow);
    $itemImporter = MigrationService::importer('inventory_items');
    $ctx = new \App\Services\Migration\ImportContext($itemBatch, $hotel);
    $assert('Inventory importer rejects yield outside its valid range', $itemImporter->validateRow(array_merge($itemRow, ['yield_pct' => '0']), $ctx, 2) !== null);
    $assert('Inventory importer rejects malformed active flags', $itemImporter->validateRow(array_merge($itemRow, ['is_active' => 'active']), $ctx, 2) !== null);
    MigrationService::run($itemBatch, true);
    $itemOutcome->refresh();
    $assert('Inventory dry-run records would_create', $itemOutcome->status === 'would_create');
    $assert('Inventory dry-run creates neither item nor category', ! InventoryItem::where('code', $itemCode)->exists() && ! ItemCategory::where('name', $categoryName)->exists());
    $itemTally = MigrationService::run($itemBatch, false);
    $itemOutcome->refresh();
    $item = InventoryItem::where('code', $itemCode)->first();
    $assert('Inventory apply creates item and category', $itemTally['created'] === 1 && $itemOutcome->status === 'created' && $item && $item->category?->name === $categoryName);
    $assert('Inventory numeric fields and unit map correctly', $item && (float) $item->reorder_level === 24.0 && (float) $item->last_cost === 12.5 && (float) $item->tax_rate === 15.0 && (float) $item->yield_pct === 92.5 && $item->unit === 'bottle');
    $blankItem = ['external_id' => $itemExternal, 'name' => $itemName, 'code' => '', 'category' => '', 'unit' => '', 'reorder_level' => '', 'last_cost' => '', 'tax_rate' => '', 'yield_pct' => '', 'is_active' => ''];
    $blankItemBatch = $makeBatch('inventory_items');
    $blankItemOutcome = $addRow($blankItemBatch, $blankItem);
    MigrationService::run($blankItemBatch, true);
    $blankItemOutcome->refresh();
    MigrationService::run($blankItemBatch, false);
    $item->refresh();
    $assert('Blank inventory cells preserve code, category, cost and stock settings', $blankItemOutcome->status === 'unchanged' && $item->code === $itemCode && $item->item_category_id === ItemCategory::where('name', $categoryName)->value('id') && (float) $item->last_cost === 12.5 && (float) $item->yield_pct === 92.5);

    $retryBatch = $makeBatch('inventory_items');
    $retryRow = $addRow($retryBatch, $itemRow);
    MigrationService::run($retryBatch, true);
    $retryRow->refresh();
    $assert('Inventory external-id re-run is unchanged', $retryRow->status === 'unchanged');

    $updatedRow = $itemRow;
    $updatedRow['last_cost'] = '13.00';
    $updateBatch = $makeBatch('inventory_items');
    $updateOutcome = $addRow($updateBatch, $updatedRow);
    MigrationService::run($updateBatch, true);
    $updateOutcome->refresh();
    $assert('Inventory dry-run identifies changed fields', $updateOutcome->status === 'would_update');
    MigrationService::run($updateBatch, false);
    $item->refresh();
    $assert('Inventory apply updates mapped item', (float) $item->last_cost === 13.0);
} catch (\Throwable $e) {
    $failed++;
    echo '  FAIL  Harness exception — '.$e->getMessage().PHP_EOL;
} finally {
    DB::rollBack();
}

echo PHP_EOL."Migration master import: {$passed} passed, {$failed} failed (all DB changes rolled back).".PHP_EOL;
if ($failed > 0) {
    throw new \RuntimeException('Migration master importer regression harness failed.');
}
