<?php
/** Tests posted reservation aggregation with actual Folio totals; no DB or checkout is exercised. */
$root = dirname(__DIR__, 3);
$temporaryLink = ! is_file($root.'/vendor/autoload.php') && ! file_exists($root.'/build/app');
if ($temporaryLink) symlink('../app', $root.'/build/app');
try {
    $loader = require is_file($root.'/vendor/autoload.php') ? $root.'/vendor/autoload.php' : $root.'/build/vendor/autoload.php';
    $loader->addPsr4('App\\', $root.'/app', true);
} finally { if ($temporaryLink) unlink($root.'/build/app'); }

use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\Reservation;
use App\Services\ReservationBillingService;

$app = new \Illuminate\Container\Container; \Illuminate\Container\Container::setInstance($app);
$app->instance('config', new \Illuminate\Config\Repository(['database.default' => 'default']));
$capsule = new \Illuminate\Database\Capsule\Manager($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher($app));
$capsule->setAsGlobal(); $capsule->bootEloquent();

$checks = 0;
$check = function ($ok, $message) use (&$checks) { $checks++; if (! $ok) throw new RuntimeException($message); };
$folio = function (string $type, string $status, array $rows): Folio {
    $f = new Folio; $f->forceFill(['type' => $type, 'status' => $status]);
    $lines = array_map(function ($row) {
        $line = new FolioTransaction; $line->forceFill(array_merge(['amount' => 0, 'tax_amount' => 0, 'is_void' => false], $row)); return $line;
    }, $rows);
    $f->setRelation('transactions', new \Illuminate\Database\Eloquent\Collection($lines));
    return $f;
};
$reservation = new Reservation;
$reservation->setRelation('folios', new \Illuminate\Database\Eloquent\Collection([
    $folio('guest', 'open', [['type' => 'payment', 'category' => 'advance', 'amount' => 500]]),
    $folio('guest', 'closed', [['type' => 'charge', 'amount' => 800, 'tax_amount' => 120], ['type' => 'payment', 'amount' => 200], ['type' => 'discount', 'amount' => 20]]),
    $folio('guest', 'open', [['type' => 'charge', 'amount' => 400, 'tax_amount' => 60], ['type' => 'allowance', 'amount' => 10], ['type' => 'refund', 'amount' => 30], ['type' => 'opening_balance', 'amount' => 50], ['type' => 'charge', 'amount' => 10000, 'is_void' => true]]),
    $folio('credit_note', 'closed', [['type' => 'charge', 'amount' => -100, 'tax_amount' => -15], ['type' => 'refund', 'amount' => 115]]),
]));
$summary = ReservationBillingService::postedSummary($reservation);
$expected = ['folios' => 4, 'charges' => 1265, 'opening' => 50, 'discount' => 30, 'payments' => 700, 'refunds' => 145, 'balance' => 730];
foreach ($expected as $key => $value) $check(abs($summary[$key] - $value) < 0.001, $key.' incorrect: '.$summary[$key]);
$reservation->setRelation('folios', new \Illuminate\Database\Eloquent\Collection([$folio('guest', 'open', [['type' => 'payment', 'category' => 'advance', 'amount' => 100]])]));
$summary = ReservationBillingService::postedSummary($reservation);
$check($summary['balance'] === -100.0, 'advance-only reservation must show a credit');
$reservation->setRelation('folios', new \Illuminate\Database\Eloquent\Collection());
$summary = ReservationBillingService::postedSummary($reservation);
$check($summary['folios'] === 0 && $summary['balance'] === 0.0, 'empty reservation has wrong balance');
// Verify database relation loading, reservation boundaries and the tenant scope.
$db = $capsule->getConnection();
$db->statement('CREATE TABLE reservations (id INTEGER PRIMARY KEY, hotel_id INTEGER)');
$db->statement('CREATE TABLE folios (id INTEGER PRIMARY KEY, hotel_id INTEGER, reservation_id INTEGER, type TEXT, status TEXT)');
$db->statement('CREATE TABLE folio_transactions (id INTEGER PRIMARY KEY, hotel_id INTEGER, folio_id INTEGER, type TEXT, category TEXT, amount NUMERIC, tax_amount NUMERIC DEFAULT 0, is_void INTEGER DEFAULT 0)');
$db->table('reservations')->insert([['id' => 1, 'hotel_id' => 1], ['id' => 2, 'hotel_id' => 1]]);
$db->table('folios')->insert([
    ['id' => 1, 'hotel_id' => 1, 'reservation_id' => 1, 'type' => 'guest', 'status' => 'open'],
    ['id' => 2, 'hotel_id' => 1, 'reservation_id' => 2, 'type' => 'guest', 'status' => 'open'],
    ['id' => 3, 'hotel_id' => 2, 'reservation_id' => 1, 'type' => 'guest', 'status' => 'open'],
]);
$db->table('folio_transactions')->insert([
    ['id' => 1, 'hotel_id' => 1, 'folio_id' => 1, 'type' => 'payment', 'category' => 'advance', 'amount' => 75, 'tax_amount' => 0, 'is_void' => 0],
    ['id' => 2, 'hotel_id' => 1, 'folio_id' => 2, 'type' => 'payment', 'category' => 'advance', 'amount' => 999, 'tax_amount' => 0, 'is_void' => 0],
    ['id' => 3, 'hotel_id' => 2, 'folio_id' => 3, 'type' => 'payment', 'category' => 'advance', 'amount' => 999, 'tax_amount' => 0, 'is_void' => 0],
    ['id' => 4, 'hotel_id' => 2, 'folio_id' => 1, 'type' => 'payment', 'category' => 'advance', 'amount' => 999, 'tax_amount' => 0, 'is_void' => 0],
]);
$hotel = new \App\Models\Hotel; $hotel->forceFill(['id' => 1, 'timezone' => 'UTC']); \App\Support\Tenant::set($hotel);
$loaded = Reservation::findOrFail(1);
$summary = ReservationBillingService::postedSummary($loaded);
$check($summary['folios'] === 1 && $summary['payments'] === 75.0 && $summary['balance'] === -75.0, 'reservation or tenant isolation failed');
\App\Support\Tenant::set(null);
echo "{$checks} reservation posted balance assertions passed (in-memory models and SQLite).\n";
