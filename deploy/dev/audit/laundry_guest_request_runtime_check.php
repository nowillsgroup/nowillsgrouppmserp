<?php
/** Laundry request lifecycle with real services on SQLite. Folio/tax/numbering are stubbed; no MySQL concurrency test. */
namespace App\Support { class Numbering { private static int $n = 0; public static function next($type) { return 'LO-'.(++self::$n); } } }
namespace App\Services {
    class ActivityLogger { public static function log(...$args) {} }
    class TaxService {
        public static function splitPrice($v, $unused, $category) { return ['net' => $v, 'tax' => 0, 'total' => $v, 'rate' => 0]; }
        public static function pricesInclusive() { return false; }
        public static function componentRates($category, $amount) { return []; }
    }
    class FolioService {
        public static function openForStay($stay) { return \App\Models\Folio::where('reservation_room_id', $stay->id)->firstOrFail(); }
        public static function charge($folio, $category, $description, $amount, $tax, $extra) {
            return $folio->transactions()->create(['business_date' => '2026-11-05', 'type' => 'charge', 'category' => $category, 'description' => $description, 'amount' => $amount, 'tax_amount' => 0] + $extra);
        }
    }
}
namespace {
    function setting($key, $default = null) { return $default; }
    function money($amount, $symbol = true) { return number_format((float) $amount, 2); }
    function auth() { return new class { public function id() { return 7; } }; }
    $root = dirname(__DIR__, 3); $link = ! is_file($root.'/vendor/autoload.php') && ! file_exists($root.'/build/app');
    if ($link) symlink('../app', $root.'/build/app');
    try { $loader = require is_file($root.'/vendor/autoload.php') ? $root.'/vendor/autoload.php' : $root.'/build/vendor/autoload.php'; $loader->addPsr4('App\\', $root.'/app', true); }
    finally { if ($link) unlink($root.'/build/app'); }
    $app = new \Illuminate\Container\Container; \Illuminate\Container\Container::setInstance($app);
    $app->instance('config', new \Illuminate\Config\Repository(['app.timezone' => 'UTC', 'database.default' => 'default']));
    \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    $capsule = new \Illuminate\Database\Capsule\Manager($app); $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher($app)); $capsule->setAsGlobal(); $capsule->bootEloquent(); $app->instance('db', $capsule->getDatabaseManager());
    $db = \Illuminate\Support\Facades\DB::class;
    $db::statement('CREATE TABLE reservations (id INTEGER PRIMARY KEY, hotel_id INTEGER, created_at TEXT, updated_at TEXT)');
    $db::statement('CREATE TABLE reservation_rooms (id INTEGER PRIMARY KEY, hotel_id INTEGER, reservation_id INTEGER, room_id INTEGER, guest_id INTEGER, status TEXT, created_at TEXT, updated_at TEXT)');
    $db::statement('CREATE TABLE rooms (id INTEGER PRIMARY KEY, hotel_id INTEGER, room_no TEXT, created_at TEXT, updated_at TEXT)');
    $db::statement('CREATE TABLE guests (id INTEGER PRIMARY KEY, hotel_id INTEGER, title TEXT, first_name TEXT, last_name TEXT, phone TEXT, created_at TEXT, updated_at TEXT)');
    $db::statement('CREATE TABLE guest_requests (id INTEGER PRIMARY KEY, hotel_id INTEGER, request_no TEXT, reservation_room_id INTEGER, category TEXT, status TEXT, linked_type TEXT, linked_id INTEGER, staff_note TEXT, acknowledged_at TEXT, completed_at TEXT, handled_by INTEGER, created_at TEXT, updated_at TEXT)');
    $db::statement("CREATE TABLE laundry_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, hotel_id INTEGER, order_no TEXT, type TEXT, speed TEXT, status TEXT, reservation_room_id INTEGER, room_id INTEGER, guest_request_id INTEGER, guest_name TEXT, phone TEXT, bag_no TEXT, bag_pieces INTEGER, pieces INTEGER DEFAULT 0, subtotal NUMERIC DEFAULT 0, express_charge NUMERIC DEFAULT 0, total NUMERIC DEFAULT 0, collected_at TEXT, promised_at TEXT, ready_at TEXT, delivered_at TEXT, delivered_by INTEGER, created_by INTEGER, notes TEXT, cancel_reason TEXT, folio_id INTEGER, folio_transaction_id INTEGER, payment_mode_id INTEGER, created_at TEXT, updated_at TEXT)");
    $db::statement('CREATE TABLE laundry_order_lines (id INTEGER PRIMARY KEY, laundry_order_id INTEGER, laundry_item_id INTEGER, item_name TEXT, service TEXT, qty INTEGER, rate NUMERIC, amount NUMERIC, remark TEXT)');
    $db::statement('CREATE TABLE laundry_items (id INTEGER PRIMARY KEY, hotel_id INTEGER, name TEXT, category TEXT, is_active INTEGER, wash_rate NUMERIC, dryclean_rate NUMERIC, press_rate NUMERIC, created_at TEXT, updated_at TEXT)');
    $db::statement('CREATE TABLE folios (id INTEGER PRIMARY KEY, hotel_id INTEGER, reservation_room_id INTEGER, folio_no TEXT, type TEXT, status TEXT, created_at TEXT, updated_at TEXT)');
    $db::statement('CREATE TABLE folio_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, hotel_id INTEGER, folio_id INTEGER, business_date TEXT, type TEXT, category TEXT, description TEXT, amount NUMERIC, tax_amount NUMERIC, source_type TEXT, source_id INTEGER, reference TEXT, created_at TEXT, updated_at TEXT)');
    $db::statement("INSERT INTO reservations (id,hotel_id) VALUES (1,1)");
    $db::statement("INSERT INTO rooms (id,hotel_id,room_no) VALUES (1,1,'202'),(2,1,'203')");
    $db::statement("INSERT INTO guests (id,hotel_id,first_name,last_name,phone) VALUES (1,1,'Ama','Mensah','123')");
    $db::statement("INSERT INTO reservation_rooms (id,hotel_id,reservation_id,room_id,guest_id,status) VALUES (1,1,1,1,1,'checked_in'),(2,1,1,2,1,'checked_in')");
    $db::statement("INSERT INTO folios (id,hotel_id,reservation_room_id,folio_no,type,status) VALUES (1,1,1,'F-1','guest','open')");
    $hotel = new \App\Models\Hotel; $hotel->forceFill(['id' => 1, 'timezone' => 'UTC']); \App\Support\Tenant::set($hotel);
    $checks = 0; $check = function ($ok, $message) use (&$checks) { $checks++; if (! $ok) throw new \RuntimeException($message); };
    $request = function ($id, $status = 'new', $stay = 1, $category = 'laundry', $hotel = 1, $linkType = null, $linkId = null, $note = null) use ($db) {
        $db::table('guest_requests')->insert(['id' => $id, 'hotel_id' => $hotel, 'request_no' => 'G-'.$id, 'reservation_room_id' => $stay, 'category' => $category, 'status' => $status, 'linked_type' => $linkType, 'linked_id' => $linkId, 'staff_note' => $note]);
    };
    $collect = fn ($id, $stay = 1) => \App\Services\LaundryService::create(['type' => 'guest', 'reservation_room_id' => $stay, 'guest_request_id' => $id, 'speed' => 'normal']);
    $fails = function ($callback, $text) use ($check) { try { $callback(); throw new \RuntimeException('Expected refusal'); } catch (\RuntimeException $e) { $check(str_contains($e->getMessage(), $text), 'Wrong refusal: '.$e->getMessage()); } };
    foreach (['new', 'acknowledged', 'in_progress'] as $i => $status) {
        $id = $i + 1; $request($id, $status, note: $status === 'in_progress' ? 'Original staff note' : null);
        $o = $collect($id); $r = \App\Models\GuestRequest::findOrFail($id);
        $check($r->status === 'in_progress' && $r->linked_type === 'LaundryOrder' && (int) $r->linked_id === $o->id, $status.' not linked correctly');
        $check((int) $o->guest_request_id === $id && (int) $o->reservation_room_id === 1, $status.' order has wrong association');
        if ($status === 'in_progress') $check($r->staff_note === 'Original staff note', 'existing staff note overwritten');
        $fails(fn () => $collect($id), 'already linked');
    }
    $request(4); $fails(fn () => $collect(4, 2), 'another room');
    $fails(fn () => \App\Services\LaundryService::create(['type' => 'walk_in', 'guest_name' => 'Other', 'guest_request_id' => 4]), 'not a walk-in');
    $request(5, 'done'); $fails(fn () => $collect(5), 'no longer an open');
    $request(6, 'new', category: 'maintenance'); $fails(fn () => $collect(6), 'no longer an open');
    $request(7, 'in_progress', linkType: 'OtherTask', linkId: 123); $fails(fn () => $collect(7), 'already linked');
    $request(8, 'new', hotel: 2); $fails(fn () => $collect(8), 'no longer an open');
    $fails(fn () => $collect(999), 'no longer an open');
    $request(9); $db::table('laundry_orders')->insert(['hotel_id' => 1, 'order_no' => 'LEGACY', 'type' => 'guest', 'status' => 'processing', 'guest_request_id' => 9]);
    $fails(fn () => $collect(9), 'already linked');
    $request(10); $o = $collect(10); \App\Services\LaundryService::cancel($o, 'Guest took bag back');
    $check(\App\Models\GuestRequest::findOrFail(10)->status === 'declined', 'cancel did not decline linked request');
    $request(11); $o = $collect(11); $db::table('guest_requests')->where('id', 11)->update(['linked_type' => 'OtherTask', 'linked_id' => 999]);
    \App\Services\LaundryService::cancel($o, 'Cancelled');
    $check(\App\Models\GuestRequest::findOrFail(11)->status === 'in_progress', 'cancellation changed a request linked elsewhere');
    $request(12); $o = $collect(12); $db::table('guest_requests')->where('id', 12)->update(['status' => 'done']);
    \App\Services\LaundryService::cancel($o, 'Cancelled');
    $check(\App\Models\GuestRequest::findOrFail(12)->status === 'done', 'terminal request overwritten');
    $request(13); $o = $collect(13); $db::table('laundry_orders')->where('id', $o->id)->update(['status' => 'processing', 'pieces' => 1, 'subtotal' => 25, 'total' => 25]);
    $o = \App\Services\LaundryService::deliver($o->fresh());
    $check($o->status === 'delivered' && \App\Models\GuestRequest::findOrFail(13)->status === 'done', 'delivery did not finish linked request');
    $request(14); $o = $collect(14); $db::table('laundry_orders')->where('id', $o->id)->update(['status' => 'processing', 'pieces' => 1, 'subtotal' => 25, 'total' => 25]);
    $db::table('guest_requests')->where('id', 14)->update(['linked_type' => 'OtherTask', 'linked_id' => 999]);
    \App\Services\LaundryService::deliver($o->fresh());
    $check(\App\Models\GuestRequest::findOrFail(14)->status === 'in_progress', 'delivery changed a request linked elsewhere');
    $request(15); $db::table('laundry_orders')->insert(['hotel_id' => 1, 'order_no' => 'LEGACY-DONE', 'type' => 'guest', 'status' => 'delivered', 'guest_request_id' => 15]);
    $fails(fn () => $collect(15), 'already linked');
    $request(16); $db::table('laundry_orders')->insert(['hotel_id' => 1, 'order_no' => 'LEGACY-CANCEL', 'type' => 'guest', 'status' => 'cancelled', 'guest_request_id' => 16]);
    $check($collect(16)->guest_request_id === 16, 'cancelled legacy order should not prevent an unlinked open request from being collected');
    $request(17); $before = $db::table('laundry_orders')->count();
    $fails(fn () => \App\Services\LaundryService::create(['type' => 'guest', 'reservation_room_id' => 1, 'guest_request_id' => 17, 'lines' => [['laundry_item_id' => 999, 'service' => 'wash', 'qty' => 1]]]), 'Unknown, duplicate or unavailable');
    $r = \App\Models\GuestRequest::findOrFail(17);
    $check($db::table('laundry_orders')->count() === $before && $r->status === 'new' && $r->linked_id === null, 'failed itemisation did not roll back collection');
    $request(18); $o = $collect(18); $db::table('laundry_orders')->where('id', $o->id)->update(['status' => 'processing', 'pieces' => 1, 'subtotal' => 25, 'total' => 25]);
    $db::table('guest_requests')->where('id', 18)->update(['status' => 'cancelled']);
    \App\Services\LaundryService::deliver($o->fresh());
    $check(\App\Models\GuestRequest::findOrFail(18)->status === 'cancelled', 'delivery overwrote terminal request');
    \App\Support\Tenant::set(null);
    echo "{$checks} laundry guest request assertions passed (SQLite; folio/tax stubbed, MySQL concurrency not tested).\n";
}
