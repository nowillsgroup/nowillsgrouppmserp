<?php

namespace App\Http\Controllers;

use App\Models\FolioTransaction;
use App\Models\GuestRequest;
use App\Models\LaundryItem;
use App\Models\LaundryOrder;
use App\Models\PaymentMode;
use App\Models\ReservationRoom;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\LaundryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LaundryController extends Controller
{
    public const SETTINGS = ['laundry_express_pct', 'laundry_normal_hours', 'laundry_express_hours'];

    public function index(Request $request)
    {
        $tab = $request->input('tab', 'open');
        $q = trim((string) $request->input('q'));
        $list = LaundryOrder::with('room')
            ->when($tab === 'open', fn ($x) => $x->whereIn('status', LaundryOrder::OPEN))
            ->when($tab === 'overdue', fn ($x) => $x->whereIn('status', LaundryOrder::OPEN)->where('promised_at', '<', now()))
            ->when(in_array($tab, ['collected', 'processing', 'ready', 'delivered', 'cancelled']), fn ($x) => $x->where('status', $tab))
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w->where('order_no', 'like', "%{$q}%")->orWhere('guest_name', 'like', "%{$q}%")->orWhere('bag_no', 'like', "%{$q}%")
                ->orWhereHas('room', fn ($r) => $r->where('room_no', $q))))
            ->orderByRaw("FIELD(status, 'ready', 'processing', 'collected', 'delivered', 'cancelled')")->orderBy('promised_at')->orderByDesc('id')
            ->paginate(25)->withQueryString();
        $day = bdate()->toDateString();
        $posted = fn ($from) => (float) FolioTransaction::where('source_type', 'laundry')->where('is_void', false)->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $day)->sum('amount');
        $open = LaundryOrder::whereIn('status', LaundryOrder::OPEN)->selectRaw('status, COUNT(*) n, SUM(pieces) p')->groupBy('status')->get()->keyBy('status');

        return view('laundry.index', ['list' => $list, 'tab' => $tab, 'q' => $q, 'stats' => [
            'collected' => (int) ($open['collected']->n ?? 0), 'processing' => (int) ($open['processing']->n ?? 0), 'ready' => (int) ($open['ready']->n ?? 0),
            'pieces' => (int) $open->sum('p'), 'overdue' => LaundryOrder::whereIn('status', LaundryOrder::OPEN)->where('promised_at', '<', now())->count(),
            'today' => $posted($day), 'month' => $posted(bdate()->copy()->startOfMonth()->toDateString()),
            'requests' => self::waiting(GuestRequest::query())->count(),
        ]]);
    }

    /** Open portal laundry requests that don't have a (live) laundry order yet. */
    protected static function waiting($q)
    {
        return $q->where('category', 'laundry')->whereIn('status', GuestRequest::OPEN)
            ->whereNotIn('id', LaundryOrder::whereNotNull('guest_request_id')->where('status', '!=', 'cancelled')->select('guest_request_id'));
    }

    protected function stays()
    {
        return ReservationRoom::with('room', 'guest')->where('status', 'checked_in')->whereNotNull('room_id')->get()
            ->sortBy(fn ($s) => $s->room?->room_no)->values();
    }

    public function create(Request $request)
    {
        $req = $request->filled('request') ? self::waiting(GuestRequest::query())->find((int) $request->input('request')) : null;

        return view('laundry.create', [
            'stays' => $this->stays(), 'items' => LaundryService::priceList(), 'req' => $req,
            'requests' => self::waiting(GuestRequest::with('room'))->orderBy('id')->get(),
            'stayId' => (int) ($req?->reservation_room_id ?? $request->input('stay')), 'expressPct' => LaundryService::expressPct(),
            'hours' => ['normal' => LaundryService::hours('normal'), 'express' => LaundryService::hours('express')],
        ]);
    }

    /** Turn the grid (q[item][service] = qty, r[item] = remark) into service lines. */
    protected function gridLines(Request $request): array
    {
        $lines = [];
        foreach ((array) $request->input('q', []) as $itemId => $svcs) {
            foreach ((array) $svcs as $svc => $qty) {
                if ((int) $qty > 0) {
                    $lines[] = ['laundry_item_id' => (int) $itemId, 'service' => (string) $svc, 'qty' => (int) $qty, 'remark' => $request->input("r.$itemId")];
                }
            }
        }

        return $lines;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|in:guest,walk_in', 'reservation_room_id' => 'nullable|required_if:type,guest|integer|owned:reservation_rooms',
            'guest_name' => 'nullable|required_if:type,walk_in|string|max:150', 'phone' => 'nullable|string|max:30',
            'speed' => 'required|in:normal,express', 'bag_no' => 'nullable|string|max:30', 'bag_pieces' => 'nullable|integer|min:1|max:500',
            'notes' => 'nullable|string|max:500', 'guest_request_id' => 'nullable|integer|owned:guest_requests',
            'q' => 'nullable|array', 'q.*' => 'array', 'q.*.*' => 'nullable|integer|min:0|max:99', 'r' => 'nullable|array', 'r.*' => 'nullable|string|max:150',
        ]);
        $o = LaundryService::create($data + ['lines' => $this->gridLines($request)]);

        return redirect()->route('laundry.show', $o)->with('success', "Laundry {$o->order_no} created".($o->pieces ? " – {$o->pieces} pcs, ".money($o->total) : ' – add the items when the bag is sorted').'.');
    }

    public function show(LaundryOrder $lorder)
    {
        $lorder->load('lines', 'room', 'stay.guest', 'folio', 'folioTransaction', 'paymentMode', 'guestRequest', 'creator', 'deliverer');
        $current = [];
        $remarks = [];
        foreach ($lorder->lines as $l) {
            $current[$l->laundry_item_id][$l->service] = $l->qty;
            if ($l->remark) {
                $remarks[$l->laundry_item_id] = $l->remark;
            }
        }

        return view('laundry.show', ['o' => $lorder, 'items' => LaundryService::priceList(), 'current' => $current, 'remarks' => $remarks,
            'modes' => PaymentMode::where('is_active', true)->whereNotIn('type', ['city_ledger', 'online', 'gift_voucher'])->orderBy('id')->get(), 'expressPct' => LaundryService::expressPct()]);
    }

    public function docket(LaundryOrder $lorder)
    {
        return view('laundry.docket', ['o' => $lorder->load('lines', 'room'), 'hotel' => hotel()]);
    }

    public function items(Request $request, LaundryOrder $lorder)
    {
        $request->validate(['q' => 'nullable|array', 'q.*' => 'array', 'q.*.*' => 'nullable|integer|min:0|max:99', 'r' => 'nullable|array', 'r.*' => 'nullable|string|max:150',
            'speed' => 'nullable|in:normal,express']);
        try {
            $o = LaundryService::setLines($lorder, $this->gridLines($request), true, $request->input('speed'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Items saved – {$o->pieces} pcs, ".money($o->total).' before tax.');
    }

    public function ready(LaundryOrder $lorder)
    {
        try {
            $ready = LaundryService::markReady($lorder);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Laundry {$ready->order_no} is ready for delivery.");
    }

    public function deliver(Request $request, LaundryOrder $lorder)
    {
        $data = $request->validate(['payment_mode_id' => 'nullable|integer|owned:payment_modes', 'reference' => 'nullable|string|max:60', 'cheque_bank_name' => 'nullable|string|max:100', 'cheque_number' => 'nullable|string|max:20', 'cheque_bank_branch' => 'nullable|string|max:100']);
        try {
            $o = LaundryService::deliver($lorder, $data['payment_mode_id'] ?? null, $data['reference'] ?? null, [
                'bank_name' => $data['cheque_bank_name'] ?? null, 'number' => $data['cheque_number'] ?? null, 'bank_branch' => $data['cheque_bank_branch'] ?? null,
            ]);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $t = $o->folioTransaction;

        return back()->with('success', "Delivered. ".money((float) $t->amount + (float) $t->tax_amount).($o->type === 'guest' ? " charged to folio {$o->folio->folio_no}." : " received – invoice {$o->folio->invoice_no}."));
    }

    public function cancel(Request $request, LaundryOrder $lorder)
    {
        $data = $request->validate(['reason' => 'required|string|max:190']);
        try {
            $cancelled = LaundryService::cancel($lorder, $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Laundry {$cancelled->order_no} cancelled.");
    }

    // ------------------------------------------------------------ price list & settings

    public function prices()
    {
        return view('laundry.prices', ['items' => LaundryItem::orderBy('category')->orderBy('sort_order')->orderBy('name')->get()->groupBy('category'),
            'v' => Setting::whereIn('key', self::SETTINGS)->pluck('value', 'key')]);
    }

    public function savePrice(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer|owned:laundry_items', 'name' => ['required', 'string', 'max:80', Rule::unique('laundry_items')->where('hotel_id', hotel()->id)->ignore($request->input('id'))],
            'category' => ['required', Rule::in(array_keys(LaundryItem::CATEGORIES))], 'wash_rate' => 'nullable|numeric|min:0|max:100000',
            'dryclean_rate' => 'nullable|numeric|min:0|max:100000', 'press_rate' => 'nullable|numeric|min:0|max:100000', 'sort_order' => 'nullable|integer|min:0|max:999',
        ]);
        if (($data['wash_rate'] ?? null) === null && ($data['dryclean_rate'] ?? null) === null && ($data['press_rate'] ?? null) === null) {
            return back()->withInput()->with('error', 'Give a price for at least one service.');
        }
        $item = ! empty($data['id']) ? LaundryItem::findOrFail($data['id']) : new LaundryItem;
        $item->fill(collect($data)->except('id')->all() + ['sort_order' => $data['sort_order'] ?? 0])->save();
        ActivityLogger::log('laundry', 'price', ($data['id'] ?? null ? 'Updated' : 'Added')." laundry price: {$item->name}", $item);

        return back()->with('success', "Price list updated – {$item->name}.");
    }

    public function togglePrice(LaundryItem $litem)
    {
        $litem->update(['is_active' => ! $litem->is_active]);

        return back()->with('success', $litem->name.($litem->is_active ? ' is offered again.' : ' removed from the price list.'));
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate(['laundry_express_pct' => 'required|numeric|min:0|max:300', 'laundry_normal_hours' => 'required|integer|min:1|max:168',
            'laundry_express_hours' => 'required|integer|min:1|max:48|lte:laundry_normal_hours']);
        foreach ($data as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => (string) $v]);
        }
        ActivityLogger::log('settings', 'laundry', 'Updated laundry settings');

        return back()->with('success', 'Laundry settings saved.');
    }
}
