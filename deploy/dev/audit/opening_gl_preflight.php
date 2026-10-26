<?php
/**
 * Full opening-trial-balance mapping and preflight regression harness.
 * It stages the CSV rows only; all database changes are rolled back, and no GL
 * journal is created. Posting is disabled pending AP-policy and subledger sign-off
 * plus control-account/duplicate-posting rules.
 *
 * Run: TINKER='require "/app/deploy/dev/audit/opening_gl_preflight.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Models\AccountHead;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\DataMigrationBatch;
use App\Models\DataMigrationIdMap;
use App\Models\DataMigrationRow;
use App\Models\GlJournal;
use App\Models\GlLine;
use App\Services\AssetService;
use App\Services\LedgerService;
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
$makeBatch = function () use ($hotel, $token, &$batchNo): DataMigrationBatch {
    $batchNo++;

    return DataMigrationBatch::create([
        'hotel_id' => $hotel->id,
        'code' => 'MIG-GL-'.$token.'-'.$batchNo,
        'dataset' => 'opening_gl',
        'source_name' => 'Opening GL preflight harness',
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

DB::beginTransaction();
try {
    Tenant::set($hotel);
    $date = LedgerService::cutoff();
    $lockDate = LedgerService::lockDate();
    if ($lockDate && $date <= $lockDate) {
        throw new RuntimeException('No eligible open, closed-ledger date is available for the preflight harness.');
    }

    $inventory = AccountHead::where('system_key', 'inventory')->first();
    $openingEquity = AccountHead::where('system_key', 'opening_equity')->first();
    if (! $inventory || ! $openingEquity || ! $inventory->code || ! $openingEquity->code) {
        throw new RuntimeException('Inventory and Opening Balance Equity must have account codes for this harness.');
    }
    $inventoryControl = collect(LedgerService::controls($date))->firstWhere('key', 'inventory');
    if (! $inventoryControl) {
        throw new RuntimeException('Inventory control balance could not be calculated.');
    }
    $inventoryCents = (int) round((float) $inventoryControl['sub'] * 100);
    if ($inventoryCents < 0) {
        throw new RuntimeException('The harness expects a non-negative inventory subledger valuation.');
    }
    $inventoryValue = number_format($inventoryCents / 100, 2, '.', '');

    $rows = [
        ['external_id' => 'GL-INV-'.$token, 'source_account_code' => 'LEG-INV', 'source_account_name' => 'Inventory', 'opening_date' => $date,
            'account_code' => $inventory->code, 'debit_balance' => $inventoryValue, 'credit_balance' => '0.00'],
        ['external_id' => 'GL-OBE-'.$token, 'source_account_code' => 'LEG-EQUITY', 'source_account_name' => 'Opening equity', 'opening_date' => $date,
            'account_code' => $openingEquity->code, 'debit_balance' => '0.00', 'credit_balance' => $inventoryValue],
    ];
    $beforeJournals = GlJournal::count();
    $beforeLines = GlLine::count();
    $importer = MigrationService::importer('opening_gl');

    $assert('opening AP and AR follow opening_stock and precede preflight-only opening_gl',
        array_search('opening_ap', MigrationService::ORDER, true) === array_search('opening_stock', MigrationService::ORDER, true) + 1
            && array_search('opening_ar', MigrationService::ORDER, true) === array_search('opening_ap', MigrationService::ORDER, true) + 1
            && array_search('opening_gl', MigrationService::ORDER, true) === array_search('opening_ar', MigrationService::ORDER, true) + 1);
    $assert('opening GL is explicitly preflight-only', ! $importer->supportsApply());
    $assert('preflight template contains a two-row sample pair', count($importer->templateRows()) === 2);
    $assert('the target-account template fields are explicit',
        isset($importer->columns()['account_code'], $importer->columns()['debit_balance'], $importer->columns()['credit_balance']));

    $batch = $makeBatch();
    $outcomes = $addRows($batch, $rows);
    $tally = MigrationService::run($batch, true);
    foreach ($outcomes as $outcome) {
        $outcome->refresh();
    }
    $assert('balanced mapped snapshot dry-runs successfully with no partial failures',
        $tally['created'] === 2 && $tally['failed'] === 0 && collect($outcomes)->every(fn ($r) => $r->status === 'would_create'),
        'created='.$tally['created'].' failed='.$tally['failed']);

    $report = MigrationService::report($batch);
    $assert('preflight report confirms inventory is reconciled to the stock subledger',
        ($report['Inventory reconciliation'] ?? null) === 'PASS — exact to the cent'
            && abs((float) str_replace(',', '', $report['Inventory in source trial balance'] ?? '0') - $inventoryCents / 100) < 0.01);
    $assert('preflight reports equal debit and credit adjustment totals',
        ($report['Provisional adjustment debit'] ?? null) === ($report['Provisional adjustment credit'] ?? null));
    $currentInventoryGlCents = (int) round(LedgerService::balance('inventory', $date) * 100);
    $inventoryDeltaCents = $inventoryCents - $currentInventoryGlCents;
    $expectedInventoryDelta = ($inventoryDeltaCents < 0 ? 'Cr ' : 'Dr ')
        .number_format(abs($inventoryDeltaCents) / 100, 2, '.', ',');
    $assert('provisional inventory delta subtracts the existing stock GL posting instead of duplicating it',
        ($report['Inventory provisional GL adjustment'] ?? null) === $expectedInventoryDelta);
    $inventoryLine = $report['Provisional account adjustment: '.$inventory->code.' — '.$inventory->name] ?? '';
    $equityLine = $report['Provisional account adjustment: '.$openingEquity->code.' — '.$openingEquity->name] ?? '';
    $assert('per-account adjustment preview shows source, current GL and proposed delta for Inventory and opening equity',
        str_contains($inventoryLine, 'Source balance') && str_contains($inventoryLine, 'current GL baseline')
            && str_contains($inventoryLine, 'proposed adjustment '.$expectedInventoryDelta)
            && str_contains($equityLine, 'Source balance') && str_contains($equityLine, 'proposed adjustment'));
    $assert('report clearly states that no opening balance was posted',
        str_contains((string) ($report['Posting status'] ?? ''), 'Not posted') && str_contains((string) ($report['Preflight status'] ?? ''), 'no posting occurred'));
    $assert('preflight creates no journal lines and no opening_gl id maps',
        GlJournal::count() === $beforeJournals && GlLine::count() === $beforeLines
            && DataMigrationIdMap::where('hotel_id', $hotel->id)->where('dataset', 'opening_gl')->count() === 0);

    $controlRows = [$rows[0]];
    $controlNet = $inventoryCents;
    $controlMap = collect(LedgerService::controls($date))->keyBy('key');
    foreach (['accounts_payable', 'guest_ledger', 'city_ledger'] as $index => $key) {
        $control = $controlMap->get($key);
        $head = AccountHead::where('system_key', $key)->first();
        if (! $control || ! $head || ! $head->code) {
            throw new RuntimeException("The {$key} control account is required for the opening-GL control reconciliation harness.");
        }
        $sourceNatural = (int) round((float) $control['sub'] * 100) + 100;
        $signed = $head->isDebitNature() ? $sourceNatural : -$sourceNatural;
        $controlNet += $signed;
        $controlRows[] = [
            'external_id' => 'GL-CTRL-'.$key.'-'.$token,
            'source_account_code' => 'LEG-CTRL-'.$key,
            'source_account_name' => $control['label'],
            'opening_date' => $date,
            'account_code' => $head->code,
            'debit_balance' => number_format(max(0, $signed) / 100, 2, '.', ''),
            'credit_balance' => number_format(max(0, -$signed) / 100, 2, '.', ''),
        ];
    }
    $equitySigned = -$controlNet;
    $controlRows[] = [
        'external_id' => 'GL-CTRL-OBE-'.$token,
        'source_account_code' => 'LEG-CTRL-EQUITY',
        'source_account_name' => 'Opening equity control-test balance',
        'opening_date' => $date,
        'account_code' => $openingEquity->code,
        'debit_balance' => number_format(max(0, $equitySigned) / 100, 2, '.', ''),
        'credit_balance' => number_format(max(0, -$equitySigned) / 100, 2, '.', ''),
    ];
    $controlBatch = $makeBatch();
    $controlOutcomes = $addRows($controlBatch, $controlRows);
    $controlTally = MigrationService::run($controlBatch, true);
    foreach ($controlOutcomes as $outcome) {
        $outcome->refresh();
    }
    $controlReport = MigrationService::report($controlBatch);
    $controlMismatchDetails = array_filter($controlReport, fn ($value, $key) =>
        (str_starts_with($key, 'Pending control: Accounts payable')
            || str_starts_with($key, 'Pending control: Guest ledger')
            || str_starts_with($key, 'Pending control: City ledger'))
        && str_contains((string) $value, 'difference 1.00.'), ARRAY_FILTER_USE_BOTH);
    $strictControlStatus = (string) ($controlReport['Strict subledger control reconciliation'] ?? '');
    $strictMismatchDetails = array_filter($controlReport, fn ($value, $key) =>
        str_starts_with($key, 'Strict control:')
        && (str_contains($key, 'Accounts payable') || str_contains($key, 'Guest ledger') || str_contains($key, 'City ledger'))
        && str_starts_with((string) $value, 'BLOCKED')
        && str_contains((string) $value, 'difference 1.00.'), ARRAY_FILTER_USE_BOTH);
    $assert('preflight exposes strict AP, guest and city-ledger variances as posting blockers',
        $controlTally['failed'] === 0
            && str_starts_with((string) ($controlReport['Preflight status'] ?? ''), 'PASS')
            && str_starts_with($strictControlStatus, 'BLOCKED')
            && str_starts_with((string) ($controlReport['Opening GL posting readiness'] ?? ''), 'BLOCKED')
            && count($controlMismatchDetails) === 3 && count($strictMismatchDetails) === 3,
        'strict status='.$strictControlStatus.' pending='.count($controlMismatchDetails).' strict details='.count($strictMismatchDetails));

    $matchedRows = [$rows[0]];
    $matchedNet = $inventoryCents;
    foreach (collect(LedgerService::controls($date))->filter(fn ($control) => ! empty($control['strict'])) as $control) {
        $head = AccountHead::where('system_key', $control['key'])->first();
        if (! $head || ! $head->code) {
            throw new RuntimeException("The {$control['key']} strict control account is required for the matched-control harness.");
        }
        $sourceNatural = (int) round((float) $control['sub'] * 100);
        $signed = $head->isDebitNature() ? $sourceNatural : -$sourceNatural;
        $matchedNet += $signed;
        $matchedRows[] = [
            'external_id' => 'GL-MATCH-'.$control['key'].'-'.$token,
            'source_account_code' => 'LEG-MATCH-'.$control['key'],
            'source_account_name' => $control['label'],
            'opening_date' => $date,
            'account_code' => $head->code,
            'debit_balance' => number_format(max(0, $signed) / 100, 2, '.', ''),
            'credit_balance' => number_format(max(0, -$signed) / 100, 2, '.', ''),
        ];
    }
    $matchedEquity = -$matchedNet;
    $matchedRows[] = [
        'external_id' => 'GL-MATCH-OBE-'.$token,
        'source_account_code' => 'LEG-MATCH-EQUITY',
        'source_account_name' => 'Opening equity matched-control balance',
        'opening_date' => $date,
        'account_code' => $openingEquity->code,
        'debit_balance' => number_format(max(0, $matchedEquity) / 100, 2, '.', ''),
        'credit_balance' => number_format(max(0, -$matchedEquity) / 100, 2, '.', ''),
    ];
    $matchedBatch = $makeBatch();
    $matchedOutcomes = $addRows($matchedBatch, $matchedRows);
    $matchedTally = MigrationService::run($matchedBatch, true);
    foreach ($matchedOutcomes as $outcome) {
        $outcome->refresh();
    }
    $matchedReport = MigrationService::report($matchedBatch);
    $matchedStrictDetails = array_filter($matchedReport, fn ($value, $key) =>
        str_starts_with($key, 'Strict control:')
        && (str_contains($key, 'Accounts payable') || str_contains($key, 'Guest ledger') || str_contains($key, 'City ledger'))
        && str_starts_with((string) $value, 'MATCH')
        && str_contains((string) $value, 'difference 0.00.'), ARRAY_FILTER_USE_BOTH);
    $assert('matching strict controls report zero differences but do not enable posting',
        $matchedTally['failed'] === 0
            && str_starts_with((string) ($matchedReport['Strict subledger control reconciliation'] ?? ''), 'PASS')
            && str_starts_with((string) ($matchedReport['Opening GL posting readiness'] ?? ''), 'BLOCKED')
            && count($matchedStrictDetails) === 3
            && GlJournal::count() === $beforeJournals && GlLine::count() === $beforeLines);
    $assert('control-variance preflight remains non-posting',
        GlJournal::count() === $beforeJournals && GlLine::count() === $beforeLines);

    // ------------------------------------------------------------------ acc_depreciation contra-asset sign regression
    // acc_depreciation is type=asset but credit-nature (contra-asset). ChartOfAccounts classifies it
    // as asset, so isDebitNature() is true, yet the control subledger ($fa[1]) and the LedgerService
    // controls() gl field (-balance()) both treat it as credit-positive. buildAnalysis() must negate
    // the debit-minus-credit source net so the natural sign matches the subledger for this account.
    $depCategory = AssetCategory::first();
    if (! $depCategory) {
        throw new RuntimeException('An asset category is required for the acc_depreciation sign regression harness.');
    }
    $depAsset = Asset::create([
        'hotel_id' => $hotel->id,
        'asset_category_id' => $depCategory->id,
        'asset_no' => 'DEP-REG-'.$token,
        'name' => 'Depreciation sign regression asset',
        'purchase_date' => $date,
        'dep_start' => $date,
        'cost' => 1000.00,
        'opening_acc_dep' => 250.00,
        'method' => 'slm',
        'life_months' => 60,
        'status' => 'active',
        'acquired_via' => 'opening',
    ]);
    $accDepHead = AccountHead::where('system_key', 'acc_depreciation')->first();
    $fixedAssetsHead = AccountHead::where('system_key', 'fixed_assets')->first();
    if (! $accDepHead || ! $fixedAssetsHead || ! $accDepHead->code || ! $fixedAssetsHead->code) {
        throw new RuntimeException('acc_depreciation and fixed_assets heads are required for the contra-asset sign regression.');
    }
    $depControl = collect(LedgerService::controls($date))->firstWhere('key', 'acc_depreciation');
    $faControl = collect(LedgerService::controls($date))->firstWhere('key', 'fixed_assets');
    if (! $depControl || ! $faControl) {
        throw new RuntimeException('acc_depreciation and fixed_assets control balances could not be calculated.');
    }
    $accDepSubledgerCents = (int) round((float) $depControl['sub'] * 100);
    $faSubledgerCents = (int) round((float) $faControl['sub'] * 100);
    // Balanced source: inventory Dr + fixed_assets Dr, with acc_depreciation Cr and opening_equity Cr.
    $depRows = [
        ['external_id' => 'GL-DEP-INV-'.$token, 'source_account_code' => 'LEG-DEP-INV', 'source_account_name' => 'Inventory',
            'opening_date' => $date, 'account_code' => $inventory->code, 'debit_balance' => $inventoryValue, 'credit_balance' => '0.00'],
        ['external_id' => 'GL-DEP-FA-'.$token, 'source_account_code' => 'LEG-DEP-FA', 'source_account_name' => 'Fixed assets',
            'opening_date' => $date, 'account_code' => $fixedAssetsHead->code, 'debit_balance' => number_format($faSubledgerCents / 100, 2, '.', ''), 'credit_balance' => '0.00'],
        ['external_id' => 'GL-DEP-AD-'.$token, 'source_account_code' => 'LEG-DEP-AD', 'source_account_name' => 'Accumulated depreciation',
            'opening_date' => $date, 'account_code' => $accDepHead->code, 'debit_balance' => '0.00', 'credit_balance' => number_format($accDepSubledgerCents / 100, 2, '.', '')],
        ['external_id' => 'GL-DEP-OBE-'.$token, 'source_account_code' => 'LEG-DEP-EQUITY', 'source_account_name' => 'Opening equity',
            'opening_date' => $date, 'account_code' => $openingEquity->code,
            'debit_balance' => '0.00', 'credit_balance' => number_format((($inventoryCents + $faSubledgerCents) - $accDepSubledgerCents) / 100, 2, '.', '')],
    ];
    $depBatch = $makeBatch();
    $depOutcomes = $addRows($depBatch, $depRows);
    $depTally = MigrationService::run($depBatch, true);
    foreach ($depOutcomes as $outcome) {
        $outcome->refresh();
    }
    $depReport = MigrationService::report($depBatch);
    $depPendingDetails = array_filter($depReport, fn ($value, $key) =>
        str_starts_with($key, 'Pending control: Accumulated depreciation'), ARRAY_FILTER_USE_BOTH);
    $assert('acc_depreciation contra-asset source net is negated to match the credit-positive subledger',
        $depTally['failed'] === 0
            && str_starts_with((string) ($depReport['Preflight status'] ?? ''), 'PASS')
            && count($depPendingDetails) === 0,
        'failed='.($depTally['failed'] ?? '?').' status='.($depReport['Preflight status'] ?? '?').' pending='.count($depPendingDetails));
    $assert('acc_depreciation sign regression preflight remains non-posting',
        GlJournal::count() === $beforeJournals && GlLine::count() === $beforeLines);

    $applyBlocked = false;
    try {
        MigrationService::run($batch, false);
    } catch (ValidationException $e) {
        $applyBlocked = str_contains((string) ($e->errors()['batch'][0] ?? ''), 'preflight-only');
    }
    $assert('service-layer apply is blocked even if called directly', $applyBlocked);
    $assert('blocked apply leaves GL unchanged', GlJournal::count() === $beforeJournals && GlLine::count() === $beforeLines);

    $unbalancedRows = $rows;
    $unbalancedRows[0]['debit_balance'] = number_format(($inventoryCents + 100) / 100, 2, '.', '');
    $unbalancedBatch = $makeBatch();
    $unbalancedOutcomes = $addRows($unbalancedBatch, $unbalancedRows);
    $unbalanced = MigrationService::run($unbalancedBatch, true);
    foreach ($unbalancedOutcomes as $outcome) {
        $outcome->refresh();
    }
    $assert('unbalanced source TB is rejected atomically without an equity plug',
        $unbalanced['failed'] === 2 && collect($unbalancedOutcomes)->every(fn ($r) => $r->status === 'failed')
            && str_contains((string) $unbalancedOutcomes[0]->message, 'not balanced'));

    $mismatchRows = $rows;
    $mismatchRows[0]['debit_balance'] = number_format(($inventoryCents + 100) / 100, 2, '.', '');
    $mismatchRows[1]['credit_balance'] = number_format(($inventoryCents + 100) / 100, 2, '.', '');
    $mismatchBatch = $makeBatch();
    $mismatchOutcomes = $addRows($mismatchBatch, $mismatchRows);
    $mismatch = MigrationService::run($mismatchBatch, true);
    foreach ($mismatchOutcomes as $outcome) {
        $outcome->refresh();
    }
    $assert('balanced TB with an inventory/subledger mismatch is rejected atomically',
        $mismatch['failed'] === 2 && collect($mismatchOutcomes)->every(fn ($r) => $r->status === 'failed')
            && str_contains((string) $mismatchOutcomes[0]->message, 'Inventory does not reconcile'));

    $badMapRows = $rows;
    $badMapRows[1]['account_code'] = 'NO-SUCH-CODE';
    $badMapBatch = $makeBatch();
    $badMapOutcomes = $addRows($badMapBatch, $badMapRows);
    $badMap = MigrationService::run($badMapBatch, true);
    foreach ($badMapOutcomes as $outcome) {
        $outcome->refresh();
    }
    $assert('unknown target Nowills account code blocks the entire snapshot',
        $badMap['failed'] === 2 && collect($badMapOutcomes)->every(fn ($r) => $r->status === 'failed')
            && str_contains((string) $badMapOutcomes[0]->message, 'not found'));

    $duplicateRows = [$rows[0], array_merge($rows[1], ['external_id' => $rows[0]['external_id']])];
    $duplicateError = $importer->validateBatch($duplicateRows, new ImportContext($batch, $hotel));
    $assert('duplicate source account ids are rejected', $duplicateError !== null && str_contains($duplicateError, 'appears more than once'));

    $mixedDateRows = [$rows[0], array_merge($rows[1], ['opening_date' => date('Y-m-d', strtotime($date.' -1 day'))])];
    $mixedDateError = $importer->validateBatch($mixedDateRows, new ImportContext($batch, $hotel));
    $assert('mixed as-of dates are rejected before preflight', $mixedDateError !== null && str_contains($mixedDateError, 'one opening_date'));

    $badPrecision = $importer->validateRow(array_merge($rows[0], ['debit_balance' => '12.345']), new ImportContext($batch, $hotel), 2);
    $assert('amounts above two-decimal GL precision are rejected', $badPrecision !== null && str_contains($badPrecision, 'debit_balance'));

    $afterJournals = GlJournal::count();
    $afterLines = GlLine::count();
    $assert('all preflight and failure scenarios leave the live GL untouched', $afterJournals === $beforeJournals && $afterLines === $beforeLines);
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL  harness exception — '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')'.PHP_EOL;
} finally {
    DB::rollBack();
}

echo PHP_EOL."Opening GL preflight: {$passed} passed, {$failed} failed (all DB changes rolled back; no GL posting).".PHP_EOL;
if ($failed > 0) {
    throw new RuntimeException('Opening GL preflight regression harness failed.');
}
