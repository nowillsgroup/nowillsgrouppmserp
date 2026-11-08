<?php
/** Focused SQLite/Laravel transaction test, no hotel services or external calls.
 * Run with PHP and composer dependencies: php deploy/dev/audit/folio_commit_runtime_check.php
 * In this repo's WASM runtime: deploy/dev/bin/php deploy/dev/audit/folio_commit_runtime_check.php
 */
namespace App\Services {
    class LoyaltyService { public static function earn($folio): void {} }
    class EInvoiceService {
        public static array $submitted = [];
        public static bool $throw = false;
        public static function eligible($f): bool { return $f->status === 'closed' && $f->invoice_no && ! $f->hasActiveIrn(); }
        public static function generate($f, $userId = null): bool {
            self::$submitted[] = $f->invoice_no;
            if (self::$throw) throw new \RuntimeException('simulated integration failure');
            $f->forceFill(['irn_status' => 'generated', 'irn' => 'STAMP', 'irn_next_try_at' => null])->save();
            return true;
        }
    }
}
namespace App\Support { class Numbering { public static function next($type): string { return 'INV-1'; } } }
namespace {
    function setting($key, $default = null) { return $default; }
    function report($exception): void { $GLOBALS['reported'][] = $exception->getMessage(); }
    $root = dirname(__DIR__, 3);
    // The optional WASM runtime's Composer vendor lives under build/; its
    // generated autoloader expects build/app to point at the application.
    $temporaryLink = ! is_file($root.'/vendor/autoload.php') && ! file_exists($root.'/build/app');
    if ($temporaryLink) {
        symlink('../app', $root.'/build/app');
    }
    try {
        $loader = require is_file($root.'/vendor/autoload.php') ? $root.'/vendor/autoload.php' : $root.'/build/vendor/autoload.php';
        $loader->addPsr4('App\\', $root.'/app', true);
    } finally {
        if ($temporaryLink) unlink($root.'/build/app');
    }

    use App\Models\Folio;
    use App\Models\ReservationRoom;
    use App\Services\EInvoiceService;
    use App\Services\FolioService;
    use App\Services\StayService;
    use App\Support\BusinessDateContext;
    use Illuminate\Config\Repository;
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\DatabaseTransactionsManager;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Support\Facades\DB;

    $app = new Container;
    Container::setInstance($app);
    $app->instance('config', new Repository(['app.timezone' => 'UTC', 'database.default' => 'default']));
    $app->instance(\Illuminate\Contracts\Auth\Factory::class, new class implements \Illuminate\Contracts\Auth\Factory { public function id() { return 7; } public function guard($name = null) { return $this; } public function shouldUse($name) {} });
    Facade::setFacadeApplication($app);
    $capsule = new Capsule($app);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $app->instance('db', $capsule->getDatabaseManager());
    DB::connection()->setTransactionManager(new DatabaseTransactionsManager());
    DB::statement('CREATE TABLE reservation_rooms (id INTEGER PRIMARY KEY, hotel_id INTEGER, room_id INTEGER, status TEXT, created_at TEXT, updated_at TEXT)');
    DB::statement('CREATE TABLE folios (id INTEGER PRIMARY KEY, hotel_id INTEGER, folio_no TEXT, invoice_no TEXT, type TEXT, status TEXT, invoice_date TEXT, closed_at TEXT, closed_by INTEGER, reservation_id INTEGER, reservation_room_id INTEGER, guest_id INTEGER, company_id INTEGER, supply_type TEXT, buyer_gstin TEXT, buyer_name TEXT, buyer_address TEXT, buyer_state_code TEXT, pos_state_code TEXT, irn TEXT, irn_status TEXT, irn_next_try_at TEXT, created_at TEXT, updated_at TEXT)');
    DB::statement('CREATE TABLE folio_transactions (id INTEGER PRIMARY KEY, folio_id INTEGER, source_type TEXT, type TEXT, is_void INTEGER)');
    $checks = 0;
    $check = function ($ok, $why) use (&$checks) { $checks++; if (! $ok) throw new \RuntimeException($why); };
    $schedule = new \ReflectionMethod(StayService::class, 'afterStayCommit');
    $events = [];
    $stay = new ReservationRoom();
    $stay->forceFill(['hotel_id' => 3, 'room_id' => 7, 'status' => 'checked_in'])->save();
    DB::beginTransaction(); DB::beginTransaction();
    BusinessDateContext::run(Carbon::parse('2025-02-10'), function () use ($schedule, $stay, &$events) {
        $schedule->invoke(null, $stay, 'checked_in', function ($s) use (&$events) { $events[] = bdate()->toDateString().':'.$s->room_id; });
    });
    DB::commit();
    $check($events === [], 'nested commit ran stay hook early');
    DB::commit();
    $check($events === ['2025-02-10:7'], 'outer commit did not preserve replay business date');
    $check(BusinessDateContext::current() === null, 'business date leaked after callback');
    DB::beginTransaction(); DB::beginTransaction();
    $schedule->invoke(null, $stay, 'checked_in', function () use (&$events) { $events[] = 'rollback'; });
    DB::commit(); DB::rollBack();
    $check(count($events) === 1, 'outer rollback ran stay hook');
    DB::beginTransaction(); DB::beginTransaction();
    $schedule->invoke(null, $stay, 'checked_in', function () use (&$events) { $events[] = 'savepoint'; });
    DB::rollBack(); DB::commit();
    $check(count($events) === 1, 'savepoint rollback ran stay hook');
    DB::beginTransaction();
    $schedule->invoke(null, $stay, 'checked_in', function () use (&$events) { $events[] = 'stale'; });
    $stay->update(['room_id' => 8]); DB::commit();
    $check(count($events) === 1, 'stale room hook ran');
    $stay->update(['room_id' => 7]);
    DB::beginTransaction();
    $schedule->invoke(null, $stay, 'checked_in', function () { throw new \RuntimeException('hook failed'); });
    DB::commit();
    $check(in_array('hook failed', $GLOBALS['reported'], true), 'post-commit failure not reported');

    $makeFolio = function ($number) { $f = new Folio(); $f->forceFill(['hotel_id' => 3, 'folio_no' => $number, 'type' => 'guest', 'status' => 'open'])->save(); return $f; };
    $f = $makeFolio('F-1');
    DB::beginTransaction();
    BusinessDateContext::run(Carbon::parse('2025-02-11'), fn () => FolioService::close($f));
    $check($f->invoice_date->toDateString() === '2025-02-11' && $f->irn_status === 'pending', 'snapshot and pending must be inside the transaction');
    $check(EInvoiceService::$submitted === [], 'invoice submitted before outer commit');
    DB::rollBack();
    $check($f->fresh()->status === 'open' && $f->fresh()->invoice_date === null && EInvoiceService::$submitted === [], 'rollback retained invoice or submitted it');
    $f = $f->fresh(); DB::beginTransaction(); FolioService::close($f);
    $check(EInvoiceService::$submitted === [], 'invoice submitted before commit'); DB::commit();
    $check(EInvoiceService::$submitted === ['INV-1'] && $f->fresh()->irn_status === 'generated', 'invoice not submitted after commit');
    $f2 = $makeFolio('F-2'); EInvoiceService::$throw = true;
    DB::beginTransaction(); FolioService::close($f2); DB::commit();
    $check($f2->fresh()->status === 'closed' && $f2->fresh()->irn_status === 'pending' && in_array('simulated integration failure', $GLOBALS['reported'], true), 'submission exception affected committed close or retry state');
    EInvoiceService::$throw = false;
    $f3 = $makeFolio('F-3'); FolioService::close($f3);
    $check($f3->irn_status === 'generated', 'top-level close should return the stamped state');
    $f4 = $makeFolio('F-4'); $before = count(EInvoiceService::$submitted);
    DB::beginTransaction(); FolioService::close($f4);
    $check($f4->irn_next_try_at !== null && Carbon::parse($f4->irn_next_try_at)->isFuture(), 'pending invoice retry grace missing');
    $f4->update(['status' => 'open']); DB::commit();
    $check(count(EInvoiceService::$submitted) === $before, 'reopened invoice was submitted');
    echo "{$checks} SQLite transaction assertions passed (external services stubbed).\n";
}
