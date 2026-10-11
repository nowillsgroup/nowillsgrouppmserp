<?php

namespace App\Http\Controllers;

use App\Models\KotPrintJob;
use App\Models\KotPrinter;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Driver;
use App\Models\Guest;
use App\Models\Outlet;
use App\Models\PaymentMode;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosPayment;
use App\Models\PosTable;
use App\Models\ReservationRoom;
use App\Services\ActivityLogger;
use App\Services\FolioService;
use App\Services\KotPrintService;
use App\Services\PosModifierService;
use App\Services\TaxService;
use App\Support\Numbering;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    public function index(Request $request)
    {
        $outlets = Outlet::where('is_active', true)->get();
        $outlet = $outlets->firstWhere('id', $request->integer('outlet')) ?? $outlets->first();

        $running = $outlet ? PosOrder::with('table', 'stay.room')->where('outlet_id', $outlet->id)->whereIn('status', ['open', 'billed'])->latest()->get() : collect();
        $pending = PosOrderItem::whereIn('pos_order_id', $running->pluck('id'))->where('is_pending', true)->where('is_cancelled', false)
            ->groupBy('pos_order_id')->selectRaw('pos_order_id, count(*) as n')->pluck('n', 'pos_order_id');

        // v3.2: tables reserved within the next 90 minutes (or late arrivals still being held)
        $reservedSoon = collect();
        if ($outlet?->tb_enabled) {
            foreach (\App\Models\TableReservation::with('tables')->where('outlet_id', $outlet->id)->whereIn('status', \App\Models\TableReservation::UPCOMING)
                ->whereBetween('start_at', [now()->subHours(2), now()->addMinutes(90)])->orderBy('start_at')->get() as $r) {
                foreach ($r->tables as $t) {
                    $reservedSoon[$t->id] ??= $r;
                }
            }
        }

        $tables = $outlet ? PosTable::with('openOrder.items')->where('outlet_id', $outlet->id)->where('is_active', true)->orderBy('id')->get() : collect();

        return view('pos.index', [
            'outlets' => $outlets, 'outlet' => $outlet, 'pending' => $pending, 'reservedSoon' => $reservedSoon,
            'tables' => $tables, 'freeTables' => $tables->filter(fn ($table) => ! $table->openOrder),
            'running' => $running,
            'inHouse' => ReservationRoom::with('room', 'reservation.guest')->where('status', 'checked_in')->get(),
        ]);
    }

    public function guestSearch(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        return response()->json(Guest::whereNull('privacy_status')->search($q)->orderBy('first_name')->orderBy('last_name')->limit(8)->get()
            ->map(fn ($guest) => ['id' => $guest->id, 'name' => $guest->full_name, 'phone' => $guest->phone, 'email' => $guest->email, 'vip' => (bool) $guest->is_vip])->values());
    }

    public function open(Request $request)
    {
        $data = $request->validate([
            'outlet_id' => 'required|integer|owned:outlets', 'pos_table_id' => 'nullable|integer|owned:pos_tables',
            'order_type' => 'required|in:dine_in,takeaway,room_service,delivery', 'reservation_room_id' => 'nullable|required_if:order_type,room_service|integer|owned:reservation_rooms',
            'covers' => 'nullable|integer|min:1|max:50', 'customer_name' => 'nullable|string|max:120', 'customer_phone' => 'nullable|string|max:30',
            'delivery_address' => 'nullable|required_if:order_type,delivery|string|max:255', 'delivery_fee' => 'nullable|required_if:order_type,delivery|numeric|min:0|max:100000',
            'delivery_notes' => 'nullable|string|max:190', 'steward' => 'nullable|string|max:60',
        ]);
        if (! empty($data['pos_table_id'])) {
            $table = PosTable::findOrFail($data['pos_table_id']);
            if (! $table->is_active || (int) $table->outlet_id !== (int) $data['outlet_id']) {
                throw ValidationException::withMessages(['pos_table_id' => 'Choose an active table in the selected outlet.']);
            }
            $existing = PosOrder::where('pos_table_id', $data['pos_table_id'])->whereIn('status', ['open', 'billed'])->first();
            if ($existing) {
                return redirect()->route('pos.terminal', $existing);
            }
        }
        if (! empty($data['reservation_room_id'])) {
            $stay = ReservationRoom::with('reservation.guest')->findOrFail($data['reservation_room_id']);
            abort_unless($stay->status === 'checked_in', 422, 'Room is not in-house.');
            $data['customer_name'] ??= $stay->reservation->guest->full_name;
        }
        $order = PosOrder::create($data + ['order_no' => Numbering::next('pos_order'), 'business_date' => bdate(), 'status' => 'open', 'user_id' => auth()->id(), 'covers' => $data['covers'] ?? 1,
            'delivery_status' => ($data['order_type'] ?? null) === 'delivery' ? 'pending' : 'not_applicable', 'delivery_fee' => $data['delivery_fee'] ?? 0]);

        return redirect()->route('pos.terminal', $order);
    }

    public function terminal(PosOrder $order)
    {
        $order->load('outlet', 'table', 'stay.room', 'items', 'paymentMode', 'payments.paymentMode', 'deliveryDriver', 'folio');
        $cats = MenuCategory::where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $order->outlet_id))->orderBy('sort_order')->get();
        $items = MenuItem::with('category')->with(['modifierGroups' => fn ($q) => $q->where(fn ($w) => $w->whereNull('outlet_id')->orWhere('outlet_id', $order->outlet_id))->with('modifiers')])
            ->where('is_available', true)->whereIn('menu_category_id', $cats->pluck('id'))
            ->where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $order->outlet_id))->orderBy('name')->get();
        $kotPrint = app(KotPrintService::class);
        $kotPrinters = $kotPrint->forOutlet($order->outlet_id);
        $printerMap = [];
        foreach ($items as $item) {
            $fake = new PosOrderItem(['menu_item_id' => $item->id]);
            $fake->setRelation('menuItem', $item);
            $printerMap[$item->id] = $kotPrint->resolve($fake, $kotPrinters, $order->outlet_id)?->name ?? 'Unrouted';
        }
        $flashKot = session('print_kot');
        $flashJobs = $flashKot ? KotPrintJob::with('printer')->where('pos_order_id', $order->id)->where('kot_no', $flashKot)->latest('id')->limit(12)->get() : collect();
        $autoPrintUrl = null;
        if ($flashKot && setting('pos_auto_print_kot', '1') === '1' && ! session('print_void') && ! session('print_ask')) {
            $cap = $flashJobs->first(fn ($j) => $j->kind === 'captain' && $j->printer?->connection === 'browser' && $j->status !== 'failed');
            if ($cap) {
                $autoPrintUrl = route('pos.printkot', [$order, $flashKot]).'?job='.$cap->id.'&autoprint=1';
            } elseif ($flashJobs->isEmpty()) {
                $autoPrintUrl = route('pos.printkot', [$order, $flashKot]).'?autoprint=1';
            } elseif ($comb = $flashJobs->firstWhere('kind', 'combined')) {
                $autoPrintUrl = route('pos.printkot', [$order, $flashKot]).'?job='.$comb->id.'&autoprint=1';
            }
        }

        return view('pos.terminal', [
            'order' => $order, 'cats' => $cats, 'items' => $items, 'allergenMap' => \App\Services\AllergenService::map($items), // v5.0
            'kotPrinters' => $kotPrinters, 'printerMap' => $printerMap, 'printerNames' => KotPrinter::orderBy('name')->pluck('name', 'id'),
            'flashJobs' => $flashJobs, 'autoPrintUrl' => $autoPrintUrl,
            'paymentModes' => PaymentMode::where('is_active', true)->where('type', '!=', 'city_ledger')->pluck('name', 'id'),
            'inHouse' => ReservationRoom::with('room', 'reservation.guest')->where('status', 'checked_in')->get(),
            'freeTables' => PosTable::where('outlet_id', $order->outlet_id)->where('is_active', true)->whereDoesntHave('openOrder')->orderBy('id')->get(),
            'otherOrders' => PosOrder::with('table')->where('outlet_id', $order->outlet_id)->whereIn('status', ['open', 'billed'])->whereKeyNot($order->id)->latest()->get(),
            'deliveryDrivers' => Driver::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    protected function assertNoPending(PosOrder $order): void
    {
        if ($order->items()->where('is_pending', true)->where('is_cancelled', false)->exists()) {
            throw ValidationException::withMessages(['order' => 'This order has guest QR items awaiting confirmation. Accept or reject them first.']);
        }
    }

    /** Accept guest QR items: they get a KOT number, go to the kitchen and join the bill. */
    public function acceptGuest(PosOrder $order)
    {
        $this->assertEditable($order);
        $kot = null;
        DB::transaction(function () use ($order, &$kot) {
            $pending = $order->items()->where('is_pending', true)->where('is_cancelled', false)->lockForUpdate()->get();
            if ($pending->isEmpty()) {
                throw ValidationException::withMessages(['order' => 'No pending guest items.']);
            }
            $kot = (int) Numbering::next('kot');
            $order->items()->whereIn('id', $pending->pluck('id'))->update(['is_pending' => false, 'kot_no' => $kot]);
            $order->user_id ??= auth()->id();
            $order->save();
            $order->recalculate();
            ActivityLogger::log('pos', 'qr_accept', "Accepted {$pending->count()} guest QR item(s) on {$order->order_no} as KOT #{$kot}", $order);
        });
        $fired = $this->fireKot($order->fresh(), (int) $kot);

        return redirect()->route('pos.terminal', $order)->with('success', 'Guest order accepted – '.$fired['message'])->with('print_kot', $kot)->with('print_ask', $fired['ask']);
    }

    public function rejectGuest(Request $request, PosOrder $order)
    {
        $this->assertEditable($order);
        $n = $order->items()->where('is_pending', true)->where('is_cancelled', false)->update(['is_cancelled' => true, 'notes' => 'Rejected by staff']);
        ActivityLogger::log('pos', 'qr_reject', "Rejected {$n} guest QR item(s) on {$order->order_no}", $order);
        if ($order->items()->where('is_cancelled', false)->doesntExist()) {
            $order->forceFill(['status' => 'void', 'void_reason' => 'Guest QR order rejected'])->save();

            return redirect()->route('pos.index', ['outlet' => $order->outlet_id])->with('success', 'Guest order rejected.');
        }

        return back()->with('success', "{$n} guest item(s) rejected.");
    }

    /** Printable sheet of QR codes – one per table – linking to the public menu. */
    public function qrSheet(Request $request)
    {
        $outlets = Outlet::where('is_active', true)->get();
        $outlet = $outlets->firstWhere('id', $request->integer('outlet')) ?? $outlets->first();
        $tables = $outlet ? PosTable::where('outlet_id', $outlet->id)->where('is_active', true)->orderBy('id')->get() : collect();
        $opts = new \chillerlan\QRCode\QROptions(['outputType' => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG, 'outputBase64' => false, 'svgAddXmlHeader' => false, 'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M, 'addQuietzone' => true]);
        $codes = $tables->mapWithKeys(function ($t) use ($opts) {
            $url = route('qrmenu.show', $t->ensureQrToken());

            return [$t->id => ['url' => $url, 'svg' => (new \chillerlan\QRCode\QRCode($opts))->render($url)]];
        });

        return view('pos.qr', ['outlets' => $outlets, 'outlet' => $outlet, 'tables' => $tables, 'codes' => $codes,
            'enabled' => setting('qr_menu_enabled', '1') === '1']);
    }

    public function qrRegenerate(PosTable $table)
    {
        $table->regenerateQrToken();
        ActivityLogger::log('pos', 'qr_regenerate', "Regenerated QR code for table {$table->table_no}", $table);

        return back()->with('success', "New QR code issued for table {$table->table_no}. Old printed code no longer works.");
    }

    protected function assertEditable(PosOrder $order): void
    {
        if (! in_array($order->status, ['open', 'billed'])) {
            throw ValidationException::withMessages(['order' => 'Order is '.$order->status.' and cannot be modified.']);
        }
    }

    protected function assertPaidAmountFits(PosOrder $order): void
    {
        $paid = $order->paidAmount();
        if ($paid > (float) $order->total + 0.005) {
            throw ValidationException::withMessages(['order' => 'This change would reduce the bill below payments already received ('.money($paid).'). Refund or reverse the payment first.']);
        }
    }

    public function kot(Request $request, PosOrder $order)
    {
        $this->assertEditable($order);
        $data = $request->validate([
            'items' => 'nullable|array|max:40', 'items.*.id' => 'required|integer|owned:menu_items', 'items.*.qty' => 'required|numeric|min:0.5|max:999', 'items.*.notes' => 'nullable|string|max:120', 'items.*.modifiers' => 'nullable|array|max:20', 'items.*.modifiers.*' => 'integer|owned:menu_modifiers', 'items.*.allergy_ack' => 'nullable|boolean', 'items.*.hold' => 'nullable|boolean',
            'open' => 'nullable|array|max:12', 'open.*.name' => 'required|string|max:80', 'open.*.price' => 'required|numeric|min:0|max:100000', 'open.*.qty' => 'required|numeric|min:0.5|max:99', 'open.*.notes' => 'nullable|string|max:200', 'open.*.station' => 'required|string|max:40', 'open.*.food_type' => 'required|in:veg,non_veg,egg,beverage', 'open.*.allergy_ack' => 'nullable|boolean', 'open.*.hold' => 'nullable|boolean',
            'print_to' => 'nullable|in:auto,selected,combined', 'printers' => 'nullable|array|max:12', 'printers.*' => 'integer', 'force_printers' => 'nullable|boolean',
        ]);
        $menuLines = array_values($data['items'] ?? []);
        $openLines = [];
        foreach (array_values($data['open'] ?? []) as $k => $line) {
            $name = trim(preg_replace('/\s+/', ' ', strip_tags($line['name'])));
            if (mb_strlen($name) < 2) {
                throw ValidationException::withMessages(["open.$k.name" => 'Enter the dish the kitchen should prepare.']);
            }
            $line['name'] = mb_substr($name, 0, 80);
            $line['notes'] = filled($line['notes'] ?? null) ? mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($line['notes']))), 0, 200) : null;
            $openLines[] = $line;
        }
        if ($menuLines === [] && $openLines === []) {
            throw ValidationException::withMessages(['items' => 'Add a menu item or an open item.']);
        }
        if (($data['print_to'] ?? 'auto') === 'selected' && empty($data['printers'])) {
            throw ValidationException::withMessages(['printers' => 'Select at least one printer, or use auto-route.']);
        }
        $stations = app(KotPrintService::class)->forOutlet($order->outlet_id)->where('role', 'station')->pluck('station')->filter()->unique()->values();
        if (! $stations->contains(fn ($s) => strcasecmp((string) $s, 'Kitchen') === 0)) {
            $stations->push('Kitchen');
        }
        foreach ($openLines as $k => $line) {
            $hit = $stations->first(fn ($s) => strcasecmp((string) $s, $line['station']) === 0);
            if (! $hit) {
                throw ValidationException::withMessages(["open.$k.station" => 'Choose a kitchen station.']);
            }
            $openLines[$k]['station'] = $hit;
        }
        // v5.0 guest allergies: a dish that contains (or may contain) a declared allergen needs an explicit confirmation
        $guest = \App\Services\AllergenService::clean($order->allergens);
        $flags = [];
        $modifierSnapshots = [];
        $modifierAmounts = [];
        foreach ($menuLines as $k => $line) {
            $m = MenuItem::with(['recipes.inventoryItem', 'modifierGroups' => fn ($q) => $q->where(fn ($w) => $w->whereNull('outlet_id')->orWhere('outlet_id', $order->outlet_id))->with('modifiers')])->findOrFail($line['id']);
            if (! $m->is_available || ($m->outlet_id && (int) $m->outlet_id !== (int) $order->outlet_id)) {
                throw ValidationException::withMessages(["items.{$k}.id" => "{$m->name} is not available at this outlet."]);
            }
            $flags[$k] = $guest ? \App\Services\AllergenService::flagText(\App\Services\AllergenService::conflicts($m, $guest)) : null;
            if ($flags[$k] && empty($line['allergy_ack'])) {
                throw ValidationException::withMessages(['items' => "{$m->name}: {$flags[$k]} – the guest declared this allergy. Check with the chef, then confirm the item to send it."]);
            }
            [$modifierSnapshots[$k], $modifierAmounts[$k]] = PosModifierService::snapshot($m, $line['modifiers'] ?? [], $k);
        }
        $openFlags = [];
        if ($guest) {
            $openFlags['text'] = mb_substr('SPECIAL – guest allergies: '.\App\Services\AllergenService::names($guest).'. Chef confirmed.', 0, 160);
            foreach ($openLines as $k => $line) {
                if (empty($line['allergy_ack'])) {
                    throw ValidationException::withMessages(['open' => 'This guest has allergies. Confirm with the chef before sending a special order.']);
                }
            }
        }
        $kot = null;
        DB::transaction(function () use ($menuLines, $openLines, $order, &$kot, $flags, $openFlags, $modifierSnapshots, $modifierAmounts) {
            $kot = (int) Numbering::next('kot');
            foreach ($menuLines as $k => $line) {
                $m = MenuItem::findOrFail($line['id']);
                $rate = round((float) $m->price + (float) ($modifierAmounts[$k] ?? 0), 2);
                $rate = TaxService::splitPrice($rate, (float) $m->tax_rate, 'fnb')['net']; // v7.39 tax-inclusive support
                $order->items()->create(['menu_item_id' => $m->id, 'item_name' => $m->name, 'qty' => $line['qty'], 'rate' => $rate, 'modifier_amount' => $modifierAmounts[$k] ?? 0, 'modifiers' => $modifierSnapshots[$k] ?? [], 'tax_rate' => $m->tax_rate, 'amount' => round($rate * $line['qty'], 2), 'kot_no' => $kot, 'notes' => $line['notes'] ?? null, 'allergy_flag' => $flags[$k], 'kitchen_status' => ! empty($line['hold']) ? 'held' : 'new']);
            }
            foreach ($openLines as $line) {
                $rate = round((float) $line['price'], 2);
                $qty = (float) $line['qty'];
                $openRate = TaxService::rateFor('fnb', $rate);
                $rate = TaxService::splitPrice($rate, $openRate, 'fnb')['net']; // v7.39 tax-inclusive support
                $order->items()->create([
                    'menu_item_id' => null, 'item_name' => $line['name'], 'qty' => $qty, 'rate' => $rate,
                    'tax_rate' => $openRate, 'amount' => round($rate * $qty, 2), 'kot_no' => $kot,
                    'notes' => $line['notes'], 'allergy_flag' => $openFlags['text'] ?? null, 'is_open' => true,
                    'kitchen_station' => $line['station'], 'food_type' => $line['food_type'],
                    'kitchen_status' => ! empty($line['hold']) ? 'held' : 'new',
                ]);
            }
            if ($openLines) {
                ActivityLogger::log('pos', 'open_item', 'Special order on '.$order->order_no.': '.collect($openLines)->pluck('name')->implode(', '), $order);
            }
            $order->status = 'open';
            $order->save();
            $order->recalculate();
        });
        $opt = ['mode' => 'auto'];
        if (($data['print_to'] ?? 'auto') === 'combined') {
            $opt['combined'] = true;
        }
        if (($data['print_to'] ?? 'auto') === 'selected') {
            $opt = ['mode' => 'manual', 'printers' => $data['printers'], 'force' => $request->boolean('force_printers'), 'captain' => false];
        }
        $fired = $this->fireKot($order->fresh(), (int) $kot, $opt);
        if ($openLines) {
            $fired['message'] .= ' Special order'.(count($openLines) > 1 ? 's' : '').' included.';
        }
        $held = collect($menuLines)->merge($openLines)->filter(fn ($l) => ! empty($l['hold']))->count();
        if ($held) {
            $fired['message'] .= ' '.$held.' on hold — fire when the table is ready.';
        }

        return redirect()->route('pos.terminal', $order)->with('success', $fired['message'])->with('print_kot', $kot)->with('print_ask', $fired['ask']);
    }

    /** v5.0 Record the guests' allergies on the order (flags lines already sent). */
    public function allergies(Request $request, PosOrder $order)
    {
        $this->assertEditable($order);
        $data = $request->validate(['allergens' => 'nullable|array', 'allergens.*' => 'string|in:'.implode(',', array_keys(\App\Services\AllergenService::ALLERGENS)), 'allergy_note' => 'nullable|string|max:160']);
        \App\Services\AllergenService::setOrderAllergies($order, $data['allergens'] ?? [], $data['allergy_note'] ?? null);
        $flagged = $order->items()->where('is_cancelled', false)->whereNotNull('allergy_flag')->count();

        return back()->with($flagged ? 'warning' : 'success', ($order->allergens ? 'Guest allergies recorded: '.\App\Services\AllergenService::names($order->allergens).'.' : 'Guest allergies cleared.')
            .($flagged ? " {$flagged} item(s) already sent contain them – tell the kitchen now." : ''));
    }

    public function updateDetails(Request $request, PosOrder $order)
    {
        $this->assertEditable($order);
        $data = $request->validate([
            'customer_name' => 'nullable|string|max:120',
            'customer_phone' => 'nullable|string|max:30',
            'covers' => 'required|integer|min:1|max:50',
            'steward' => 'nullable|string|max:60',
        ]);
        $order->forceFill($data)->save();
        ActivityLogger::log('pos', 'details', "Updated guest and service details on {$order->order_no}", $order);

        return back()->with('success', 'Order details updated.');
    }

    public function cancelItem(Request $request, PosOrder $order, PosOrderItem $line)
    {
        abort_unless($line->pos_order_id === $order->id, 404);
        $this->assertEditable($order);
        abort_if($line->is_cancelled, 422, 'This item is already cancelled.');
        abort_if($line->is_pending, 422, 'Pending guest items must be accepted or rejected before they can be cancelled.');
        $request->validate(['reason' => 'required|string|max:120']);
        DB::transaction(function () use ($request, $order, $line) {
            $line->update(['is_cancelled' => true, 'notes' => trim(($line->notes ?? '').' [Cancelled: '.$request->input('reason').']')]);
            $order->recalculate();
            $this->assertPaidAmountFits($order);
            ActivityLogger::log('pos', 'cancel_item', "Cancelled {$line->item_name} on {$order->order_no}", $order);
        });
        $voids = $line->kot_no ? app(KotPrintService::class)->voidLine($order, $line->fresh()) : collect();
        $msg = 'Item cancelled.'.($voids->isNotEmpty() ? ' Void slip — '.app(KotPrintService::class)->jobSummary($voids).'.' : '');

        return back()->with('success', $msg)->with('print_kot', $line->kot_no)->with('print_void', $line->kot_no ? 1 : null);
    }

    /** Give a fired dish away. The kitchen still prepares it; it drops off the guest's bill. */
    public function compItem(Request $request, PosOrder $order, PosOrderItem $line)
    {
        abort_unless($line->pos_order_id === $order->id, 404);
        $this->assertEditable($order);
        abort_if($line->is_cancelled || $line->is_pending, 422, 'Only a dish that has been sent to the kitchen can be complimentary.');
        if ($request->boolean('undo')) {
            abort_unless($line->is_complimentary, 422, 'This item is not complimentary.');
            DB::transaction(function () use ($order, $line) {
                $line->forceFill(['is_complimentary' => false, 'comp_reason' => null, 'comp_by' => null, 'comp_at' => null, 'amount' => round((float) $line->qty * (float) $line->rate, 2)])->save();
                $order->recalculate();
                $this->assertPaidAmountFits($order);
                ActivityLogger::log('pos', 'comp_undo', "Charged {$line->item_name} again on {$order->order_no}", $order);
            });

            return back()->with('success', "{$line->item_name} is charged again.");
        }
        $data = $request->validate([
            'reason' => 'required|in:'.implode(',', array_keys(PosOrder::COMP_REASONS)),
            'note' => 'nullable|string|max:80',
        ]);
        $reason = PosOrder::COMP_REASONS[$data['reason']].(filled($data['note'] ?? null) ? ' – '.$data['note'] : '');
        DB::transaction(function () use ($order, $line, $reason) {
            $line->forceFill(['is_complimentary' => true, 'comp_reason' => $reason, 'comp_by' => auth()->id(), 'comp_at' => now(), 'amount' => 0])->save();
            $order->recalculate();
            $this->assertPaidAmountFits($order);
            ActivityLogger::log('pos', 'comp_item', "Complimentary {$line->item_name} on {$order->order_no}: {$reason}", $order);
        });

        return back()->with('success', "{$line->item_name} is complimentary ({$reason}). The kitchen still prepares it.");
    }

    public function moveTable(Request $request, PosOrder $order)
    {
        $data = $request->validate(['pos_table_id' => 'required|integer|owned:pos_tables']);

        try {
            \App\Services\PosOpsService::moveTable($order, PosTable::findOrFail($data['pos_table_id']));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Order moved to table '.$order->fresh('table')->table->table_no.'.');
    }

    public function merge(Request $request, PosOrder $order)
    {
        $data = $request->validate(['source_order_id' => 'required|integer|owned:pos_orders']);
        $source = PosOrder::findOrFail($data['source_order_id']);

        try {
            \App\Services\PosOpsService::merge($order, $source);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('pos.terminal', $order)->with('success', "Order {$source->order_no} merged into this bill.");
    }

    public function split(Request $request, PosOrder $order)
    {
        $data = $request->validate(['lines' => 'required|array', 'lines.*' => 'nullable|numeric|min:0|max:999']);

        try {
            $new = \App\Services\PosOpsService::split($order, $data['lines']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('pos.terminal', $new)->with('success', "Split bill {$new->order_no} created. The original is {$order->order_no}.");
    }

    /** Release held dishes so the kitchen can start. One line, or every dish still on hold. */
    public function fireHeld(Request $request, PosOrder $order)
    {
        $this->assertEditable($order);
        $data = $request->validate(['item_id' => 'nullable|integer']);
        $q = $order->items()->where('kitchen_status', 'held')->where('is_cancelled', false)->where('is_pending', false);
        if (! empty($data['item_id'])) {
            $q->whereKey($data['item_id']);
        }
        $lines = $q->get();
        abort_if($lines->isEmpty(), 422, 'Nothing is on hold.');
        foreach ($lines as $line) {
            $line->forceFill(['kitchen_status' => 'new', 'fired_at' => now()])->save();
        }
        $jobs = app(KotPrintService::class)->release($order->fresh(), $lines);
        ActivityLogger::log('pos', 'kot_fire', 'Fired '.$lines->pluck('item_name')->implode(', ').' on '.$order->order_no, $order);
        $summary = app(KotPrintService::class)->jobSummary($jobs);

        return back()->with('success', 'Fired '.$lines->count().' held dish'.($lines->count() === 1 ? '' : 'es').'. The kitchen can start.'.($summary ? ' — '.$summary.'.' : ''))
            ->with('print_kot', $lines->first()->kot_no);
    }

    /** Steward marks a ready (or any) kitchen item as served. */
    public function served(PosOrder $order, PosOrderItem $line)
    {
        abort_unless($line->pos_order_id === $order->id && ! $line->is_cancelled, 404);
        $this->assertEditable($order);
        abort_if($line->is_pending, 422, 'Pending guest items must be accepted before they can be served.');
        $line->forceFill(['kitchen_status' => 'served', 'served_at' => now(), 'ready_at' => $line->ready_at ?? now()])->save();

        return back();
    }

    public function bill(Request $request, PosOrder $order)
    {
        $this->assertEditable($order);
        $data = $request->validate(['discount' => 'nullable|numeric|min:0', 'discount_type' => 'nullable|in:amount,percent', 'member_code' => 'nullable|string|max:40']);
        $this->assertNoPending($order);
        abort_if($order->items()->where('is_cancelled', false)->where('is_pending', false)->count() === 0, 422, 'No items on order.');
        $disc = (float) ($data['discount'] ?? 0);
        if (($data['discount_type'] ?? 'amount') === 'percent') {
            $disc = round($order->items()->where('is_cancelled', false)->where('is_pending', false)->sum('amount') * min(100, $disc) / 100, 2);
        }
        // v5.1 health club member: the plan's F&B discount (the better of it and any manual discount)
        $memberNote = '';
        $order->club_member_id = null;
        if (filled($data['member_code'] ?? null)) {
            $mem = \App\Services\ClubService::memberDiscount(trim($data['member_code']));
            if (! $mem['member']) {
                return back()->with('error', $mem['reason']);
            }
            $order->club_member_id = $mem['member']->id;
            $sub = (float) $order->items()->where('is_cancelled', false)->where('is_pending', false)->sum('amount');
            $md = round($sub * $mem['pct'] / 100, 2);
            if ($md > $disc) {
                $disc = $md;
                $memberNote = " Member discount {$mem['pct']}% for {$mem['member']->name}.";
            } else {
                $memberNote = " Member {$mem['member']->name} recorded".($mem['pct'] > 0 ? ' (manual discount is higher).' : ' – the plan has no F&B discount.');
            }
        }
        DB::transaction(function () use ($order, $disc) {
            $order->discount = $disc;
            $order->bill_no ??= Numbering::next('pos_bill');
            $order->status = 'billed';
            $order->save();
            $order->recalculate();
            $this->assertPaidAmountFits($order);
        });

        return redirect()->route('pos.terminal', $order)->with('success', "Bill {$order->bill_no} generated.".$memberNote);
    }

    public function settle(Request $request, PosOrder $order)
    {
        $this->assertEditable($order);
        $this->assertNoPending($order);
        if ($request->input('settlement') === 'payment') {
            try {
                \App\Services\ShiftService::assertOpen();
            } catch (\RuntimeException $e) {
                return back()->with('error', $e->getMessage());
            }
        }
        if ($request->input('settlement') === 'complimentary' && ! $request->filled('comp_reason') && $request->filled('reason')) {
            $request->merge(['comp_reason' => $request->input('reason')]);
        }
        $data = $request->validate([
            'settlement' => 'required|in:payment,room_post,complimentary',
            'comp_reason' => 'nullable|required_if:settlement,complimentary|string|min:3|max:120',
            'payment_mode_id' => 'nullable|integer|owned:payment_modes',
            'reservation_room_id' => 'nullable|required_if:settlement,room_post|integer|owned:reservation_rooms',
            'amount' => 'nullable|numeric|min:0.01',
            'tendered_amount' => 'nullable|numeric|min:0',
            'allocations' => 'nullable|array|max:10',
            'allocations.*.payment_mode_id' => 'required|integer|owned:payment_modes',
            'allocations.*.amount' => 'required|numeric|min:0.01',
            'allocations.*.tendered_amount' => 'nullable|numeric|min:0',
            'allocations.*.reference' => 'nullable|string|max:80',
            'allocations.*.cheque_bank_name' => 'nullable|string|max:100',
            'allocations.*.cheque_number' => 'nullable|string|max:30',
            'allocations.*.cheque_bank_branch' => 'nullable|string|max:100',
            'allocations.*.card_authorisation' => 'nullable|string|max:80',
            'allocations.*.mobile_network' => 'nullable|string|max:30',
            'allocations.*.mobile_phone' => 'nullable|string|max:30',
            'allocations.*.payment_notes' => 'nullable|string|max:190',
            'reference' => 'nullable|string|max:80',
            'cheque_bank_name' => 'nullable|string|max:100',
            'cheque_number' => 'nullable|string|max:30',
            'cheque_bank_branch' => 'nullable|string|max:100',
            'card_authorisation' => 'nullable|string|max:80',
            'mobile_network' => 'nullable|string|max:30',
            'mobile_phone' => 'nullable|string|max:30',
            'payment_notes' => 'nullable|string|max:190',
            'voucher_code' => 'nullable|string|max:20',
            'buyer_tin' => ['nullable', 'string', 'max:20', function ($a, $v, $fail) { if (filled($v) && ! \App\Services\GstService::validTin($v)) { $fail('Enter a valid Ghana TIN (e.g. C0012345678) or Ghana Card PIN (GHA-123456789-0).'); } }],
            'buyer_name' => 'nullable|required_with:buyer_tin|string|max:120',
        ]);
        $result = ['status' => 'settled', 'amount' => 0.0, 'change' => 0.0];
        try {
            DB::transaction(function () use ($data, $order, &$result) {
            // Settlement is a financial write: serialize concurrent cashier requests before calculating the balance.
            $order = PosOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($order);
            $this->assertNoPending($order);
            $order->bill_no ??= Numbering::next('pos_bill');
            $order->recalculate();
            $order->comp_reason = $data['settlement'] === 'complimentary' ? $data['comp_reason'] : null;
            $order->settlement = $data['settlement'];
            if ($data['settlement'] === 'payment') {
                $usingAllocations = ! empty($data['allocations']);
                if (! $usingAllocations && empty($data['payment_mode_id'])) {
                    throw ValidationException::withMessages(['payment_mode_id' => 'Choose a payment mode.']);
                }
                $allocations = $usingAllocations ? array_values($data['allocations']) : [[
                    'payment_mode_id' => $data['payment_mode_id'],
                    'amount' => $data['amount'] ?? null,
                    'tendered_amount' => $data['tendered_amount'] ?? null,
                    'reference' => $data['reference'] ?? null,
                    'cheque_bank_name' => $data['cheque_bank_name'] ?? null,
                    'cheque_number' => $data['cheque_number'] ?? null,
                    'cheque_bank_branch' => $data['cheque_bank_branch'] ?? null,
                    'card_authorisation' => $data['card_authorisation'] ?? null,
                    'mobile_network' => $data['mobile_network'] ?? null,
                    'mobile_phone' => $data['mobile_phone'] ?? null,
                    'payment_notes' => $data['payment_notes'] ?? null,
                ]];
                $due = $order->balanceDue();
                if ($due <= 0.005) {
                    throw ValidationException::withMessages(['payment_mode_id' => 'This bill is already fully paid.']);
                }
                $allocated = 0.0;
                $change = 0.0;
                $voucherModeId = null;
                foreach ($allocations as $index => $allocation) {
                    $mode = PaymentMode::findOrFail($allocation['payment_mode_id']);
                    if (! $mode->is_active || $mode->type === 'city_ledger') {
                        throw ValidationException::withMessages([$usingAllocations ? "allocations.{$index}.payment_mode_id" : 'payment_mode_id' => 'Choose an active direct POS payment mode.']);
                    }
                    $amount = round((float) ($allocation['amount'] ?? ($due - $allocated)), 2);
                    if ($amount <= 0) {
                        throw ValidationException::withMessages([$usingAllocations ? "allocations.{$index}.amount" : 'amount' => 'Enter an amount greater than zero.']);
                    }
                    if ($allocated + $amount > $due + 0.005) {
                        throw ValidationException::withMessages([$usingAllocations ? "allocations.{$index}.amount" : 'amount' => 'The payment allocations cannot exceed the remaining balance.']);
                    }
                    $hasTendered = array_key_exists('tendered_amount', $allocation) && $allocation['tendered_amount'] !== null;
                    $tendered = $hasTendered ? round((float) $allocation['tendered_amount'], 2) : $amount;
                    $allocationChange = 0.0;
                    if ($mode->type === 'cash') {
                        if ($tendered + 0.005 < $amount) {
                            throw ValidationException::withMessages([$usingAllocations ? "allocations.{$index}.tendered_amount" : 'tendered_amount' => 'Cash received is less than the amount being paid.']);
                        }
                        $allocationChange = round($tendered - $amount, 2);
                    } elseif ($hasTendered && abs($tendered - $amount) > 0.005) {
                        throw ValidationException::withMessages([$usingAllocations ? "allocations.{$index}.tendered_amount" : 'tendered_amount' => 'Tendered amount must equal the payment for non-cash tenders.']);
                    }
                    if (\App\Services\GiftVoucherService::isVoucherMode((int) $mode->id)) {
                        if (count($allocations) !== 1 || abs($amount - $due) > 0.005) {
                            throw ValidationException::withMessages([$usingAllocations ? "allocations.{$index}.amount" : 'amount' => 'A gift voucher must cover the remaining bill in one payment.']);
                        }
                        $voucherModeId = (int) $mode->id;
                    }
                    $order->settlement = 'payment';
                    if (filled($data['buyer_tin'] ?? null)) { // v4.4: business customer's TIN on the E-VAT stamped bill
                        $order->buyer_tin = \App\Services\GstService::normaliseTin($data['buyer_tin']);
                        $order->buyer_name = $data['buyer_name'];
                    }
                    $order->payments()->create([
                        'payment_mode_id' => $mode->id, 'kind' => 'payment', 'amount' => $amount,
                        'tendered_amount' => $mode->type === 'cash' ? $tendered : null, 'change_amount' => $allocationChange,
                        'reference' => filled($allocation['reference'] ?? null) ? trim($allocation['reference']) : null,
                        'cheque_bank_name' => $allocation['cheque_bank_name'] ?? null, 'cheque_number' => $allocation['cheque_number'] ?? null,
                        'cheque_bank_branch' => $allocation['cheque_bank_branch'] ?? null, 'card_authorisation' => $allocation['card_authorisation'] ?? null,
                        'mobile_network' => $allocation['mobile_network'] ?? null, 'mobile_phone' => $allocation['mobile_phone'] ?? null,
                        'notes' => $allocation['payment_notes'] ?? null, 'business_date' => $order->business_date?->toDateString() ?? bdate()->toDateString(),
                        'received_by' => auth()->id(), 'received_at' => now(),
                    ]);
                    $allocated = round($allocated + $amount, 2);
                    $change = round($change + $allocationChange, 2);
                }
                $paid = round($order->paidAmount(), 2);
                $result = ['status' => $paid + 0.005 >= (float) $order->total ? 'settled' : 'partial', 'amount' => $allocated, 'change' => $change];
                $modes = $order->payments()->where('kind', 'payment')->pluck('payment_mode_id')->unique();
                $order->payment_mode_id = $modes->count() === 1 ? $modes->first() : null;
                if ($result['status'] === 'settled') {
                    $order->status = 'settled';
                    $order->settled_at = now();
                    $order->settled_by = auth()->id();
                    if ($voucherModeId) {
                        \App\Services\GiftVoucherService::posPayment($order, $data['voucher_code'] ?? null, $allocated);
                    }
                    \App\Services\RecipeService::consume($order);
                } else {
                    $order->status = 'billed';
                    $order->settled_at = null;
                    $order->settled_by = null;
                }
            } elseif ($data['settlement'] === 'room_post') {
                $stay = ReservationRoom::findOrFail($data['reservation_room_id']);
                if ($stay->status !== 'checked_in') {
                    throw ValidationException::withMessages(['reservation_room_id' => 'Room is not in-house.']);
                }
                $folio = FolioService::openForStay($stay);
                $net = (float) $order->subtotal - (float) $order->discount + (float) $order->service_charge + (float) $order->round_off;
                FolioService::charge($folio, 'fnb', "{$order->outlet->name} – Bill {$order->bill_no}", $net, false,
                    ['tax_amount' => $order->tax, 'tax_rate' => $net > 0 ? round($order->tax * 100 / $net, 2) : 0, 'source_type' => 'pos', 'source_id' => $order->id]);
                $order->reservation_room_id = $stay->id;
                $order->folio_id = $folio->id;
                $order->status = 'settled';
                $order->settled_at = now();
                $order->settled_by = auth()->id();
                \App\Services\RecipeService::consume($order);
            } else {
                $order->status = 'settled';
                $order->settled_at = now();
                $order->settled_by = auth()->id();
                \App\Services\RecipeService::consume($order);
            }
            $order->save();
            $label = $result['status'] === 'partial' ? 'Part payment' : 'Settled';
            ActivityLogger::log('pos', 'settle', "{$label} {$order->bill_no} ".money($result['amount'] ?: $order->total)." via {$data['settlement']}", $order);
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $order->refresh();
        $ev = match ($order->evat_status) { // v4.4 GRA E-VAT
            'generated' => " Stamped by GRA E-VAT (receipt {$order->evat_rec_num}).",
            'failed' => ' E-VAT stamping failed – see Billing › VAT › E-VAT; it will be retried automatically.',
            'pending' => ' Queued for GRA E-VAT.',
            default => '',
        };
        $message = $result['status'] === 'partial'
            ? 'Part payment of '.money($result['amount']).' recorded. Balance due: '.money($order->balanceDue()).'.'
            : 'Bill '.$order->bill_no.' settled.'.($result['change'] > 0 ? ' Change: '.money($result['change']).'.' : '').$ev;

        $redirect = $result['status'] === 'partial'
            ? redirect()->route('pos.terminal', $order)
            : redirect()->route('pos.index', ['outlet' => $order->outlet_id]);

        return $redirect->with($order->evat_status === 'failed' ? 'warning' : 'success', $message);
    }

    public function deliveryStatus(Request $request, PosOrder $order)
    {
        abort_unless($order->order_type === 'delivery', 422, 'This order is not a delivery order.');
        $this->assertEditable($order);
        $data = $request->validate([
            'delivery_status' => 'required|in:pending,preparing,out_for_delivery,delivered,failed,cancelled',
            'delivery_driver_id' => 'nullable|integer|owned:drivers',
            'delivery_address' => 'required|string|max:255',
            'delivery_notes' => 'nullable|string|max:190',
        ]);
        $driverId = $data['delivery_driver_id'] ?? $order->delivery_driver_id;
        if (in_array($data['delivery_status'], ['out_for_delivery', 'delivered'], true) && empty($driverId)) {
            throw ValidationException::withMessages(['delivery_driver_id' => 'Assign a driver before dispatching or completing the delivery.']);
        }
        if ($driverId && in_array($data['delivery_status'], ['out_for_delivery', 'delivered'], true) && ! Driver::whereKey($driverId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['delivery_driver_id' => 'The selected delivery driver is not active.']);
        }
        $was = $order->delivery_status;
        $order->forceFill([
            'delivery_status' => $data['delivery_status'], 'delivery_driver_id' => $data['delivery_driver_id'] ?? $order->delivery_driver_id,
            'delivery_address' => trim($data['delivery_address']), 'delivery_notes' => filled($data['delivery_notes'] ?? null) ? trim($data['delivery_notes']) : null,
            'delivered_at' => $data['delivery_status'] === 'delivered' ? ($order->delivered_at ?? now()) : null,
        ])->save();
        ActivityLogger::log('pos', 'delivery_status', "Delivery {$order->order_no}: {$was} → {$order->delivery_status}", $order);

        return back()->with('success', 'Delivery status updated.');
    }

    public function refund(Request $request, PosOrder $order)
    {
        abort_unless($order->status === 'settled' && $order->settlement === 'payment', 422, 'Only a settled direct POS payment can be refunded.');
        try {
            \App\Services\ShiftService::assertOpen();
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $data = $request->validate([
            'payment_id' => 'required|integer|owned:pos_payments',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:190',
            'reference' => 'nullable|string|max:80',
        ]);
        try {
            DB::transaction(function () use ($data, $order) {
                $payment = PosPayment::whereKey($data['payment_id'])->where('pos_order_id', $order->id)->where('kind', 'payment')->lockForUpdate()->first();
                if (! $payment) {
                    throw ValidationException::withMessages(['payment_id' => 'Choose an original payment on this order.']);
                }
                $remaining = $payment->refundableAmount();
                $amount = round((float) $data['amount'], 2);
                if ($amount < 0.01) {
                    throw ValidationException::withMessages(['amount' => 'The refund must be at least 0.01.']);
                }
                if ($amount > $remaining + 0.005) {
                    throw ValidationException::withMessages(['amount' => 'The refund exceeds the remaining amount on this payment ('.money($remaining).').']);
                }
                if (\App\Services\GiftVoucherService::isVoucherMode((int) $payment->payment_mode_id)) {
                    \App\Services\GiftVoucherService::posRefund($order, $amount);
                }
                $refund = $order->payments()->create([
                    'refund_of_id' => $payment->id, 'payment_mode_id' => $payment->payment_mode_id, 'kind' => 'refund',
                    'amount' => $amount, 'reference' => filled($data['reference'] ?? null) ? trim($data['reference']) : null,
                    'notes' => trim($data['reason']), 'business_date' => $order->business_date?->toDateString() ?? bdate()->toDateString(),
                    'received_by' => auth()->id(), 'received_at' => now(),
                ]);
                // The POS order is the GL source; touch it so a post-settlement refund is picked up by incremental ledger sync.
                $order->touch();
                ActivityLogger::log('pos', 'refund', 'Refunded '.money($amount).' from '.$order->bill_no.': '.$data['reason'], $refund);
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('pos.terminal', $order)->with('success', 'POS refund of '.money((float) $data['amount']).' recorded.');
    }

    public function void(Request $request, PosOrder $order)
    {
        $this->assertEditable($order);
        $data = $request->validate(['reason' => 'required|string|max:200']);
        $order->update(['status' => 'void', 'void_reason' => $data['reason']]);
        $svc = app(KotPrintService::class);
        foreach ($order->items()->whereNotNull('kot_no')->where('is_cancelled', false)->pluck('kot_no')->unique() as $kotNo) {
            $svc->voidKot($order->fresh(), (int) $kotNo);
        }
        ActivityLogger::log('pos', 'void', "Voided order {$order->order_no}: {$data['reason']}", $order);

        return redirect()->route('pos.index', ['outlet' => $order->outlet_id])->with('success', 'Order voided.');
    }

    public function orders(Request $request)
    {
        $from = $request->input('from', bdate()->toDateString());
        $to = $request->input('to', bdate()->toDateString());
        $search = trim((string) $request->input('q', ''));
        $orders = PosOrder::with('outlet', 'table', 'paymentMode', 'payments.paymentMode', 'stay.room', 'user')
            ->whereBetween('business_date', [$from, $to])
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(function ($match) use ($like) {
                    $match->where('order_no', 'like', $like)
                        ->orWhere('bill_no', 'like', $like)
                        ->orWhere('customer_name', 'like', $like)
                        ->orWhere('customer_phone', 'like', $like)
                        ->orWhere('steward', 'like', $like);
                });
            })
            ->when($request->outlet, fn ($q, $o) => $q->where('outlet_id', $o))
            ->when($request->status, function ($q, $status) {
                $status === 'unsettled' ? $q->whereIn('status', ['open', 'billed']) : $q->where('status', $status);
            })
            ->latest()->get();

        return view('pos.orders', ['orders' => $orders, 'from' => $from, 'to' => $to, 'q' => $search, 'outlets' => Outlet::pluck('name', 'id')]);
    }

    public function printBill(PosOrder $order)
    {
        $order->load('outlet', 'table', 'items', 'paymentMode', 'payments.paymentMode', 'stay.room', 'user');

        return view('pos.bill', compact('order'));
    }

    public function printKot(Request $request, PosOrder $order, int $kot)
    {
        $order->load('outlet', 'table', 'stay.room', 'items.menuItem.category');
        $svc = app(KotPrintService::class);
        $printers = KotPrinter::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        $all = $order->items->where('kot_no', $kot)->values();
        if ($jobId = $request->integer('job')) {
            $job = KotPrintJob::with('printer')->where('pos_order_id', $order->id)->where('kot_no', $kot)->findOrFail($jobId);
            $items = $all->whereIn('id', $job->item_ids ?? [])->values();
            if ($items->isEmpty()) {
                $items = PosOrderItem::whereIn('id', $job->item_ids ?? [])->orderBy('id')->get();
            }
            $slips = [[
                'printer' => $job->printer, 'kind' => $job->kind, 'items' => $items,
                'copies' => max(1, (int) $job->copies), 'sections' => [], 'void' => $job->kind === 'cancel',
            ]];
            $picked = collect([$job->kot_printer_id])->filter()->values();
        } else {
            $raw = $request->query('printers', []);
            $picked = collect(is_array($raw) ? $raw : explode(',', (string) $raw))->filter()->map(fn ($v) => (int) $v);
            if ($request->integer('printer')) {
                $picked->push($request->integer('printer'));
            }
            $picked = $picked->unique()->values();
            $slips = $svc->plan($order, $kot, [
                'printers' => $picked->all(),
                'combined' => $request->boolean('combined'),
                'void' => $request->boolean('void'),
                'force' => $request->boolean('force'),
                'captain' => $picked->isEmpty() && ! $request->boolean('combined') && ! $request->boolean('void'),
            ]);
        }

        return view('pos.kot', compact('order', 'kot', 'slips', 'printers', 'all', 'picked'));
    }

    /** Reprint, or release an ask-mode KOT, to the printers the user ticked. */
    public function reprintKot(Request $request, PosOrder $order, int $kot)
    {
        $data = $request->validate([
            'printers' => 'nullable|array|max:12', 'printers.*' => 'integer',
            'combined' => 'nullable|boolean', 'force' => 'nullable|boolean', 'void' => 'nullable|boolean',
        ]);
        if (empty($data['printers']) && ! $request->boolean('combined')) {
            throw ValidationException::withMessages(['printers' => 'Select a printer, or choose the combined sheet.']);
        }
        $svc = app(KotPrintService::class);
        $jobs = $svc->fire($order, $kot, [
            'mode' => 'reprint',
            'printers' => $data['printers'] ?? [],
            'combined' => $request->boolean('combined') && empty($data['printers']),
            'force' => $request->boolean('force'),
            'void' => $request->boolean('void'),
            'captain' => false,
        ]);
        $summary = $svc->jobSummary($jobs);

        return back()->with($jobs->contains(fn ($j) => $j->status === 'failed') ? 'warning' : 'success', $summary ? 'Print sent — '.$summary.'.' : 'Nothing to print for that choice.');
    }

    /** @return array{message: string, ask: ?int} */
    protected function fireKot(PosOrder $order, int $kot, array $opt = []): array
    {
        $svc = app(KotPrintService::class);
        $jobs = $svc->fire($order, $kot, $opt);
        $ask = $jobs->isEmpty() && ($opt['mode'] ?? 'auto') === 'auto' && empty($opt['combined'])
            && setting('pos_kot_print_mode', 'split') === 'ask' && $svc->forOutlet($order->outlet_id)->isNotEmpty();
        $summary = $svc->jobSummary($jobs);
        $message = $ask
            ? "KOT #{$kot} is on the kitchen display. Choose which printers to print."
            : ("KOT #{$kot} sent to kitchen".($summary ? " — {$summary}" : '').'.');

        return ['message' => $message, 'ask' => $ask ? 1 : null];
    }
}
