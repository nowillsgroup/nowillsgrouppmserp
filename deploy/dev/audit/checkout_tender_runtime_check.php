<?php
/** Checkout controller + SQLite ledger test. Request validation, stay posting and integrations are stubbed.
 * Run: deploy/dev/bin/php deploy/dev/audit/checkout_tender_runtime_check.php
 * SQLite cannot verify MySQL row-lock behavior.
 */
namespace App\Services {
    class ShiftService { public static function assertOpen(): void {} }
    class GiftVoucherService {
        public static function isVoucherMode(?int $id): bool { return \App\Models\PaymentMode::whereKey($id)->where('type', 'gift_voucher')->exists(); }
        public static function folioPayment($folio, $amount, $reference, $category, $type, $modeId = null) {
            return $folio->transactions()->create(['payment_mode_id' => $modeId, 'type' => $type, 'category' => $category, 'amount' => $amount, 'business_date' => bdate()->toDateString(), 'description' => 'voucher', 'reference' => $reference]);
        }
    }
    class StayService {
        public static int $checkouts = 0;
        public static function postPendingNights($stay, $date): void { FolioService::charge($stay->folio, 'room', 'Night', 10, false); }
        public static function checkOut($stay, $force = false) {
            if (abs($stay->folio->balance()) > 0.009) throw new \RuntimeException('Balance remains');
            self::$checkouts++; $stay->update(['status' => 'checked_out']); return $stay;
        }
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
    use App\Models\FolioTransaction;
    use App\Models\Hotel;
    use App\Models\PaymentMode;
    use App\Models\Reservation;
    use App\Models\ReservationRoom;
    use App\Support\Tenant;
    use Illuminate\Config\Repository;
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\DatabaseTransactionsManager;
    use Illuminate\Events\Dispatcher;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Validation\ValidationException;

    $app = new Container; Container::setInstance($app);
    $app->instance('config', new Repository(['app.timezone' => 'UTC', 'database.default' => 'default']));
    $app->instance(\Illuminate\Contracts\Auth\Factory::class, new class implements \Illuminate\Contracts\Auth\Factory { public function id() { return 7; } public function guard($name = null) { return $this; } public function shouldUse($name) {} });
    $app->instance('validator', new \Illuminate\Validation\Factory(new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'en'), $app));
    Facade::setFacadeApplication($app);
    $capsule = new Capsule($app); $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $capsule->setEventDispatcher(new Dispatcher($app)); $capsule->setAsGlobal(); $capsule->bootEloquent(); $app->instance('db', $capsule->getDatabaseManager());
    DB::connection()->setTransactionManager(new DatabaseTransactionsManager());
    DB::statement('CREATE TABLE reservations (id INTEGER PRIMARY KEY, hotel_id INTEGER, company_id INTEGER, created_at TEXT, updated_at TEXT)');
    DB::statement('CREATE TABLE reservation_rooms (id INTEGER PRIMARY KEY, hotel_id INTEGER, reservation_id INTEGER, room_id INTEGER, status TEXT, created_at TEXT, updated_at TEXT)');
    DB::statement('CREATE TABLE folios (id INTEGER PRIMARY KEY, hotel_id INTEGER, folio_no TEXT, reservation_room_id INTEGER, status TEXT, created_at TEXT, updated_at TEXT)');
    DB::statement('CREATE TABLE payment_modes (id INTEGER PRIMARY KEY, hotel_id INTEGER, name TEXT, type TEXT, is_active INTEGER, created_at TEXT, updated_at TEXT)');
    DB::statement('CREATE TABLE folio_transactions (id INTEGER PRIMARY KEY, hotel_id INTEGER, folio_id INTEGER, business_date TEXT, type TEXT, category TEXT, description TEXT, amount NUMERIC, tax_amount NUMERIC DEFAULT 0, tax_rate NUMERIC DEFAULT 0, is_void INTEGER DEFAULT 0, payment_mode_id INTEGER, reference TEXT, posted_by INTEGER, cheque_bank_name TEXT, cheque_number TEXT, cheque_bank_branch TEXT, created_at TEXT, updated_at TEXT)');
    $hotel = new Hotel; $hotel->forceFill(['id' => 1, 'timezone' => 'UTC', 'currency_symbol' => 'GH₵']); Tenant::set($hotel);
    $checks = 0; $check = function ($ok, $why) use (&$checks) { $checks++; if (! $ok) throw new \RuntimeException($why); };
    $mode = function ($name, $type, $active, $hotelId = 1) { $m = new PaymentMode; $m->forceFill(['hotel_id' => $hotelId, 'name' => $name, 'type' => $type, 'is_active' => $active])->save(); return $m; };
    $cash = $mode('Cash', 'cash', true); $card = $mode('Card', 'card', true); $inactive = $mode('Old cash', 'cash', false); $city = $mode('City ledger', 'city_ledger', true); $voucher = $mode('Voucher', 'gift_voucher', true); $voucherTwo = $mode('Voucher two', 'gift_voucher', true); $cheque = $mode('Cheque', 'cheque', true); $otherHotel = $mode('Other hotel cash', 'cash', true, 2);
    $makeStay = function ($credit = false) {
        $reservation = new Reservation; $reservation->forceFill(['hotel_id' => 1])->save();
        $stay = new ReservationRoom; $stay->forceFill(['hotel_id' => 1, 'reservation_id' => $reservation->id, 'status' => 'checked_in'])->save();
        $folio = new Folio; $folio->forceFill(['hotel_id' => 1, 'folio_no' => 'F-'.$stay->id, 'reservation_room_id' => $stay->id, 'status' => 'open'])->save();
        $line = new FolioTransaction; $line->forceFill(['hotel_id' => 1, 'folio_id' => $folio->id, 'type' => $credit ? 'payment' : 'charge', 'category' => 'misc', 'description' => 'Initial', 'amount' => $credit ? 30 : 90, 'business_date' => '2025-01-01'])->save();
        return [$stay, $folio];
    };
    $request = new class extends \Illuminate\Http\Request { public array $data; public function validate(array $rules, ...$params) { return $this->data; } };
    $controller = new class extends \App\Http\Controllers\FrontDeskController { protected function checkOutSuccessRedirect(ReservationRoom $stay, string $message) { return 'success'; } };
    $reject = function ($data, $credit, $field) use ($makeStay, $controller, $request, $check) {
        [$stay, $folio] = $makeStay($credit); $request->data = $data;
        try { $controller->checkOut($request, $stay); throw new \RuntimeException('Checkout should have failed'); }
        catch (ValidationException $e) { $check(isset($e->errors()[$field]), 'wrong validation field: '.json_encode($e->errors())); }
        $check($stay->fresh()->status === 'checked_in' && $folio->transactions()->count() === 1, 'rejected checkout did not roll back pending night and settlement');
    };
    foreach ([$inactive->id, $city->id, $otherHotel->id, 999999] as $id) {
        $reject(['settle' => 'payment', 'payment_mode_id' => $id], false, 'payment_mode_id');
        $reject(['settle' => 'payment', 'payment_mode_id' => $id], true, 'payment_mode_id');
    }
    $reject(['settle' => 'payment', 'payment_mode_id' => $cash->id, 'amount' => 10], false, 'amount');
    $reject(['settle' => 'payment', 'payments' => [['amount' => 40, 'payment_mode_id' => $cash->id], ['amount' => 60, 'payment_mode_id' => $inactive->id]]], false, 'payments.1.payment_mode_id');
    $reject(['settle' => 'payment', 'payments' => [['amount' => 10, 'payment_mode_id' => $city->id], ['amount' => 10, 'payment_mode_id' => $cash->id]]], true, 'payments.0.payment_mode_id');
    $reject(['settle' => 'payment', 'payments' => [['amount' => 40, 'payment_mode_id' => $inactive->id], ['amount' => 60, 'payment_mode_id' => $cash->id]]], false, 'payments.0.payment_mode_id');
    $reject(['settle' => 'payment', 'payments' => [['amount' => 40, 'payment_mode_id' => $cash->id], ['amount' => 60, 'payment_mode_id' => $voucher->id]]], false, 'payments');
    $check(\App\Services\StayService::$checkouts === 0, 'invalid tender called checkout');
    [$stay, $folio] = $makeStay(); $request->data = ['settle' => 'payment', 'payment_mode_id' => $cash->id, 'reference' => 'receipt'];
    $check($controller->checkOut($request, $stay) === 'success' && $stay->fresh()->status === 'checked_out', 'valid single payment failed');
    $entry = $folio->transactions()->where('category', 'settlement')->first();
    $check($entry->type === 'payment' && (float) $entry->amount === 100.0 && $entry->reference === 'receipt' && $entry->payment_mode_id === $cash->id, 'single payment was not posted correctly');
    [$stay, $folio] = $makeStay(true); $request->data = ['settle' => 'payment', 'payment_mode_id' => $cash->id];
    $controller->checkOut($request, $stay); $entry = $folio->transactions()->where('type', 'refund')->first();
    $check($entry && (float) $entry->amount === 20.0 && $stay->fresh()->status === 'checked_out', 'valid single refund failed');
    [$stay, $folio] = $makeStay(); $request->data = ['settle' => 'payment', 'payments' => [['amount' => 40, 'payment_mode_id' => $card->id], ['amount' => 60, 'payment_mode_id' => $cash->id]]];
    $controller->checkOut($request, $stay);
    $check($folio->transactions()->where('category', 'settlement')->count() === 2 && $stay->fresh()->status === 'checked_out', 'valid split payment failed');
    [$stay, $folio] = $makeStay(true); $request->data = ['settle' => 'payment', 'payments' => [['amount' => 10, 'payment_mode_id' => $card->id], ['amount' => 10, 'payment_mode_id' => $cash->id]]];
    $controller->checkOut($request, $stay);
    $check($folio->transactions()->where('type', 'refund')->count() === 2, 'valid split refund failed');
    [$stay, $folio] = $makeStay(); $request->data = ['settle' => 'payment', 'payment_mode_id' => $voucherTwo->id, 'reference' => 'GV-TEST'];
    $controller->checkOut($request, $stay);
    $check($folio->transactions()->where('description', 'voucher')->where('payment_mode_id', $voucherTwo->id)->count() === 1, 'single voucher tender was blocked or lost its selected mode');
    [$stay, $folio] = $makeStay(); $request->data = ['settle' => 'payment', 'payment_mode_id' => $cheque->id, 'cheque_bank_name' => 'Example bank', 'cheque_number' => '12345', 'cheque_bank_branch' => 'Osu'];
    $controller->checkOut($request, $stay); $entry = $folio->transactions()->where('category', 'settlement')->first();
    $check($entry->cheque_bank_name === 'Example bank' && $entry->cheque_number === '12345' && $entry->cheque_bank_branch === 'Osu', 'cheque details were lost');
    echo "{$checks} checkout tender assertions passed on SQLite with stubbed integrations.\n";
}
