<?php

namespace App\Http\Controllers;

use App\Models\KotPrintJob;
use App\Models\KotPrinter;
use App\Models\MenuCategory;
use App\Models\Outlet;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

/**
 * Kitchen display system: live tickets per KOT, filtered by outlet and station (menu category's
 * "kitchen station"). Cooks bump items new → preparing → ready; stewards mark them served from the POS.
 */
class KitchenController extends Controller
{
    public const FLOW = ['new' => 'preparing', 'preparing' => 'ready', 'ready' => 'served'];

    public function index(Request $request)
    {
        return view('kitchen.index', [
            'outlets' => Outlet::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'stations' => MenuCategory::whereNotNull('kitchen_station')->distinct()->orderBy('kitchen_station')->pluck('kitchen_station')->prepend('Kitchen')->unique()->values(),
        ]);
    }

    /** JSON feed polled by the board every few seconds. */
    public function feed(Request $request)
    {
        $station = $request->query('station');
        $items = PosOrderItem::with('order.table', 'order.stay.room', 'order.outlet', 'menuItem.category', 'kotPrinter')
            ->whereNotNull('kot_no')->where('is_pending', false)
            ->where(fn ($q) => $q->whereIn('kitchen_status', ['held', 'new', 'preparing', 'ready'])
                ->orWhere(fn ($w) => $w->where('kitchen_status', 'served')->where('served_at', '>=', now()->subMinutes(10))))
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['open', 'billed'])
                ->when($request->integer('outlet'), fn ($w, $o) => $w->where('outlet_id', $o)))
            ->orderBy('id')->get()
            // cancelled items stay on the ticket, struck through, so the cook knows to stop
            ->filter(fn ($i) => ! $station || $this->stationOf($i) === $station);

        $stationPrinter = $station ? KotPrinter::where('role', 'station')->where('station', $station)->value('id') : null;
        $tickets = $items->groupBy(fn ($i) => $i->pos_order_id.'-'.$i->kot_no)->map(function ($lines) use ($stationPrinter) {
            $o = $lines->first()->order;
            $live = $lines->where('is_cancelled', false);
            $cooking = $live->reject(fn ($l) => $l->kitchen_status === 'held');
            $status = $live->isEmpty() ? 'cancelled' : ($cooking->isEmpty() ? 'held'
                : ($cooking->every(fn ($l) => $l->kitchen_status === 'served') ? 'served'
                : ($cooking->every(fn ($l) => in_array($l->kitchen_status, ['ready', 'served'])) ? 'ready'
                : ($cooking->contains(fn ($l) => $l->kitchen_status !== 'new') ? 'preparing' : 'new'))));
            $since = $lines->min('created_at');

            return [
                'key' => $o->id.'-'.$lines->first()->kot_no, 'order_id' => $o->id, 'kot' => $lines->first()->kot_no, 'order_no' => $o->order_no,
                'where' => $o->table ? 'Table '.$o->table->table_no : ($o->stay ? 'Room '.$o->stay->room?->room_no : ucwords(str_replace('_', ' ', $o->order_type))),
                'outlet' => $o->outlet?->name, 'steward' => $o->steward, 'covers' => $o->covers, 'status' => $status,
                'allergy' => $o->allergens ? \App\Services\AllergenService::names($o->allergens).($o->allergy_note ? ' – '.$o->allergy_note : '') : null, // v5.0
                'minutes' => (int) $since->diffInMinutes(now()), 'since' => $since->format('H:i'),
                'print' => route('pos.printkot', [$o->id, $lines->first()->kot_no]).($stationPrinter ? '?printer='.$stationPrinter : ''),
                'items' => $lines->map(fn ($l) => ['id' => $l->id, 'name' => $l->item_name, 'qty' => (float) $l->qty, 'notes' => $l->notes,
                    'status' => $l->kitchen_status, 'cancelled' => $l->is_cancelled, 'comp' => (bool) $l->is_complimentary, 'open' => (bool) $l->is_open, 'hold' => $l->kitchen_status === 'held', 'station' => $this->stationOf($l),
                    'printer' => $l->kotPrinter?->name, 'flag' => $l->allergy_flag, 'modifiers' => collect($l->modifiers ?? [])->pluck('name')->values()])->values(),
            ];
        })->reject(fn ($t) => $t['status'] === 'cancelled' && $t['minutes'] > 15)
            ->sortBy(fn ($t) => [$t['status'] === 'served' ? 1 : 0, -$t['minutes']])->values();

        return response()->json(['tickets' => $tickets, 'counts' => $tickets->countBy('status'), 'at' => now()->format('H:i:s')]);
    }

    protected function stationOf(PosOrderItem $i): string
    {
        return $i->kitchen_station ?: ($i->menuItem?->category?->kitchen_station ?: 'Kitchen');
    }

    /** Advance one item, or every item of a ticket (optionally for one station). */
    public function bump(Request $request)
    {
        $data = $request->validate(['item_id' => 'nullable|integer', 'order_id' => 'nullable|required_without:item_id|integer', 'kot' => 'nullable|required_with:order_id|integer',
            'station' => 'nullable|string|max:40', 'undo' => 'nullable|boolean']);
        $q = PosOrderItem::with('menuItem.category')->where('is_cancelled', false)->where('is_pending', false)
            ->whereHas('order', fn ($w) => $w->whereIn('status', ['open', 'billed']));
        $items = ! empty($data['item_id']) ? $q->whereKey($data['item_id'])->get()
            : $q->where('pos_order_id', $data['order_id'])->where('kot_no', $data['kot'])->get()
                ->filter(fn ($i) => empty($data['station']) || $this->stationOf($i) === $data['station']);
        $heldOnly = $items->isNotEmpty() && $items->every(fn ($i) => $i->kitchen_status === 'held');
        $items = $items->reject(fn ($i) => $i->kitchen_status === 'held');
        abort_if($heldOnly, 422, 'This dish is on hold. The captain has to fire it before the kitchen starts.');
        abort_if($items->isEmpty(), 404);
        $undo = $request->boolean('undo');
        $back = array_flip(self::FLOW);
        // bumping a ticket moves every item to the next status of its slowest item
        $target = null;
        if (empty($data['item_id'])) {
            $slowest = collect(['new', 'preparing', 'ready', 'served'])->first(fn ($s) => $items->contains('kitchen_status', $s));
            $target = $undo ? ($back[$slowest] ?? null) : (self::FLOW[$slowest] ?? null);
        }
        foreach ($items as $i) {
            $next = $target ?? ($undo ? ($back[$i->kitchen_status] ?? null) : (self::FLOW[$i->kitchen_status] ?? null));
            if (! $next || $next === $i->kitchen_status) {
                continue;
            }
            $i->forceFill(['kitchen_status' => $next, 'ready_at' => $next === 'ready' ? now() : ($next === 'new' || $next === 'preparing' ? null : $i->ready_at),
                'served_at' => $next === 'served' ? now() : null])->save();
        }
        if (! empty($data['order_id'])) {
            ActivityLogger::log('pos', 'kds_bump', "KOT #{$data['kot']} → ".($target ?? 'next'), PosOrder::find($data['order_id']));
        }

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    /** A PC left open next to one kitchen printer. It claims queued slips and prints them. */
    public function station(Request $request)
    {
        $printers = KotPrinter::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();

        return view('kitchen.station', [
            'printers' => $printers,
            'printer' => $printers->firstWhere('id', $request->integer('printer')) ?? $printers->first(),
        ]);
    }

    public function stationJobs(Request $request)
    {
        KotPrintJob::where('status', 'printing')->where('updated_at', '<', now()->subMinutes(2))->update(['status' => 'queued']);
        $id = $request->integer('printer');
        $waiting = $id ? KotPrintJob::with('order.table', 'order.stay.room')->where('kot_printer_id', $id)->where('status', 'queued')
            ->where('created_at', '>=', now()->subHours(12))->orderBy('id')->limit(8)->get() : collect();
        $recent = $id ? KotPrintJob::with('order.table', 'order.stay.room')->where('kot_printer_id', $id)->whereIn('status', ['printed', 'sent', 'failed'])
            ->latest('id')->limit(8)->get() : collect();
        $row = fn ($j) => [
            'id' => $j->id, 'kot' => $j->kot_no, 'status' => $j->status, 'error' => $j->error, 'items' => count($j->item_ids ?? []),
            'where' => $j->order ? (new \App\Services\KotPrintService)->whereText($j->order) : '',
        ];

        return response()->json(['waiting' => $waiting->map($row)->values(), 'recent' => $recent->map($row)->values(), 'at' => now()->format('H:i:s')]);
    }

    public function stationClaim(Request $request)
    {
        $job = KotPrintJob::whereKey($request->integer('job'))->where('status', 'queued')->first();
        if (! $job) {
            return response()->json(['ok' => false], 409);
        }
        $job->update(['status' => 'printing']);
        $url = route('pos.printkot', [$job->pos_order_id, $job->kot_no]).'?job='.$job->id.'&autoprint=1';

        return response()->json(['ok' => true, 'url' => $url]);
    }

    public function stationAck(Request $request)
    {
        $job = KotPrintJob::whereKey($request->integer('job'))->first();
        if ($job && in_array($job->status, ['printing', 'queued'], true)) {
            $job->forceFill(['status' => 'printed', 'printed_at' => now(), 'error' => null])->save();
        }

        return response()->json(['ok' => true]);
    }
}
