<?php
/** In-memory SQLite tests for actual StayService laundry checkout guard and bill assembly.
 * Other service integrations are stubbed; SQLite does not prove MySQL row locking.
 */
namespace App\Services {
    class TransportService { public static function pendingForStay($id) { return collect(); } public static function postPendingForStay($stay) { return 0; } }
    class ConciergeService { public static function pendingForStay($id) { return collect(); } }
    class FormCService { public static function enabled(): bool { return false; } }
    class LaundryService {
        public static function pendingForStay($id) { return \App\Models\LaundryOrder::where('reservation_room_id', $id)->whereIn('status', \App\Models\LaundryOrder::OPEN)->orderBy('id')->get(); }
        public static function deliver($order) { throw new \RuntimeException('Bill preparation must never deliver laundry'); }
    }
}
namespace {
    $root = dirname(__DIR__, 3);
    $temporaryLink = ! is_file($root.'/vendor/autoload.php') && ! file_exists($root.'/build/app');
    if ($temporaryLink) symlink('../app', $root.'/build/app');
    try {
        $loader = require is_file($root.'/vendor/autoload.php') ? $root.'/vendor/autoload.php' : $root.'/build/vendor/autoload.php';
        $loader->addPsr4('App\\', $root.'/app', true);
    } finally { if ($temporaryLink) unlink($root.'/build/app'); }
    use App\Models\Folio;
    use App\Models\LaundryOrder;
    use App\Models\Reservation;
    use App\Models\ReservationRoom;
    use App\Services\StayService;
    use Illuminate\Config\Repository;
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\DatabaseTransactionsManager;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Support\Facades\DB;

    $app = new Container; Container::setInstance($app);
    $app->instance('config', new Repository(['app.timezone' => 'UTC', 'database.default' => 'default']));
    Facade::setFacadeApplication($app);
    $capsule = new Capsule($app); $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher($app)); $capsule->setAsGlobal(); $capsule->bootEloquent(); $app->instance('db', $capsule->getDatabaseManager());
    DB::connection()->setTransactionManager(new DatabaseTransactionsManager());
    DB::statement('CREATE TABLE reservations (id INTEGER PRIMARY KEY, hotel_id INTEGER, created_at TEXT, updated_at TEXT)');
    DB::statement('CREATE TABLE reservation_rooms (id INTEGER PRIMARY KEY, hotel_id INTEGER, reservation_id INTEGER, room_id INTEGER, status TEXT, created_at TEXT, updated_at TEXT)');
    DB::statement('CREATE TABLE folios (id INTEGER PRIMARY KEY, hotel_id INTEGER, reservation_room_id INTEGER, status TEXT, created_at TEXT, updated_at TEXT)');
    DB::statement('CREATE TABLE laundry_orders (id INTEGER PRIMARY KEY, hotel_id INTEGER, reservation_room_id INTEGER, status TEXT, total NUMERIC, order_no TEXT, created_at TEXT, updated_at TEXT)');
    DB::statement('CREATE TABLE pos_orders (id INTEGER PRIMARY KEY, hotel_id INTEGER, reservation_room_id INTEGER, status TEXT)');
    $checks = 0; $check = function ($ok, $why) use (&$checks) { $checks++; if (! $ok) throw new \RuntimeException($why); };
    $reservation = new Reservation; $reservation->forceFill(['hotel_id' => 1])->save();
    $stay = new ReservationRoom; $stay->forceFill(['hotel_id' => 1, 'reservation_id' => $reservation->id, 'status' => 'checked_in'])->save();
    $folio = new Folio; $folio->forceFill(['hotel_id' => 1, 'reservation_room_id' => $stay->id, 'status' => 'open'])->save();
    $other = new ReservationRoom; $other->forceFill(['hotel_id' => 1, 'reservation_id' => $reservation->id, 'status' => 'checked_in'])->save();
    $order = new LaundryOrder; $order->forceFill(['hotel_id' => 1, 'reservation_room_id' => $stay->id, 'order_no' => 'L-1', 'status' => 'collected', 'total' => 20])->save();
    foreach (LaundryOrder::OPEN as $status) {
        $order->update(['status' => $status]);
        $holds = StayService::checkoutHolds($stay);
        $check($holds['laundry']['count'] === 1 && $holds['blocking'] === 1, $status.' missing from holds');
        foreach ([false, true] as $force) {
            try { StayService::checkOut($stay, $force); throw new \RuntimeException('Checkout should have blocked'); }
            catch (\RuntimeException $e) { $check(str_contains($e->getMessage(), 'laundry order(s) are still open'), $status.' not blocked correctly'); }
            $check($stay->fresh()->status === 'checked_in' && $folio->fresh()->status === 'open', 'blocked checkout changed stay or folio');
        }
    }
    $otherHolds = StayService::checkoutHolds($other);
    $check($otherHolds['blocking'] === 0, 'another stay inherited a laundry hold');
    DB::transaction(fn () => StayService::assertLaundryClear($other));
    foreach (LaundryOrder::OPEN as $status) {
        $order->update(['status' => $status]);
        $result = StayService::assembleBill($stay);
        $check($order->fresh()->status === $status && $result['laundry'] === 0 && count($result['skipped']) === 1, 'prepare bill falsely delivered '.$status.' laundry');
    }
    foreach (['delivered', 'cancelled'] as $status) {
        $order->update(['status' => $status]);
        $holds = StayService::checkoutHolds($stay);
        $check($holds['blocking'] === 0 && $holds['laundry']['count'] === 0, $status.' still blocks checkout');
        DB::transaction(fn () => StayService::assertLaundryClear($stay));
    }
    $foreign = new LaundryOrder; $foreign->forceFill(['hotel_id' => 2, 'reservation_room_id' => $stay->id, 'order_no' => 'FOREIGN', 'status' => 'ready', 'total' => 50])->save();
    $hotel = new \App\Models\Hotel; $hotel->forceFill(['id' => 1, 'timezone' => 'UTC']); \App\Support\Tenant::set($hotel);
    $check(StayService::checkoutHolds($stay)['blocking'] === 0, 'another tenant inherited a laundry hold');
    DB::transaction(fn () => StayService::assertLaundryClear($stay));
    \App\Support\Tenant::set(null);
    echo "{$checks} laundry checkout assertions passed (SQLite, stubbed integrations).\n";
}
