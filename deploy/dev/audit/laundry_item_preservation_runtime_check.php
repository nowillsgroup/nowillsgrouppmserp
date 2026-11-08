<?php
/** Real LaundryService on in-memory SQLite; no MySQL row-lock or gateway coverage. */
namespace App\Services {
    class ActivityLogger { public static function log(...$args) {} }
}
namespace {
    function setting($key, $default = null) { return $GLOBALS['settings'][$key] ?? $default; }
    function money($amount, $symbol = true) { return number_format((float) $amount, 2); }
    $root = dirname(__DIR__, 3);
    $temporaryLink = ! is_file($root.'/vendor/autoload.php') && ! file_exists($root.'/build/app');
    if ($temporaryLink) symlink('../app', $root.'/build/app');
    try { $loader = require is_file($root.'/vendor/autoload.php') ? $root.'/vendor/autoload.php' : $root.'/build/vendor/autoload.php'; $loader->addPsr4('App\\', $root.'/app', true); }
    finally { if ($temporaryLink) unlink($root.'/build/app'); }
    $app = new \Illuminate\Container\Container; \Illuminate\Container\Container::setInstance($app);
    $app->instance('config', new \Illuminate\Config\Repository(['app.timezone' => 'UTC', 'database.default' => 'default']));
    \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    $capsule = new \Illuminate\Database\Capsule\Manager($app); $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher($app)); $capsule->setAsGlobal(); $capsule->bootEloquent(); $app->instance('db', $capsule->getDatabaseManager());
    $db = \Illuminate\Support\Facades\DB::class;
    $db::statement('CREATE TABLE laundry_items (id INTEGER PRIMARY KEY, hotel_id INTEGER, name TEXT, category TEXT, is_active INTEGER, wash_rate NUMERIC, dryclean_rate NUMERIC, press_rate NUMERIC, sort_order INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT)');
    $db::statement('CREATE TABLE laundry_orders (id INTEGER PRIMARY KEY, hotel_id INTEGER, order_no TEXT, status TEXT, speed TEXT, subtotal NUMERIC, express_charge NUMERIC, total NUMERIC, pieces INTEGER, collected_at TEXT, promised_at TEXT, ready_at TEXT, created_at TEXT, updated_at TEXT)');
    $db::statement('CREATE TABLE laundry_order_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, laundry_order_id INTEGER, laundry_item_id INTEGER, item_name TEXT, service TEXT, qty INTEGER, rate NUMERIC, amount NUMERIC, remark TEXT)');
    $db::statement("INSERT INTO laundry_items VALUES (1,1,'Shirt renamed','gents',1,90,150,NULL,0,NULL,NULL), (2,1,'Jacket','gents',0,100,NULL,NULL,0,NULL,NULL), (3,1,'Dress','ladies',1,80,NULL,NULL,0,NULL,NULL), (4,2,'Other hotel','gents',1,1,NULL,NULL,0,NULL,NULL)");
    $db::statement("INSERT INTO laundry_orders VALUES (1,1,'L-1','processing','express',190,95,285,4,'2026-11-01 08:00:00',NULL,NULL,NULL,NULL)");
    $db::statement("INSERT INTO laundry_order_lines VALUES (10,1,1,'Shirt','wash',2,45,90,'Cuff'), (11,1,2,'Jacket','wash',1,70,70,'Button'), (12,1,NULL,'Deleted garment','press',1,30,30,'Torn')");
    $hotel = new \App\Models\Hotel; $hotel->forceFill(['id' => 1, 'timezone' => 'UTC']); \App\Support\Tenant::set($hotel);
    $checks = 0; $check = function ($condition, $message) use (&$checks) { $checks++; if (! $condition) throw new \RuntimeException($message); };
    $read = fn () => \App\Models\LaundryOrder::findOrFail(1);
    $state = fn () => \App\Services\LaundryService::itemEditState($read());
    $save = fn ($lines, $revision, $remove = [], $speed = 'express') => \App\Services\LaundryService::setLines($read(), $lines, false, $speed, $revision, $remove);
    $input = [['laundry_item_id' => 1, 'service' => 'wash', 'qty' => 2, 'remark' => 'Cuff']];
    $s = $state();
    $check(count($s['preservedLines']) === 2, 'inactive and deleted items must be preserved');
    $check($s['savedRates'][1]['wash'] === 45.0, 'recorded rate missing from grid');
    $check(isset($s['blockedItems'][2]), 'inactive item should be blocked');
    $order = $save($input, $s['revision']);
    $check($order->lines->count() === 3 && $order->pieces === 4, 'save lost historical lines');
    $shirt = $order->lines->firstWhere('laundry_item_id', 1);
    $check($shirt->item_name === 'Shirt' && (float) $shirt->rate === 45.0, 'saved garment name or price changed');
    $check((float) $order->subtotal === 190.0 && (float) $order->express_charge === 95.0, 'unchanged order was repriced');
    $check($order->lines->contains(fn ($l) => $l->item_name === 'Deleted garment' && $l->remark === 'Torn'), 'deleted garment detail was lost');
    $s = $state();
    $input[0]['qty'] = 3;
    $input[] = ['laundry_item_id' => 3, 'service' => 'wash', 'qty' => 1, 'remark' => ''];
    $order = $save($input, $s['revision']);
    $check((float) $order->subtotal === 315.0 && (float) $order->express_charge === 157.5, 'changed quantities did not preserve effective surcharge');
    $check((float) $order->lines->firstWhere('laundry_item_id', 1)->rate === 45.0, 'quantity change repriced existing service');
    $check((float) $order->lines->firstWhere('laundry_item_id', 3)->rate === 80.0, 'new service did not use current price');
    $stale = $state();
    $db::table('laundry_items')->where('id', 3)->update(['wash_rate' => 100]);
    try { $save($input, $stale['revision']); throw new \RuntimeException('stale catalogue accepted'); }
    catch (\RuntimeException $e) { $check(str_contains($e->getMessage(), 'changed since'), 'catalogue stale message wrong'); }
    $check((float) $read()->subtotal === 315.0, 'stale save changed order');
    $s = $state();
    $preserved = $s['preservedLines']->firstWhere('item_name', 'Jacket');
    try { $save($input, $s['revision'], [9999]); throw new \RuntimeException('invalid removal accepted'); }
    catch (\RuntimeException $e) { $check(str_contains($e->getMessage(), 'Invalid preserved'), 'invalid line removal error wrong'); }
    $order = $save($input, $s['revision'], [$preserved->id]);
    $check(! $order->lines->contains(fn ($l) => $l->item_name === 'Jacket') && $order->lines->contains(fn ($l) => $l->item_name === 'Deleted garment'), 'explicit removal affected wrong lines');
    $check((float) $order->subtotal === 245.0, 'removal did not update subtotal');
    try { $save($input, $s['revision']); throw new \RuntimeException('stale order accepted'); }
    catch (\RuntimeException $e) { $check(str_contains($e->getMessage(), 'changed since'), 'order stale message wrong'); }
    try { $save($input, null); throw new \RuntimeException('missing revision accepted'); }
    catch (\RuntimeException $e) { $check(str_contains($e->getMessage(), 'changed since'), 'missing revision message wrong'); }
    $s = $state();
    $foreignInput = [['laundry_item_id' => 4, 'service' => 'wash', 'qty' => 1]];
    try { $save($foreignInput, $s['revision']); throw new \RuntimeException('cross-hotel item accepted'); }
    catch (\RuntimeException $e) { $check(str_contains($e->getMessage(), 'unavailable'), 'cross-hotel item error wrong'); }
    $db::statement("INSERT INTO laundry_order_lines (laundry_order_id,laundry_item_id,item_name,service,qty,rate,amount,remark) VALUES (1,1,'Shirt','wash',1,45,45,'Different')");
    $s = $state();
    $check(isset($s['blockedItems'][1]) && $s['preservedLines']->where('laundry_item_id', 1)->count() === 2, 'duplicate/conflicting remarks not preserved');
    try { $save($input, $s['revision']); throw new \RuntimeException('editing blocked legacy item accepted'); }
    catch (\RuntimeException $e) { $check(str_contains($e->getMessage(), 'unavailable'), 'legacy collision error wrong'); }
    $db::statement("INSERT INTO laundry_orders VALUES (2,1,'L-2','processing','normal',80,0,80,2,'2026-11-01 08:00:00',NULL,NULL,NULL,NULL)");
    $db::statement("INSERT INTO laundry_order_lines (laundry_order_id,laundry_item_id,item_name,service,qty,rate,amount,remark) VALUES (2,1,'Shirt','wash',1,50,50,'0'), (2,3,'Dress','press',1,30,30,'zip')");
    $second = \App\Models\LaundryOrder::findOrFail(2);
    $secondState = \App\Services\LaundryService::itemEditState($second);
    $check($secondState['remarks'][1] === '0', 'numeric-zero remark disappeared');
    $check($secondState['preservedLines']->count() === 1, 'removed service was not preserved');
    $second = \App\Services\LaundryService::setLines($second, [
        ['laundry_item_id' => 1, 'service' => 'wash', 'qty' => 1, 'remark' => '0'],
        ['laundry_item_id' => 1, 'service' => 'dryclean', 'qty' => 1, 'remark' => '0'],
    ], false, 'normal', $secondState['revision']);
    $check($second->lines->firstWhere('service', 'dryclean')->item_name === 'Shirt', 'new service lost the order garment name');
    $check($second->lines->firstWhere('service', 'wash')->remark === '0', 'numeric-zero remark was deleted on save');
    $check($second->lines->contains(fn ($l) => $l->service === 'press' && $l->remark === 'zip'), 'unavailable service lost on save');
    $check(! isset(\App\Services\LaundryService::itemEditState($second)['blockedItems'][1]), 'new service froze an otherwise valid garment');
    $db::table('laundry_orders')->where('id', 1)->update(['status' => 'delivered']);
    try { $save([], $state()['revision']); throw new \RuntimeException('delivered order edited'); }
    catch (\RuntimeException $e) { $check(str_contains($e->getMessage(), "can't be changed"), 'delivered guard failed'); }
    \App\Support\Tenant::set(null);
    echo "{$checks} laundry item preservation assertions passed (SQLite; MySQL concurrency not tested).\n";
}
