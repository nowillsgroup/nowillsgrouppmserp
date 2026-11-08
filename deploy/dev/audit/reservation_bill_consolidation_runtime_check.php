<?php
/** SQLite integration for the inactive consolidation primitive; MySQL concurrency and gateways are not exercised. */
namespace App\Services {
    class GstService { public static function reopenBlocker($folio) { return null; } }
    class ActivityLogger { public static function log(...$args): void {} }
}
namespace {
    function back() { return new class { public array $flash = []; public function with($key, $value) { $this->flash[$key] = $value; return $this; } }; }
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
    use App\Models\Reservation;
    use App\Services\ReservationBillConsolidator;
    use App\Support\Tenant;
    use Illuminate\Config\Repository;
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\DatabaseTransactionsManager;
    use Illuminate\Events\Dispatcher;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Support\Facades\DB;

    $app = new Container; Container::setInstance($app);
    $app->instance('config', new Repository(['app.timezone' => 'UTC', 'database.default' => 'default']));
    Facade::setFacadeApplication($app);
    $capsule = new Capsule($app); $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $capsule->setEventDispatcher(new Dispatcher($app)); $capsule->setAsGlobal(); $capsule->bootEloquent(); $app->instance('db', $capsule->getDatabaseManager());
    $app->bind('db.schema', fn () => DB::connection()->getSchemaBuilder());
    DB::connection()->setTransactionManager(new DatabaseTransactionsManager());
    DB::statement('CREATE TABLE reservations (id INTEGER PRIMARY KEY, hotel_id INTEGER)');
    DB::statement('CREATE TABLE rooms (id INTEGER PRIMARY KEY, hotel_id INTEGER, room_no TEXT)');
    DB::statement('CREATE TABLE reservation_rooms (id INTEGER PRIMARY KEY, hotel_id INTEGER, reservation_id INTEGER, room_id INTEGER, status TEXT)');
    DB::statement('CREATE TABLE folios (id INTEGER PRIMARY KEY, hotel_id INTEGER, reservation_id INTEGER, reservation_room_id INTEGER, folio_no TEXT, type TEXT, status TEXT, invoice_no TEXT, closed_at TEXT, closed_by INTEGER, created_at TEXT, updated_at TEXT)');
    DB::statement('CREATE TABLE folio_transactions (id INTEGER PRIMARY KEY, hotel_id INTEGER, folio_id INTEGER, business_date TEXT, type TEXT, category TEXT, description TEXT, amount NUMERIC, tax_amount NUMERIC DEFAULT 0, source_type TEXT, source_id INTEGER, opening_date TEXT, is_void INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT)');
    $migration = require $root.'/database/migrations/2026_11_08_000460_reservation_bill_consolidation.php';
    $migration->up(); $migration->up();
    $hotel = new Hotel; $hotel->forceFill(['id' => 1, 'timezone' => 'UTC']); Tenant::set($hotel);
    DB::table('reservations')->insert([['id' => 1, 'hotel_id' => 1], ['id' => 2, 'hotel_id' => 2]]);
    DB::table('rooms')->insert([['id' => 1, 'hotel_id' => 1, 'room_no' => '202'], ['id' => 2, 'hotel_id' => 1, 'room_no' => '203']]);
    DB::table('reservation_rooms')->insert([
        ['id' => 10, 'hotel_id' => 1, 'reservation_id' => 1, 'room_id' => 1, 'status' => 'checked_out'],
        ['id' => 11, 'hotel_id' => 1, 'reservation_id' => 1, 'room_id' => 2, 'status' => 'checked_out'],
        ['id' => 12, 'hotel_id' => 2, 'reservation_id' => 2, 'room_id' => null, 'status' => 'checked_out'],
    ]);
    DB::table('folios')->insert([
        ['id' => 1, 'hotel_id' => 1, 'reservation_id' => 1, 'reservation_room_id' => null, 'folio_no' => 'MASTER', 'type' => 'guest', 'status' => 'open'],
        ['id' => 2, 'hotel_id' => 1, 'reservation_id' => 1, 'reservation_room_id' => 10, 'folio_no' => 'ROOM-1', 'type' => 'guest', 'status' => 'open'],
        ['id' => 3, 'hotel_id' => 1, 'reservation_id' => 1, 'reservation_room_id' => 11, 'folio_no' => 'ROOM-2', 'type' => 'guest', 'status' => 'open'],
        ['id' => 4, 'hotel_id' => 2, 'reservation_id' => 2, 'reservation_room_id' => 12, 'folio_no' => 'OTHER', 'type' => 'guest', 'status' => 'open'],
    ]);
    DB::table('folio_transactions')->insert([
        ['id' => 1, 'hotel_id' => 1, 'folio_id' => 1, 'business_date' => '2026-01-01', 'type' => 'payment', 'category' => 'advance', 'description' => 'Advance', 'amount' => 300, 'tax_amount' => 0, 'is_void' => 0],
        ['id' => 2, 'hotel_id' => 1, 'folio_id' => 2, 'business_date' => '2026-01-02', 'type' => 'charge', 'category' => 'room', 'description' => 'Room 1', 'amount' => 100, 'tax_amount' => 10, 'is_void' => 0],
        ['id' => 3, 'hotel_id' => 1, 'folio_id' => 3, 'business_date' => '2026-01-03', 'type' => 'charge', 'category' => 'room', 'description' => 'Room 2', 'amount' => 200, 'tax_amount' => 20, 'is_void' => 0],
        ['id' => 4, 'hotel_id' => 1, 'folio_id' => 3, 'business_date' => '2026-01-03', 'type' => 'charge', 'category' => 'misc', 'description' => 'Voided', 'amount' => 999, 'tax_amount' => 0, 'is_void' => 1],
    ]);
    $r = Reservation::findOrFail(1); $target = Folio::findOrFail(1);
    $checks = 0; $check = function ($ok, $why) use (&$checks) { $checks++; if (! $ok) throw new \RuntimeException($why); };
    $expect = function ($fn, $message) use ($check) { try { $fn(); throw new \RuntimeException('expected failure'); } catch (\RuntimeException $e) { $check(str_contains($e->getMessage(), $message), 'unexpected error: '.$e->getMessage()); } };
    $run = fn () => ReservationBillConsolidator::consolidate($r, $target, 7);
    DB::table('reservation_rooms')->where('id', 11)->update(['status' => 'checked_in']);
    $expect($run, 'All rooms must');
    DB::table('reservation_rooms')->where('id', 11)->update(['status' => 'reserved']);
    $expect($run, 'All rooms must');
    DB::table('reservation_rooms')->where('id', 11)->update(['status' => 'checked_out']);
    DB::table('reservation_rooms')->whereIn('id', [10, 11])->update(['status' => 'cancelled']);
    $expect($run, 'All rooms must');
    DB::table('reservation_rooms')->whereIn('id', [10, 11])->update(['status' => 'checked_out']);
    DB::table('folios')->where('id', 2)->update(['status' => 'closed', 'invoice_no' => 'INV-1']);
    $expect($run, 'closed or invoiced');
    $check(DB::table('folio_transactions')->where('folio_id', 2)->count() === 1 && DB::table('reservation_bill_transfers')->count() === 0, 'invoiced folio changed');
    DB::table('folios')->where('id', 2)->update(['status' => 'open']);
    $expect($run, 'closed or invoiced');
    DB::table('folios')->where('id', 2)->update(['status' => 'closed', 'invoice_no' => null]);
    $expect($run, 'closed or invoiced');
    DB::table('folios')->where('id', 2)->update(['status' => 'open', 'type' => 'credit_note']);
    $expect($run, 'closed or invoiced');
    DB::table('folios')->where('id', 2)->update(['type' => 'guest']);
    $foreign = new Folio; $foreign->forceFill(['id' => 4, 'hotel_id' => 2]);
    $expect(fn () => ReservationBillConsolidator::consolidate($r, $foreign, 7), 'reservation folio');
    $expect(fn () => ReservationBillConsolidator::consolidate($r, Folio::findOrFail(2), 7), 'reservation folio');
    DB::table('folio_transactions')->where('id', 3)->update(['opening_date' => '2025-12-31']);
    $expect($run, 'Opening receivables');
    DB::table('folio_transactions')->where('id', 3)->update(['opening_date' => null, 'source_type' => 'pos_complimentary']);
    $expect($run, 'Opening receivables');
    DB::table('folio_transactions')->where('id', 3)->update(['source_type' => 'night', 'source_id' => 33]);
    DB::table('folios')->where('id', 3)->update(['reservation_room_id' => 12]);
    $expect($run, 'outside this reservation');
    DB::table('folios')->where('id', 3)->update(['reservation_room_id' => 11]);
    DB::table('folio_transactions')->where('id', 3)->update(['hotel_id' => 2]);
    $expect($run, 'different hotel');
    DB::table('folio_transactions')->where('id', 3)->update(['hotel_id' => 1]);
    DB::table('folio_transactions')->where('id', 1)->update(['hotel_id' => 2]);
    $expect($run, 'different hotel');
    DB::table('folio_transactions')->where('id', 1)->update(['hotel_id' => 1]);
    DB::table('folio_transactions')->where('id', 1)->update(['opening_date' => '2025-12-31']);
    $expect($run, 'Opening receivables');
    DB::table('folio_transactions')->where('id', 1)->update(['opening_date' => null]);
    DB::beginTransaction(); $run();
    $check(DB::table('folio_transactions')->where('folio_id', 1)->count() === 4 && DB::table('reservation_bill_transfers')->count() === 3, 'did not consolidate all entries including void history');
    $check(Folio::find(2)->status === 'closed' && Folio::find(2)->invoice_no === null && Folio::find(2)->consolidated_into_folio_id === 1, 'source folio not archived without an invoice');
    $check((float) Folio::find(1)->balance() === 30.0, 'combined amount lost or double counted');
    DB::rollBack();
    $check(DB::table('folio_transactions')->where('folio_id', 1)->count() === 1 && Folio::find(2)->status === 'open' && DB::table('reservation_bill_transfers')->count() === 0, 'outer rollback did not restore folios and provenance');
    $run();
    $record = DB::table('reservation_bill_transfers')->where('folio_transaction_id', 3)->first();
    $tx = DB::table('folio_transactions')->where('id', 3)->first();
    $check($record->source_folio_id === 3 && $record->target_folio_id === 1 && $record->actor_user_id === 7, 'provenance is incorrect');
    $check($tx->business_date === '2026-01-03' && $tx->source_type === 'night' && $tx->source_id === 33 && (float) $tx->tax_amount === 20.0, 'posting metadata changed');
    $loadedLine = FolioTransaction::with('consolidationTransfer.sourceFolio.stay.room')->findOrFail(3);
    $check($loadedLine->consolidationTransfer->sourceFolio->folio_no === 'ROOM-2' && $loadedLine->consolidationTransfer->sourceFolio->stay->room->room_no === '203', 'folio and room attribution missing');
    $check(FolioTransaction::with('consolidationTransfer')->findOrFail(1)->consolidationTransfer === null, 'untransferred advance acquired a false origin');
    $check(Folio::with('consolidatedInto')->findOrFail(2)->consolidatedInto->folio_no === 'MASTER', 'source folio does not point to the combined target');
    DB::table('reservation_bill_transfers')->insert(['hotel_id' => 2, 'reservation_id' => 2, 'folio_transaction_id' => 1, 'source_folio_id' => 4, 'target_folio_id' => 1, 'actor_user_id' => null, 'created_at' => now()]);
    $check(FolioTransaction::with('consolidationTransfer')->findOrFail(1)->consolidationTransfer === null, 'foreign tenant origin was exposed');
    $check(Folio::find(1)->status === 'open' && Folio::find(1)->invoice_no === null, 'consolidation issued an invoice or closed target');
    $controller = new \App\Http\Controllers\FolioController;
    $response = $controller->reopen(Folio::findOrFail(2));
    $check(str_contains($response->flash['error'] ?? '', 'cannot be reopened') && Folio::find(2)->status === 'closed', 'consolidated source could be reopened');
    DB::table('folios')->insert(['id' => 5, 'hotel_id' => 1, 'reservation_id' => 1, 'reservation_room_id' => null, 'folio_no' => 'LEGACY', 'type' => 'guest', 'status' => 'closed']);
    $response = $controller->reopen(Folio::findOrFail(5));
    $check(isset($response->flash['success']) && Folio::find(5)->status === 'open', 'regular folio reopen regressed');
    $expect($run, 'closed or invoiced');
    $expect(fn () => $migration->down(), 'Cannot remove');
    $check(DB::table('folios')->where('id', 4)->value('status') === 'open', 'another hotel was modified');
    DB::table('folios')->where('id', 2)->delete();
    $orphan = FolioTransaction::with('consolidationTransfer.sourceFolio')->findOrFail(2)->consolidationTransfer;
    $check($orphan->sourceFolio === null && $orphan->source_folio_id === 2, 'source identifier was lost when a folio is removed');
    DB::table('reservation_bill_transfers')->delete();
    DB::table('folios')->update(['consolidated_into_folio_id' => null]);
    $migration->down();
    $check(! \Illuminate\Support\Facades\Schema::hasTable('reservation_bill_transfers') && ! \Illuminate\Support\Facades\Schema::hasColumn('folios', 'consolidated_into_folio_id'), 'empty rollback did not remove schema');
    $migration->up();
    $check(\Illuminate\Support\Facades\Schema::hasTable('reservation_bill_transfers'), 'migration could not be re-applied');
    echo "{$checks} reservation bill consolidation assertions passed (SQLite; not wired into checkout).\n";
}
