<?php

namespace App\Http\Controllers;

use App\Models\AccountHead;
use App\Models\MeterReading;
use App\Models\Supplier;
use App\Models\UtilityBill;
use App\Models\UtilityMeter;
use App\Services\ApService;
use App\Services\UtilityService as Us;
use Carbon\Carbon;
use Illuminate\Http\Request;
use RuntimeException;

/** v3.9 – utilities & energy: daily meter readings, meters & sub-meters, utility bills, KPIs and carbon footprint. */
class UtilityController extends Controller
{
    public const TABS = ['overview' => 'Overview', 'readings' => 'Daily readings', 'meters' => 'Meters', 'bills' => 'Utility bills', 'settings' => 'Settings'];

    public const TAB_PERM = ['overview' => 'utilities.view', 'readings' => 'utilities.view', 'meters' => 'utilities.view', 'bills' => 'utilities.view', 'settings' => 'utilities.manage'];

    protected function fail(RuntimeException $e)
    {
        return back()->withInput()->with('error', $e->getMessage());
    }

    protected function range(Request $r, int $days = 30): array
    {
        $to = $r->query('to') && strtotime($r->query('to')) ? Carbon::parse($r->query('to'))->toDateString() : bdate()->toDateString();
        $from = $r->query('from') && strtotime($r->query('from')) ? Carbon::parse($r->query('from'))->toDateString() : Carbon::parse($to)->subDays($days - 1)->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 400) {
            $from = Carbon::parse($to)->subDays(400)->toDateString();
        }

        return [$from, $to];
    }

    public function index(Request $request)
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'overview';
        if (! can_do(self::TAB_PERM[$tab])) {
            $tab = 'overview';
        }
        $v = ['tab' => $tab, 'cfg' => Us::cfg(), 'snap' => Us::snapshot()];
        if ($tab === 'overview') {
            [$from, $to] = $this->range($request);
            $v += ['from' => $from, 'to' => $to, 'kpi' => Us::kpis($from, $to), 'by' => Us::byUtility($from, $to), 'carbon' => Us::carbon($from, $to),
                'anomalies' => Us::openAnomalies(14), 'missingY' => Us::missing(bdate()->copy()->subDay()->toDateString(), true)];
        } elseif ($tab === 'readings') {
            $date = $request->query('date') && strtotime($request->query('date')) ? min(Carbon::parse($request->query('date'))->toDateString(), bdate()->toDateString()) : bdate()->toDateString();
            $meters = UtilityMeter::with('parent')->where('is_active', true)->orderByRaw("FIELD(utility,'electricity','dg','solar','water','lpg','diesel','png')")->orderBy('parent_id')->orderBy('code')->get();
            $today = MeterReading::with('user')->where('reading_date', $date)->get()->keyBy('utility_meter_id');
            $rows = $meters->map(fn ($m) => ['meter' => $m, 'prev' => Us::previous($m, $date), 'cur' => $today->get($m->id), 'expected' => Us::expected($m, $date)]);
            $v += ['date' => $date, 'rows' => $rows];
        } elseif ($tab === 'meters') {
            $v += ['meters' => UtilityMeter::with('parent', 'lastReading')->withCount('readings')->orderBy('utility')->orderBy('code')->get()];
        } elseif ($tab === 'bills') {
            $v += ['bills' => UtilityBill::with('meter', 'supplier', 'supplierInvoice')->orderByDesc('bill_date')->orderByDesc('id')->paginate(30),
                'suppliers' => Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
                'heads' => ApService::chargeableHeads()->mapWithKeys(fn ($h) => [$h->id => trim($h->code.' '.$h->name)])];
        }
        $v['allMeters'] = UtilityMeter::orderBy('code')->get();

        return view('utilities.index', $v);
    }

    public function meter(Request $request, UtilityMeter $umeter)
    {
        [$from, $to] = $this->range($request, 60);
        $readings = MeterReading::with('user')->where('utility_meter_id', $umeter->id)->whereBetween('reading_date', [$from, $to])->orderByDesc('reading_date')->get();
        $sum = ['cons' => $readings->sum('consumption'), 'cost' => $readings->sum('cost'), 'days' => $readings->sum('days')];

        return view('utilities.meter', ['m' => $umeter->load('parent', 'children', 'lastReading'), 'readings' => $readings, 'from' => $from, 'to' => $to, 'sum' => $sum,
            'bills' => $umeter->bills()->orderByDesc('bill_date')->limit(12)->get(), 'allMeters' => UtilityMeter::orderBy('code')->get()]);
    }

    public function saveReadings(Request $request)
    {
        $data = $request->validate(['date' => 'required|date', 'r' => 'required|array', 'r.*.reading' => 'nullable|string|max:20', 'r.*.estimated' => 'nullable|boolean',
            'r.*.reset' => 'nullable|boolean', 'r.*.note' => 'nullable|string|max:190']);
        $date = Carbon::parse($data['date'])->toDateString();
        try {
            $res = Us::recordBatch($date, $data['r']);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }
        $back = redirect()->route('utilities.index', ['tab' => 'readings', 'date' => $date]);
        if ($res['errors']) {
            $back = $back->withInput()->with('error', implode(' ', $res['errors']));
        }
        if ($res['anomalies']) {
            $back = $back->with('warning', 'Check: '.implode(' · ', $res['anomalies']));
        }

        if (! $res['saved']) {
            return $res['errors'] ? $back : $back->with('warning', 'Nothing to save – no reading was entered or changed.');
        }

        return $back->with('success', $res['saved'].' reading(s) saved for '.Carbon::parse($date)->format('d M Y').'.');
    }

    /** correct / add a single reading from the meter page */
    public function saveReading(Request $request, UtilityMeter $umeter)
    {
        $d = $request->validate(['date' => 'required|date', 'reading' => 'required|numeric|min:0', 'estimated' => 'nullable|boolean', 'reset' => 'nullable|boolean', 'note' => 'nullable|string|max:190']);
        try {
            $r = Us::record($umeter, Carbon::parse($d['date'])->toDateString(), (float) $d['reading'], $d);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        $back = back()->with('success', 'Reading saved – consumption '.number_format($r->consumption, 2).' '.$umeter->unitShort().'.');

        return $r->anomaly ? $back->with('warning', MeterReading::ANOMALIES[$r->anomaly][0].': '.$r->anomaly_note) : $back;
    }

    public function deleteReading(MeterReading $mreading)
    {
        try {
            Us::deleteReading($mreading);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', 'Reading deleted and the next reading recalculated.');
    }

    public function acknowledge(Request $request, MeterReading $mreading)
    {
        $d = $request->validate(['note' => 'nullable|string|max:190']);
        abort_unless($mreading->anomaly, 404);
        Us::acknowledge($mreading, $d['note'] ?? null);

        return back()->with('success', 'Anomaly acknowledged.');
    }

    public function saveMeter(Request $request, ?UtilityMeter $umeter = null)
    {
        $d = $request->validate([
            'code' => 'required|string|max:20|regex:/^[A-Za-z0-9._-]+$/', 'name' => 'required|string|max:80', 'utility' => 'required|in:'.implode(',', array_keys(UtilityMeter::UTILITIES)),
            'unit' => 'nullable|in:'.implode(',', array_keys(UtilityMeter::UNITS)), 'meter_no' => 'nullable|string|max:40', 'area' => 'nullable|string|max:30',
            'parent_id' => 'nullable|integer|owned:utility_meters', 'multiplier' => 'nullable|numeric|min:0.001|max:100000', 'digits' => 'nullable|integer|min:3|max:9',
            'rate' => 'nullable|numeric|min:0|max:100000', 'is_main' => 'nullable|boolean', 'alert_pct' => 'nullable|integer|min:0|max:500', 'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:500', 'opening' => 'nullable|numeric|min:0',
        ]);
        $d['is_active'] = $request->boolean('is_active');
        try {
            $new = ! $umeter?->exists;
            $m = Us::saveMeter($d, $umeter?->exists ? $umeter : null);
            if ($new && isset($d['opening']) && $d['opening'] !== null) {
                Us::record($m, bdate()->toDateString(), (float) $d['opening'], ['note' => 'Opening reading']);
            }
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('utilities.index', ['tab' => 'meters'])->with('success', "Meter {$m->code} saved.");
    }

    public function saveBill(Request $request, ?UtilityBill $ubill = null)
    {
        $d = $request->validate([
            'utility_meter_id' => 'required|integer|owned:utility_meters', 'bill_no' => 'required|string|max:40', 'bill_date' => 'required|date',
            'period_from' => 'required|date', 'period_to' => 'required|date', 'units_billed' => 'nullable|numeric|min:0', 'energy_charges' => 'nullable|numeric|min:0',
            'fixed_charges' => 'nullable|numeric|min:0', 'taxes' => 'nullable|numeric|min:0', 'due_date' => 'nullable|date', 'supplier_id' => 'nullable|integer|owned:suppliers',
            'notes' => 'nullable|string|max:500',
        ]);
        try {
            $b = Us::saveBill($d, $ubill?->exists ? $ubill : null);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }
        $var = $b->variancePct();

        return redirect()->route('utilities.index', ['tab' => 'bills'])->with('success', "Bill {$b->bill_no} saved: ".number_format($b->units_billed, 0).' billed vs '
            .number_format($b->metered_units, 0).' metered'.($var !== null ? ' ('.($var >= 0 ? '+' : '').$var.'%)' : '').'.');
    }

    public function postBill(Request $request, UtilityBill $ubill)
    {
        $d = $request->validate(['supplier_id' => 'required|integer|owned:suppliers', 'account_head_id' => 'nullable|integer|owned:account_heads', 'budget_reason' => 'nullable|string|max:300']);
        try {
            Us::postBill($ubill, (int) $d['supplier_id'], ! empty($d['account_head_id']) ? (int) $d['account_head_id'] : null, $d['budget_reason'] ?? null);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', "Bill {$ubill->bill_no} booked to accounts payable as {$ubill->fresh()->supplierInvoice?->doc_no}.");
    }

    public function adoptRate(UtilityBill $ubill)
    {
        try {
            $rate = Us::adoptRate($ubill);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', "Tariff of {$ubill->meter->code} updated to {$rate} per {$ubill->meter->unitShort()}.");
    }

    public function saveSettings(Request $request)
    {
        $d = $request->validate(['alert_pct' => 'required|integer|min:5|max:500', 'require_readings' => 'nullable|boolean', 'ef' => 'required|array',
            'ef.*' => 'nullable|numeric|min:0|max:100']);
        Us::saveSettings($d);

        return redirect()->route('utilities.index', ['tab' => 'settings'])->with('success', 'Utility & energy settings saved.');
    }
}
