<?php
/** Isolated SQLite/Laravel ledger checks. SQLite cannot prove MySQL row-lock concurrency. */
namespace App\Services {
    class ActivityLogger { public static function log(...$args): void {} }
    class IncomeAuditService { public static function assertCanVoid($tx): void {} }
    class LoyaltyService { public static int $refunds = 0; public static function refund($tx): void { self::$refunds++; } public static function earn($folio): void {} }
    class GiftVoucherService { public static int $voids = 0; public static bool $throw = false; public static function onFolioVoid($tx): void { self::$voids++; if (self::$throw) throw new \RuntimeException("voucher failure"); } }
    class PackageService { public static function onFolioVoid($tx): void {} }
    class CreditService {
        public static float $amount = 0; public static bool $reject = false;
        public static function assertCanCharge($company, $amount, $reason, $folio): void {
            self::$amount = $amount; if (self::$reject) throw new \RuntimeException('credit refused');
        }
    }
    class GstService { public static function onClose($folio): void {} }
}
namespace App\Support { class Numbering { public static function next($type): string { return 'INV-1'; } } }
namespace {
    function redirect() { return new class { public function route(...$args) { return $this; } public function with(...$args) { return $this; } }; }
    $root = dirname(__DIR__, 3);
    $temporaryLink = ! is_file($root.'/vendor/autoload.php') && ! file_exists($root.'/build/app');
    if ($temporaryLink) symlink('../app', $root.'/build/app');
    try {
        $loader = require is_file($root.'/vendor/autoload.php') ? $root.'/vendor/autoload.php' : $root.'/build/vendor/autoload.php';
        $loader->addPsr4('App\\', $root.'/app', true);
    } finally { if ($temporaryLink) unlink($root.'/build/app'); }

    use App\Models\Company;
    use App\Models\Folio;
    use App\Models\FolioTransaction;
    use App\Services\CreditService;
    use App\Services\FolioService;
    use App\Services\LoyaltyService;
    use App\Services\GiftVoucherService;
    use Illuminate\Config\Repository;
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\DatabaseTransactionsManager;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Support\Facades\DB;

    $app = new Container; Container::setInstance($app);
    $app->instance('config', new Repository(['app.timezone' => 'UTC', 'database.default' => 'default']));
    $app->instance(\Illuminate\Contracts\Auth\Factory::class, new class implements \Illuminate\Contracts\Auth\Factory { public function id() { return 7; } public function guard($name = null) { return $this; } public function shouldUse($name) {} });
    Facade::setFacadeApplication($app);
    $capsule = new Capsule($app); $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $capsule->setAsGlobal(); $capsule->bootEloquent(); $app->instance('db', $capsule->getDatabaseManager());
    DB::connection()->setTransactionManager(new DatabaseTransactionsManager());
    DB::statement('CREATE TABLE folios (id INTEGER PRIMARY KEY, hotel_id INTEGER, folio_no TEXT, invoice_no TEXT, type TEXT, status TEXT, company_id INTEGER, closed_at TEXT, closed_by INTEGER, created_at TEXT, updated_at TEXT)');
    DB::statement('CREATE TABLE companies (id INTEGER PRIMARY KEY, hotel_id INTEGER, name TEXT, is_active INTEGER, credit_days INTEGER, direct_billing INTEGER, credit_status TEXT, created_at TEXT, updated_at TEXT)');
    DB::statement('CREATE TABLE folio_transactions (id INTEGER PRIMARY KEY, hotel_id INTEGER, folio_id INTEGER, business_date TEXT, type TEXT, category TEXT, description TEXT, amount NUMERIC, tax_amount NUMERIC DEFAULT 0, tax_rate NUMERIC DEFAULT 0, company_id INTEGER, posted_by INTEGER, source_type TEXT, source_id INTEGER, opening_date TEXT, is_void INTEGER DEFAULT 0, void_reason TEXT, created_at TEXT, updated_at TEXT)');
    $checks = 0;
    $check = function ($ok, $why) use (&$checks) { $checks++; if (! $ok) throw new \RuntimeException($why); };
    $expect = function ($fn, $text) use ($check) { try { $fn(); throw new \RuntimeException('expected an exception'); } catch (\RuntimeException $e) { $check(str_contains($e->getMessage(), $text), 'unexpected exception: '.$e->getMessage()); } };
    $make = function ($number) { $f = new Folio; $f->forceFill(['hotel_id' => 1, 'folio_no' => $number, 'status' => 'open', 'type' => 'guest'])->save(); return $f; };
    $charge = function ($folio, $amount) { $tx = new FolioTransaction; $tx->forceFill(['hotel_id' => 1, 'folio_id' => $folio->id, 'type' => 'charge', 'category' => 'room', 'amount' => $amount, 'tax_amount' => 0, 'is_void' => false])->save(); return $tx; };
    $company = new Company; $company->forceFill(['hotel_id' => 1, 'name' => 'Test company'])->save();

    $f = $make('A'); $stale = $f->fresh(); $f->update(['status' => 'closed']);
    $expect(fn () => FolioService::discount($stale, 10, 'test'), 'closed');
    $expect(fn () => FolioService::transferToCityLedger($stale, $company->id), 'closed');
    $check($f->transactions()->count() === 0, 'stale request wrote to closed folio');
    $f->update(['status' => 'open']); $charge($f, 100); $charge($f, 60);
    $discount = FolioService::discountPercent($stale, 25, 'test');
    $check((float) $discount->amount === 40.0, 'percentage was not calculated from current charges');
    CreditService::$reject = true;
    $expect(fn () => FolioService::transferToCityLedger($stale, $company->id), 'credit refused');
    $check($f->fresh()->company_id === null && $f->transactions()->count() === 3, 'credit rejection left a partial transfer');
    CreditService::$reject = false;
    DB::beginTransaction();
    $transfer = FolioService::transferToCityLedger($stale, $company->id);
    $check(CreditService::$amount === 120.0 && (float) $transfer->amount === 120.0 && $f->fresh()->company_id === $company->id, 'transfer amount or company wrong');
    DB::rollBack();
    $check($f->fresh()->company_id === null && $f->transactions()->count() === 3, 'outer rollback left a partial transfer');
    FolioService::transferToCityLedger($stale, $company->id);
    $expect(fn () => FolioService::transferToCityLedger($stale, $company->id), 'Nothing to transfer');
    $check($f->transactions()->where('category', 'city_ledger')->count() === 1, 'duplicate city ledger transfer');

    $g = $make('B'); $tx = $charge($g, 100); $old = $tx->fresh();
    FolioService::void($tx, 'mistake');
    $check($tx->is_void && LoyaltyService::$refunds === 1 && GiftVoucherService::$voids === 1, 'void or reversal did not run');
    $expect(fn () => FolioService::void($old, 'again'), 'already voided');
    $check(LoyaltyService::$refunds === 1 && GiftVoucherService::$voids === 1, 'repeat void repeated reversals');
    $moved = $charge($g, 20); $oldMoved = $moved->fresh(); $moved->update(['folio_id' => $f->id]);
    $expect(fn () => FolioService::void($oldMoved, 'stale'), 'moved to another folio');
    $check(! $moved->fresh()->is_void, 'stale moved line was voided');
    $rollbackLine = $charge($g, 13); GiftVoucherService::$throw = true;
    $expect(fn () => FolioService::void($rollbackLine, 'test'), 'voucher failure');
    $check(! $rollbackLine->fresh()->is_void, 'failed reversal left the ledger line void');
    GiftVoucherService::$throw = false;
    $opening = $charge($g, 4); $opening->update(['source_type' => 'opening_ar', 'opening_date' => '2025-01-01']);
    $expect(fn () => FolioService::void($opening, 'test'), 'Opening receivable');
    $online = $charge($g, 3); $online->update(['source_type' => 'online_payment']);
    $expect(fn () => FolioService::void($online, 'test'), 'Online payments');
    $complimentary = $charge($g, 0); $complimentary->update(['source_type' => 'pos_complimentary']);
    $expect(fn () => FolioService::void($complimentary, 'test'), 'read-only');
    $g->update(['status' => 'closed']); $new = $charge($g, 1);
    $expect(fn () => FolioService::void($new, 'stale'), 'closed');
    $expect(fn () => FolioService::close($g), 'closed');
    $h = $make('C'); $charge($h, 10);
    $expect(fn () => FolioService::close($h), 'balance must be zero');
    $check($h->fresh()->status === 'open', 'non-zero folio was closed');
    $openingFolio = $make('opening');
    $openingTx = $charge($openingFolio, 100);
    $openingTx->update(['type' => 'opening_balance', 'source_type' => 'opening_ar']);
    $openingPayment = $charge($openingFolio, 100); $openingPayment->update(['type' => 'payment']);
    FolioService::close($openingFolio);
    $check($openingFolio->status === 'closed' && $openingFolio->invoice_no === null, 'settled opening-only folio gained an invoice');
    $mixed = $make('mixed'); $mixedOpening = $charge($mixed, 100);
    $mixedOpening->update(['type' => 'opening_balance', 'source_type' => 'opening_ar']);
    $charge($mixed, 25); $mixedPayment = $charge($mixed, 125); $mixedPayment->update(['type' => 'payment']);
    FolioService::close($mixed);
    $check($mixed->status === 'closed' && $mixed->invoice_no === 'INV-1', 'mixed opening and new charge did not get an invoice');

    // Exercise the real move controller with a prevalidated request; HTTP middleware
    // and permissions are outside this isolated service test.
    $move = new class extends \Illuminate\Http\Request {
        public array $data;
        public function validate(array $rules, ...$params) { return $this->data; }
    };
    $controller = new \App\Http\Controllers\FolioController();
    $source = $make('source'); $target = $make('target'); $line = $charge($source, 50);
    $move->data = ['tx' => [$line->id], 'target' => (string) $target->id, 'reason' => 'test'];
    $sourceStale = $source->fresh(); $source->update(['status' => 'closed']);
    $expect(fn () => $controller->move($move, $sourceStale), 'Source folio is closed');
    $source->update(['status' => 'open']); $target->update(['status' => 'closed']);
    $expect(fn () => $controller->move($move, $sourceStale), 'Target folio must be');
    $check($line->fresh()->folio_id === $source->id, 'closed source/target moved a line');
    $target->update(['status' => 'open']); $controller->move($move, $sourceStale);
    $check($line->fresh()->folio_id === $target->id, 'open folio move failed');
    $expect(fn () => $controller->move($move, $sourceStale), 'do not belong');
    $check($line->fresh()->folio_id === $target->id, 'stale move changed line ownership');
    echo "{$checks} ledger and move assertions passed using SQLite and stubbed integrations.\n";
}
