<?php

namespace App\Http\Controllers;

use App\Models\CallRecord;
use App\Models\CallTariff;
use App\Models\PhoneExtension;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Services\CallAccountingService as Calls;
use App\Services\FrontDeskOpsService;
use App\Services\InterfaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/** v3.7 – PABX call accounting: call log, review queue, SMDR import, tariffs, extensions, class of service. */
class CallController extends Controller
{
    public const TABS = ['log' => 'Call log', 'review' => 'Review queue', 'tariffs' => 'Tariffs', 'extensions' => 'Extensions'];

    protected function fail(RuntimeException $e)
    {
        return back()->withInput()->with('error', $e->getMessage());
    }

    public function index(Request $request)
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'log';
        $from = $request->date('from')?->toDateString() ?? bdate()->copy()->subDays(2)->toDateString();
        $to = $request->date('to')?->toDateString() ?? bdate()->toDateString();
        $calls = null;
        if (in_array($tab, ['log', 'review'], true)) {
            $calls = CallRecord::with('room', 'stay.guest', 'stay.room', 'phoneExtension', 'handler')
                ->when($tab === 'review', fn ($q) => $q->where('status', 'pending'), fn ($q) => $q->whereBetween('started_at', [$from.' 00:00:00', $to.' 23:59:59']))
                ->when($tab === 'log' && $request->query('status'), fn ($q, $s) => $q->where('status', $request->query('status')))
                ->when($request->query('class'), fn ($q, $c) => $q->where('call_class', $c))
                ->when(trim((string) $request->query('q')), fn ($q, $t) => $q->where(fn ($w) => $w->where('extension', $t)->orWhere('dialled', 'like', "%{$t}%")->orWhereHas('room', fn ($r) => $r->where('room_no', $t))))
                ->orderBy('started_at', $tab === 'review' ? 'asc' : 'desc')->paginate(50)->withQueryString();
        }
        $sum = CallRecord::whereBetween('started_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->selectRaw("count(*) n, sum(duration_secs) secs, sum(case when status='posted' then charge else 0 end) charged, sum(cost) cost, sum(case when status='admin' then cost else 0 end) admin_cost")->first();

        return view('calls.index', [
            'tab' => $tab, 'calls' => $calls, 'from' => $from, 'to' => $to, 'sum' => $sum, 'cfg' => InterfaceService::cfg(), 'counts' => InterfaceService::counts(),
            'tariffs' => $tab === 'tariffs' ? CallTariff::orderByRaw('length(prefix) desc')->orderBy('prefix')->get() : collect(),
            'extensions' => $tab === 'extensions' ? PhoneExtension::with('room')->withCount('calls')->orderBy('type')->orderByRaw('cast(extension as unsigned)')->get() : collect(),
            'rooms' => Room::where('is_active', true)->orderBy('room_no')->pluck('room_no', 'id')->all(),
            'stays' => FrontDeskOpsService::stayOptions(false),
        ]);
    }

    /** JSON rate calculator (“what would this call cost?”) */
    public function rate(Request $request)
    {
        $data = $request->validate(['number' => 'required|string|max:30', 'secs' => 'required|integer|min:0|max:86400']);
        $r = Calls::rate($data['number'], (int) $data['secs']);

        return response()->json(['number' => $r['number'], 'class' => $r['class'], 'tariff' => $r['tariff']?->name, 'pulses' => $r['pulses'],
            'cost' => $r['cost'], 'charge' => $r['charge'], 'free' => $r['free'], 'error' => $r['error'] ?? null]);
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'nullable|file|max:4096|mimes:csv,txt,log,smdr,dat', 'text' => 'nullable|string|max:500000']);
        $text = $request->file('file') ? (string) file_get_contents($request->file('file')->getRealPath()) : (string) $request->input('text');
        if (trim($text) === '') {
            return back()->with('error', 'Upload an SMDR / CSV file or paste the call records.');
        }
        $s = Calls::import($text);
        $msg = "{$s['imported']} call(s) imported: {$s['posted']} posted to folios (".money($s['charge'])."), {$s['pending']} for review, {$s['free']} free, {$s['admin']} admin"
            .($s['duplicates'] ? ", {$s['duplicates']} duplicate(s) skipped" : '').'.';
        $r = redirect()->route('calls.index', ['tab' => $s['pending'] ? 'review' : 'log'])->with($s['imported'] ? 'success' : 'warning', $msg);

        return $s['errors'] ? $r->with('import_errors', array_slice($s['errors'], 0, 30)) : $r;
    }

    public function store(Request $request)
    {
        $data = $request->validate(['ext' => 'required|string|max:10', 'number' => 'required|string|max:30', 'started_at' => 'required|date|before_or_equal:now',
            'duration' => 'required|string|max:10', 'trunk' => 'nullable|string|max:10']);
        $secs = Calls::parseDuration($data['duration']);
        if ($secs === null) {
            return back()->withInput()->withErrors(['duration' => 'Duration as seconds, m:ss or h:mm:ss.']);
        }
        try {
            $call = Calls::record(['ext' => $data['ext'], 'number' => $data['number'], 'started_at' => Carbon::parse($data['started_at']), 'duration' => $secs, 'trunk' => $data['trunk'] ?? null], 'manual');
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }
        if (! $call) {
            return back()->with('warning', 'That call is already recorded.');
        }

        return back()->with('success', "Call to {$call->dialled} recorded – ".$call->statusLabel().($call->status === 'posted' ? ' ('.money($call->charge).' on room '.$call->stay?->room?->room_no.')' : ($call->note ? ': '.$call->note : '')).'.');
    }

    public function post(Request $request, CallRecord $call)
    {
        $data = $request->validate(['stay_id' => 'nullable|integer|owned:reservation_rooms']);
        try {
            $call = Calls::post($call, ! empty($data['stay_id']) ? ReservationRoom::find($data['stay_id']) : null);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', 'Call posted: '.money($call->charge).' to room '.$call->stay?->room?->room_no.'.');
    }

    public function waive(Request $request, CallRecord $call)
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:200']);
        try {
            Calls::waive($call, $data['reason']);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', 'Call waived.');
    }

    public function reprocess()
    {
        $r = Calls::reprocess();

        return back()->with('success', "Review queue re-run: {$r['posted']} call(s) posted, {$r['still']} still need attention.");
    }

    public function saveExtension(Request $request, ?PhoneExtension $ext = null)
    {
        $data = $request->validate(['extension' => 'required|string|max:10', 'type' => 'required|in:'.implode(',', array_keys(PhoneExtension::TYPES)),
            'room_id' => 'nullable|integer|owned:rooms', 'department' => 'nullable|string|max:40', 'label' => 'nullable|string|max:60', 'is_active' => 'nullable|boolean']);
        $data['is_active'] = $request->boolean('is_active', ! $ext);
        try {
            $x = Calls::saveExtension($data, $ext);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('calls.index', ['tab' => 'extensions'])->with('success', "Extension {$x->extension} saved.");
    }

    public function syncExtensions()
    {
        $n = Calls::syncRoomExtensions();

        return redirect()->route('calls.index', ['tab' => 'extensions'])->with('success', $n ? "{$n} room extension(s) created." : 'Every room already has an extension.');
    }

    public function saveTariff(Request $request, ?CallTariff $tariff = null)
    {
        $data = $request->validate(['name' => 'required|string|max:60', 'prefix' => 'nullable|string|max:15', 'call_class' => 'required|in:'.implode(',', array_keys(CallTariff::CLASSES)),
            'pulse_secs' => 'required|integer|min:1|max:600', 'cost_per_pulse' => 'required|numeric|min:0|max:10000', 'markup_pct' => 'required|numeric|min:0|max:1000',
            'min_charge' => 'nullable|numeric|min:0|max:10000', 'free_secs' => 'nullable|integer|min:0|max:600', 'is_active' => 'nullable|boolean']);
        $data['prefix'] = (string) ($data['prefix'] ?? '');
        $data['is_active'] = $request->boolean('is_active', ! $tariff);
        try {
            $t = Calls::saveTariff($data, $tariff);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('calls.index', ['tab' => 'tariffs'])->with('success', "Tariff {$t->name} saved – guest price ".money($t->pricePerMinute()).' per minute.');
    }

    public function cos(Request $request, ReservationRoom $stay)
    {
        $data = $request->validate(['cos' => 'required|in:'.implode(',', array_keys(InterfaceService::COS))]);
        try {
            Calls::setCos($stay, $data['cos']);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', 'Phone for room '.$stay->room?->room_no.': '.InterfaceService::COS[$data['cos']].'.');
    }
}
