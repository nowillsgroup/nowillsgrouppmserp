<?php

namespace App\Http\Controllers;

use App\Models\LostFoundItem;
use App\Models\LostReport;
use App\Models\Room;
use App\Services\FrontDeskOpsService;
use App\Services\LostFoundService;
use Illuminate\Http\Request;
use RuntimeException;

/** v3.5 – lost & found: store, loss reports & matching, returns, disposal. */
class LostFoundController extends Controller
{
    public const TABS = ['stored' => 'In store', 'due' => 'Due for disposal', 'reports' => 'Loss reports', 'returned' => 'Returned', 'disposed' => 'Disposed'];

    protected function fail(RuntimeException $e)
    {
        return back()->withInput()->with('error', $e->getMessage());
    }

    protected function cats(): string
    {
        return implode(',', array_keys(LostFoundItem::CATEGORIES));
    }

    public function index(Request $request)
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'stored';
        $term = trim((string) $request->query('q'));
        $items = $reports = null;
        if ($tab === 'reports') {
            $reports = LostReport::with('foundItem', 'stay.room', 'guest')->when($request->query('status', 'open') !== 'all', fn ($q) => $q->where('status', $request->query('status', 'open')))
                ->when($term, fn ($q) => $q->where(fn ($w) => $w->where('report_no', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%")->orWhere('item', 'like', "%{$term}%")))
                ->orderByDesc('id')->paginate(40)->withQueryString();
        } else {
            $items = LostFoundItem::with('room', 'guest', 'report')
                ->when($tab === 'stored', fn ($q) => $q->where('status', 'stored'))
                ->when($tab === 'due', fn ($q) => $q->where('status', 'stored')->whereNotNull('retain_until')->whereDate('retain_until', '<=', bdate()->toDateString()))
                ->when(in_array($tab, ['returned', 'disposed']), fn ($q) => $q->where('status', $tab))
                ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
                ->when($request->boolean('valuable'), fn ($q) => $q->where('valuable', true))
                ->when($term, fn ($q) => $q->where(fn ($w) => $w->where('item_no', 'like', "%{$term}%")->orWhere('item_name', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%")->orWhere('returned_to', 'like', "%{$term}%")->orWhereHas('room', fn ($r) => $r->where('room_no', $term))))
                ->orderBy($tab === 'due' ? 'retain_until' : 'id', $tab === 'due' ? 'asc' : 'desc')->paginate(40)->withQueryString();
        }

        return view('lostfound.index', ['tab' => $tab, 'items' => $items, 'reports' => $reports, 'counts' => FrontDeskOpsService::counts(), 'cfg' => FrontDeskOpsService::cfg(),
            'rooms' => Room::where('is_active', true)->orderBy('room_no')->pluck('room_no', 'id')->all(), 'stays' => FrontDeskOpsService::stayOptions(false)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'item_name' => 'required|string|max:150', 'category' => 'required|in:'.$this->cats(), 'description' => 'nullable|string|max:1000', 'valuable' => 'nullable|boolean',
            'est_value' => 'nullable|numeric|min:0|max:99999999', 'room_id' => 'nullable|integer|owned:rooms', 'location' => 'nullable|string|max:120',
            'storage_location' => 'nullable|string|max:60', 'found_date' => 'required|date', 'found_by' => 'nullable|string|max:120',
        ]);
        try {
            $item = LostFoundService::log($data);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('lostfound.show', $item)->with('success', "Logged as {$item->item_no} – keep until ".$item->retain_until->format('d M Y').'. Print the tag and store it in: '.$item->storage_location.'.');
    }

    public function show(LostFoundItem $item)
    {
        $item->load('room', 'stay.room', 'stay.reservation', 'guest', 'finder', 'releaser', 'disposer', 'witness', 'report');
        $openReports = $item->isStored() ? LostReport::where('status', 'open')->orderByDesc('id')->get()
            ->filter(fn ($r) => LostFoundService::candidates($r, 20)->contains('id', $item->id))->values() : collect();

        return view('lostfound.show', ['i' => $item, 'openReports' => $openReports]);
    }

    public function update(Request $request, LostFoundItem $item)
    {
        $data = $request->validate(['item_name' => 'required|string|max:150', 'category' => 'required|in:'.$this->cats(), 'description' => 'nullable|string|max:1000',
            'valuable' => 'nullable|boolean', 'est_value' => 'nullable|numeric|min:0|max:99999999', 'storage_location' => 'nullable|string|max:60']);
        try {
            LostFoundService::update($item, $data);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', "{$item->item_no} updated.");
    }

    public function returnItem(Request $request, LostFoundItem $item)
    {
        $data = $request->validate(['returned_to' => 'required|string|max:150', 'claimant_phone' => 'nullable|string|max:40',
            'claimant_id_type' => 'nullable|in:'.implode(',', array_keys(LostFoundService::ID_TYPES)), 'claimant_id_no' => 'nullable|string|max:40',
            'return_method' => 'required|in:'.implode(',', array_keys(LostFoundItem::RETURN_METHODS)), 'courier' => 'nullable|string|max:60', 'tracking_no' => 'nullable|string|max:60',
            'courier_charge' => 'nullable|numeric|min:0|max:100000']);
        try {
            $item = LostFoundService::returnItem($item, $data);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('lostfound.show', $item)->with('success', "{$item->item_no} returned to {$item->returned_to}. Print the receipt for the claimant to sign.");
    }

    public function dispose(Request $request, LostFoundItem $item)
    {
        $data = $request->validate(['disposal_method' => 'required|in:'.implode(',', array_keys(LostFoundItem::DISPOSAL)), 'disposal_note' => 'nullable|string|max:190',
            'witness_username' => 'nullable|string|max:60', 'witness_password' => 'nullable|string|max:200']);
        try {
            LostFoundService::dispose($item, $data);
        } catch (RuntimeException $e) {
            return back()->withInput($request->except('witness_password'))->with('error', $e->getMessage());
        }

        return redirect()->route('lostfound.show', $item)->with('success', "{$item->item_no} disposed: ".LostFoundItem::DISPOSAL[$data['disposal_method']].'.');
    }

    public function tag(LostFoundItem $item)
    {
        return view('lostfound.tag', ['i' => $item->load('room', 'finder')]);
    }

    public function receipt(LostFoundItem $item)
    {
        abort_unless($item->status === 'returned', 404);

        return view('lostfound.receipt', ['i' => $item->load('room', 'releaser')]);
    }

    // ───────────────────────── loss reports ─────────────────────────

    public function report(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'phone' => 'nullable|string|max:40', 'email' => 'nullable|email|max:150', 'item' => 'required|string|max:150',
            'category' => 'required|in:'.$this->cats(), 'description' => 'nullable|string|max:1000', 'lost_date' => 'nullable|date|before_or_equal:today',
            'where_lost' => 'nullable|string|max:120', 'reservation_room_id' => 'nullable|integer|owned:reservation_rooms']);
        try {
            $r = LostFoundService::report($data);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }
        $n = LostFoundService::candidates($r)->count();

        return redirect()->route('lostfound.report.show', $r)->with('success', "Loss report {$r->report_no} recorded".($n ? " – {$n} possible match".($n > 1 ? 'es' : '').' in store.' : ' – no match in store yet.'));
    }

    public function showReport(LostReport $lreport)
    {
        $lreport->load('foundItem', 'stay.room', 'guest', 'creator');

        return view('lostfound.report', ['r' => $lreport, 'candidates' => $lreport->status === 'open' ? LostFoundService::candidates($lreport) : collect()]);
    }

    public function match(LostReport $lreport, LostFoundItem $item)
    {
        try {
            LostFoundService::match($lreport, $item);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('lostfound.show', $item)->with('success', "{$lreport->report_no} matched to {$item->item_no}. Verify the owner and return the item.");
    }

    public function closeReport(Request $request, LostReport $lreport)
    {
        $data = $request->validate(['close_note' => 'required|string|max:190']);
        try {
            LostFoundService::closeReport($lreport, $data['close_note']);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('lostfound.report.show', $lreport)->with('success', "{$lreport->report_no} closed.");
    }
}
