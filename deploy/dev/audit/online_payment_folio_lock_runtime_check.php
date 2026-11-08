<?php
/** SQLite target selection and actual online ledger posting; no gateway or MySQL concurrency is exercised. */
namespace App\Support { class Numbering { public static int $next = 0; public static function next($type): string { return 'NEW-'.++self::$next; } } }
namespace {
    function bdate() { return \Carbon\Carbon::parse('2026-01-01'); }
    $root = dirname(__DIR__, 3);
    $temporaryLink = ! is_file($root.'/vendor/autoload.php') && ! file_exists($root.'/build/app');
    if ($temporaryLink) symlink('../app', $root.'/build/app');
    try {
        $loader = require is_file($root.'/vendor/autoload.php') ? $root.'/vendor/autoload.php' : $root.'/build/vendor/autoload.php';
        $loader->addPsr4('App\\', $root.'/app', true);
    } finally { if ($temporaryLink) unlink($root.'/build/app'); }

    use App\Models\Folio;
    use App\Models\Hotel;
    use App\Models\OnlinePayment;
    use App\Services\OnlinePaymentService;
    use App\Support\Tenant;
    use Illuminate\Config\Repository;
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\DatabaseTransactionsManager;
    use Illuminate\Events\Dispatcher;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Support\Facades\DB;

    class OnlinePaymentProbe extends OnlinePaymentService { public static function postForTest($payment, $amount) { return parent::post($payment, $amount); } }
    $app = new Container; Container::setInstance($app);
    $app->instance('config', new Repository(['app.timezone' => 'UTC', 'database.default' => 'default']));
    Facade::setFacadeApplication($app);
    $db = new Capsule($app); $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $db->setEventDispatcher(new Dispatcher($app)); $db->setAsGlobal(); $db->bootEloquent(); $app->instance('db', $db->getDatabaseManager());
    DB::connection()->setTransactionManager(new DatabaseTransactionsManager());
    DB::statement('CREATE TABLE reservations (id INTEGER PRIMARY KEY, hotel_id INTEGER)');
    DB::statement('CREATE TABLE reservation_rooms (id INTEGER PRIMARY KEY, hotel_id INTEGER, reservation_id INTEGER, status TEXT)');
    DB::statement('CREATE TABLE folios (id INTEGER PRIMARY KEY, hotel_id INTEGER, reservation_id INTEGER, reservation_room_id INTEGER, guest_id INTEGER, folio_no TEXT, type TEXT, status TEXT, created_at TEXT, updated_at TEXT)');
    DB::statement('CREATE TABLE payment_modes (id INTEGER PRIMARY KEY, hotel_id INTEGER, type TEXT, name TEXT, account_head_id INTEGER)');
    DB::statement('CREATE TABLE folio_transactions (id INTEGER PRIMARY KEY, hotel_id INTEGER, folio_id INTEGER, business_date TEXT, type TEXT, category TEXT, description TEXT, amount NUMERIC, tax_amount NUMERIC DEFAULT 0, payment_mode_id INTEGER, reference TEXT, source_type TEXT, source_id INTEGER, posted_by INTEGER, is_void INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT)');
    DB::table('reservations')->insert([['id' => 1, 'hotel_id' => 1], ['id' => 2, 'hotel_id' => 1]]);
    DB::table('reservation_rooms')->insert([['id' => 10, 'hotel_id' => 1, 'reservation_id' => 1, 'status' => 'checked_in'], ['id' => 11, 'hotel_id' => 1, 'reservation_id' => 1, 'status' => 'checked_in']]);
    DB::table('folios')->insert([
        ['id' => 1, 'hotel_id' => 1, 'reservation_id' => 1, 'reservation_room_id' => 10, 'folio_no' => 'ROOM-1', 'type' => 'guest', 'status' => 'open'],
        ['id' => 2, 'hotel_id' => 1, 'reservation_id' => 1, 'reservation_room_id' => null, 'folio_no' => 'ADV-1', 'type' => 'guest', 'status' => 'open'],
        ['id' => 3, 'hotel_id' => 1, 'reservation_id' => 1, 'reservation_room_id' => 11, 'folio_no' => 'ROOM-2', 'type' => 'guest', 'status' => 'open'],
        ['id' => 4, 'hotel_id' => 1, 'reservation_id' => 2, 'reservation_room_id' => null, 'folio_no' => 'OTHER', 'type' => 'guest', 'status' => 'open'],
        ['id' => 5, 'hotel_id' => 2, 'reservation_id' => 1, 'reservation_room_id' => null, 'folio_no' => 'FOREIGN', 'type' => 'guest', 'status' => 'open'],
    ]);
    DB::table('payment_modes')->insert(['id' => 1, 'hotel_id' => 1, 'type' => 'online', 'name' => 'Online', 'account_head_id' => 1]);
    $hotel = new Hotel; $hotel->forceFill(['id' => 1, 'timezone' => 'UTC']); Tenant::set($hotel);
    $checks = 0;
    $check = function ($ok, $why) use (&$checks) { $checks++; if (! $ok) throw new \RuntimeException($why); };
    $p = new OnlinePayment; $p->forceFill(['id' => 99, 'hotel_id' => 1, 'reservation_id' => 1, 'folio_id' => 3, 'pay_no' => 'PAY-1', 'provider' => 'demo', 'provider_ref' => 'R1']);
    try { OnlinePaymentService::targetFolio($p); throw new \RuntimeException('expected failure'); }
    catch (\RuntimeException $e) { $check(str_contains($e->getMessage(), 'inside a transaction'), 'missing transaction guard'); }
    $target = fn () => DB::transaction(fn () => OnlinePaymentService::targetFolio($p));
    $check($target()->id === 3, 'explicit open folio must be preferred');
    Folio::whereKey(3)->update(['status' => 'closed']);
    $check($target()->id === 1, 'closed explicit folio did not fall back to first in-house room');
    $tx = DB::transaction(fn () => OnlinePaymentProbe::postForTest($p, 45));
    $check($tx->folio_id === 1 && $tx->category === 'settlement' && $tx->source_type === 'online_payment' && $tx->source_id === 99, 'online post lost payment identity or target');
    $check(Folio::find(3)->transactions()->count() === 0, 'payment landed on a closed folio');
    DB::beginTransaction();
    OnlinePaymentProbe::postForTest($p, 55);
    DB::rollBack();
    $check(Folio::find(1)->transactions()->count() === 1, 'rolled-back online payment left a ledger entry');
    DB::table('reservation_rooms')->where('id', 10)->update(['status' => 'checked_out']);
    // The first remaining checked-in stay has a closed folio, so the advance folio wins.
    $check($target()->id === 2, 'reservation advance fallback failed');
    $tx = DB::transaction(fn () => OnlinePaymentProbe::postForTest($p, 20));
    $check($tx->folio_id === 2 && $tx->category === 'advance', 'reservation online advance did not post correctly');
    Folio::whereKey(2)->update(['status' => 'closed']);
    DB::beginTransaction(); $created = OnlinePaymentService::targetFolio($p);
    $check($created->reservation_id === 1 && $created->status === 'open', 'new reservation advance folio missing');
    $newId = $created->id; DB::rollBack();
    $check(Folio::find($newId) === null, 'outer rollback left a new folio');
    $created = $target();
    $check($target()->id === $created->id, 'existing open advance folio was not reused');
    $check($created->hotel_id === 1 && $created->folio_no !== 'FOREIGN', 'cross-tenant target selected');
    $p->folio_id = 5; // Explicit IDs are still tenant-scoped.
    $check($target()->id === $created->id, 'foreign explicit folio leaked into target selection');
    $p->reservation_id = 999;
    try { $target(); throw new \RuntimeException('expected failure'); }
    catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) { $check(true, 'missing reservation must fail'); }
    $p->reservation_id = 1;
    $noRes = new OnlinePayment; $noRes->forceFill(['hotel_id' => 1, 'folio_id' => 3]);
    $newCredit = DB::transaction(fn () => OnlinePaymentService::targetFolio($noRes));
    $check($newCredit->reservation_id === null && $newCredit->status === 'open', 'closed standalone fallback changed');
    echo "{$checks} online payment target assertions passed (SQLite; gateway and MySQL concurrency not tested).\n";
}
