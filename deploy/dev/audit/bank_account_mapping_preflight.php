<?php
/**
 * Read-only Treasury/bank-account mapping preflight acceptance harness.
 * All DB fixtures are rolled back; temporary CSV and stored source file are removed.
 *
 * Run: TINKER='require "/app/deploy/dev/audit/bank_account_mapping_preflight.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Http\Controllers\DataMigrationController;
use App\Models\AccountHead;
use App\Models\BankStatement;
use App\Models\DataMigrationBatch;
use App\Models\DataMigrationIdMap;
use App\Models\DataMigrationRow;
use App\Models\GlJournal;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Services\BankRecService;
use App\Services\Migration\ImportContext;
use App\Services\Migration\MigrationService;
use App\Services\TreasuryService;
use App\Support\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;

$passed = 0;
$failed = 0;
$storedFiles = [];
$tmpFiles = [];
$assert = function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo '  ok    '.$label.PHP_EOL;
    } else {
        $failed++;
        echo '  FAIL  '.$label.($detail !== '' ? ' — '.$detail : '').PHP_EOL;
    }
};
$makeCsv = function (string $csv) use (&$tmpFiles): string {
    $path = tempnam(sys_get_temp_dir(), 'bank-map-');
    if ($path === false) {
        throw new RuntimeException('Could not create a temporary CSV file.');
    }
    file_put_contents($path, $csv);
    $tmpFiles[] = $path;

    return $path;
};

$hotel = Tenant::hotel();
$token = (string) random_int(1000, 9999);
DB::beginTransaction();
try {
    Tenant::set($hotel);
    $renderUser = User::first();
    if (! $renderUser) {
        throw new RuntimeException('The harness requires the seeded development user to render the migration page.');
    }
    auth()->login($renderUser);
    TreasuryService::ensureDefaults();
    $bankHead = AccountHead::find(AccountHead::sys('bank'));
    $bankTreasury = $bankHead
        ? TreasuryAccount::where('account_head_id', $bankHead->id)->where('is_active', true)->first()
        : null;
    if (! $bankHead || ! $bankTreasury || ! $bankHead->code || ! BankRecService::isReconcilable((int) $bankHead->id)) {
        throw new RuntimeException('The harness requires the normal active, reconcilable Bank Treasury account.');
    }
    $walletHead = AccountHead::find(AccountHead::sys('wallet_clearing'));
    $walletTreasury = $walletHead
        ? TreasuryAccount::where('account_head_id', $walletHead->id)->where('is_active', true)->first()
        : null;
    if (! $walletHead || ! $walletTreasury || ! $walletHead->code) {
        throw new RuntimeException('The harness requires the normal active Mobile Money clearing account.');
    }
    $cashHead = AccountHead::find(AccountHead::sys('cash'));
    if (! $cashHead || ! $cashHead->code) {
        throw new RuntimeException('The harness requires the normal Cash in Hand account.');
    }

    $importer = MigrationService::importer('bank_account_mappings');
    $basePayload = [
        'external_id' => 'LEGACY-BANK-'.$token,
        'source_account_name' => 'Legacy Operating Account',
        'source_kind' => 'bank',
        'target_account_code' => (string) $bankHead->code,
    ];
    $safeCsvPath = $makeCsv("external_id,source_account_name,source_kind,target_account_code\n{$basePayload['external_id']},{$basePayload['source_account_name']},{$basePayload['source_kind']},{$basePayload['target_account_code']}\n");

    $batchCountBefore = DataMigrationBatch::where('hotel_id', $hotel->id)->count();
    $batch = MigrationService::register($hotel, 'bank_account_mappings', $safeCsvPath, 'Legacy Treasury mapping review', 'legacy-export.csv');
    $storedFiles[] = $batch->file_path;
    $ctx = new ImportContext($batch, $hotel);

    $assert('dataset is ordered before the final paid-history archive, has four fields and remains Apply-disabled',
        isset(MigrationService::DATASETS['bank_account_mappings'])
            && array_search('bank_account_mappings', MigrationService::ORDER, true) === count(MigrationService::ORDER) - 2
            && array_search('paid_supplier_history', MigrationService::ORDER, true) === count(MigrationService::ORDER) - 1
            && array_keys($importer->columns()) === ['external_id', 'source_account_name', 'source_kind', 'target_account_code']
            && ! $importer->supportsApply());
    $assert('uploaded source file is retained under a neutral filename and contains only the supported mapping columns',
        str_ends_with((string) $batch->file_path, '/bank-account-mappings.csv')
            && str_contains((string) Storage::get($batch->file_path), 'target_account_code')
            && ! str_contains((string) Storage::get($batch->file_path), 'account_no'));
    $assert('valid bank source ID maps to an existing active bank Treasury account without guessing',
        $importer->validateRow($basePayload, $ctx, 2) === null
            && $importer->validateBatch([$basePayload], $ctx) === null);

    $walletPayload = [
        'external_id' => 'LEGACY-MOMO-'.$token,
        'source_account_name' => 'Legacy Mobile Money Clearing',
        'source_kind' => 'momo',
        'target_account_code' => (string) $walletHead->code,
    ];
    $assert('Mobile Money source kind matches its existing reconcilable Treasury account',
        $importer->validateRow($walletPayload, $ctx, 3) === null);
    $wrongKind = $basePayload;
    $wrongKind['source_kind'] = 'momo';
    $assert('source-kind mismatch is blocked',
        str_contains((string) $importer->validateRow($wrongKind, $ctx, 2), 'does not match'));
    $unsupportedKind = $basePayload;
    $unsupportedKind['source_kind'] = 'cash';
    $assert('cash and unsupported account types are not accepted as bank statement sources',
        str_contains((string) $importer->validateRow($unsupportedKind, $ctx, 2), 'must be one of'));
    $cashPayload = $basePayload;
    $cashPayload['target_account_code'] = (string) $cashHead->code;
    $cashPayload['source_account_name'] = 'Cash till';
    $assert('cash-in-hand GL account is not accepted as a reconcilable bank target',
        str_contains((string) $importer->validateRow($cashPayload, $ctx, 2), 'does not match')
            || str_contains((string) $importer->validateRow($cashPayload, $ctx, 2), 'not enabled for bank-statement reconciliation'));
    $unknownTarget = $basePayload;
    $unknownTarget['target_account_code'] = 'ZZZ123';
    $assert('unknown target account codes fail without account creation or name-based matching',
        str_contains((string) $importer->validateRow($unknownTarget, $ctx, 2), 'was not found'));

    $duplicateId = $walletPayload;
    $duplicateId['external_id'] = strtolower($basePayload['external_id']);
    $assert('duplicate legacy IDs are rejected case-insensitively',
        str_contains((string) $importer->validateBatch([$basePayload, $duplicateId], $ctx), 'appears more than once'));
    $duplicateTarget = $walletPayload;
    $duplicateTarget['target_account_code'] = $basePayload['target_account_code'];
    $assert('two source bank statements cannot collapse to one Nowills GL/Treasury target',
        str_contains((string) $importer->validateBatch([$basePayload, $duplicateTarget], $ctx), 'assigned more than once'));
    $assert('long numeric runs in source IDs or labels are rejected before source-file retention',
        $importer->sourceDataSafetyError(['external_id' => '1234567890', 'source_account_name' => 'Legacy Bank']) !== null
            && $importer->sourceDataSafetyError(['external_id' => 'LEGACY-1', 'source_account_name' => 'Main 1234 5678']) !== null);

    $badHeaderPath = $makeCsv("external_id,source_account_name,source_kind,target_account_code,account_no\nLEGACY-BAD,Legacy Bank,bank,{$bankHead->code},123456789012\n");
    $headerRejected = false;
    try {
        MigrationService::register($hotel, 'bank_account_mappings', $badHeaderPath, 'Legacy mapping', 'bad.csv');
    } catch (ValidationException $e) {
        $headerRejected = str_contains((string) ($e->errors()['file'][0] ?? ''), 'only the four documented mapping columns');
    }
    $assert('CSV headers containing bank-account details or any extra column are rejected before batch creation',
        $headerRejected && DataMigrationBatch::where('hotel_id', $hotel->id)->count() === $batchCountBefore + 1);

    $numericValuePath = $makeCsv("external_id,source_account_name,source_kind,target_account_code\n123456789012,Legacy Bank,bank,{$bankHead->code}\n");
    $numericRejected = false;
    try {
        MigrationService::register($hotel, 'bank_account_mappings', $numericValuePath, 'Legacy mapping', 'numeric.csv');
    } catch (ValidationException $e) {
        $numericRejected = str_contains((string) ($e->errors()['file'][0] ?? ''), 'looks like it contains a bank or card number');
    }
    $assert('sensitive-looking numeric source identifiers are rejected before batch creation',
        $numericRejected && DataMigrationBatch::where('hotel_id', $hotel->id)->count() === $batchCountBefore + 1);

    $badLabelRejected = false;
    try {
        MigrationService::register($hotel, 'bank_account_mappings', $safeCsvPath, 'Bank 123456789012', 'legacy.csv');
    } catch (ValidationException $e) {
        $badLabelRejected = str_contains((string) ($e->errors()['source_name'][0] ?? ''), 'Do not include a bank/card number');
    }
    $assert('source labels containing bank/card-like numeric data are rejected before opening the file', $badLabelRejected);

    $beforeAccounts = TreasuryAccount::count();
    $beforeStatements = BankStatement::count();
    $beforeMaps = DataMigrationIdMap::where('hotel_id', $hotel->id)->where('dataset', 'bank_account_mappings')->count();
    $beforeJournals = GlJournal::count();
    $tally = MigrationService::run($batch, true);
    $row = $batch->rows()->first();
    $report = MigrationService::report($batch);
    View::share('currentHotel', $hotel);
    View::share('errors', new ViewErrorBag);
    $detailHtml = app(DataMigrationController::class)->show($batch->fresh())->render();
    $assert('dry-run returns a passing mapping workpaper with no operational writes',
        $tally['created'] === 1 && $tally['failed'] === 0
            && $row?->status === 'would_create'
            && str_contains((string) ($report['Preflight status'] ?? ''), 'PASS')
            && str_contains((string) ($report['GL impact'] ?? ''), 'None')
            && str_contains((string) ($report['Data-migration ID maps created'] ?? ''), 'None')
            && TreasuryAccount::count() === $beforeAccounts
            && BankStatement::count() === $beforeStatements
            && DataMigrationIdMap::where('hotel_id', $hotel->id)->where('dataset', 'bank_account_mappings')->count() === $beforeMaps
            && GlJournal::count() === $beforeJournals);
    $assert('report explicitly excludes imported supplier bank details and opening bank balances',
        str_contains((string) ($report['Supplier bank details imported'] ?? ''), 'None')
            && str_contains((string) ($report['Bank statements or balances created'] ?? ''), 'None'));

    $applyBlocked = false;
    try {
        MigrationService::run($batch, false);
    } catch (ValidationException $e) {
        $applyBlocked = str_contains((string) ($e->errors()['batch'][0] ?? ''), 'read-only preflight only');
    }
    $directApplyBlocked = false;
    try {
        $importer->importRow($basePayload, $ctx, false);
    } catch (LogicException $e) {
        $directApplyBlocked = str_contains($e->getMessage(), 'preflight-only');
    }
    $controllerApplyBlocked = false;
    try {
        app(DataMigrationController::class)->apply(Request::create('/settings/migration/'.$batch->id.'/apply', 'POST', ['confirm' => 'yes']), $batch);
    } catch (ValidationException $e) {
        $controllerApplyBlocked = str_contains((string) ($e->errors()['batch'][0] ?? ''), 'read-only preflight only');
    }
    $assert('service, importer and controller all block direct Apply', $applyBlocked && $directApplyBlocked && $controllerApplyBlocked);
    $assert('Apply attempt leaves Treasury, bank statements, ID maps and GL journals unchanged',
        TreasuryAccount::count() === $beforeAccounts
            && BankStatement::count() === $beforeStatements
            && DataMigrationIdMap::where('hotel_id', $hotel->id)->where('dataset', 'bank_account_mappings')->count() === $beforeMaps
            && GlJournal::count() === $beforeJournals);

    $cliPath = $makeCsv("external_id,source_account_name,source_kind,target_account_code\nLEGACY-CLI-{$token},Legacy Operating Account,bank,{$bankHead->code}\n");
    $batchCountBeforeCli = DataMigrationBatch::where('hotel_id', $hotel->id)->count();
    $cliExit = Artisan::call('pms:import', [
        'dataset' => 'bank_account_mappings',
        'file' => $cliPath,
        '--hotel' => (string) $hotel->id,
    ]);
    $cliOutput = Artisan::output();
    $assert('CLI without --dry-run exits before registering a batch and explains the read-only guard',
        $cliExit !== 0
            && str_contains($cliOutput, 'read-only preflight only')
            && DataMigrationBatch::where('hotel_id', $hotel->id)->count() === $batchCountBeforeCli);

    $assert('rendered batch detail shows the passing preflight, zero-write counters and no Apply route',
        str_contains($detailHtml, 'Read-only bank-account mapping preflight')
            && str_contains($detailHtml, 'PASS — source IDs resolve to distinct active Nowills Treasury accounts')
            && str_contains($detailHtml, 'Treasury mappings written')
            && ! str_contains($detailHtml, route('migration.apply', $batch)));

    echo PHP_EOL."Bank-account mapping preflight harness: {$passed} passed, {$failed} failed (all database fixtures rollback-only).".PHP_EOL;
} catch (Throwable $e) {
    echo '  ERROR '.$e->getMessage().PHP_EOL;
    $failed++;
} finally {
    foreach ($storedFiles as $storedFile) {
        Storage::delete($storedFile);
    }
    foreach ($tmpFiles as $tmpFile) {
        if (is_file($tmpFile)) {
            @unlink($tmpFile);
        }
    }
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}

if ($failed > 0) {
    throw new RuntimeException("{$failed} bank-account mapping preflight assertions failed.");
}
