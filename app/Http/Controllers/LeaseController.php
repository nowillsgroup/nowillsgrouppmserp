<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Lease;
use App\Models\LeaseCharge;
use App\Models\LeaseInspection;
use App\Models\LeaseMeterReading;
use App\Models\LeasePayment;
use App\Models\LeaseUnit;
use App\Models\MaintenanceRequest;
use App\Models\PaymentMode;
use App\Models\Room;
use App\Models\Setting;
use App\Services\LeaseBillingService as LB;
use App\Services\LeaseService as LS;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use RuntimeException;

/** v5.2 Serviced apartments & long-stay leases: dashboard, units, the lease life cycle, meters and inspections. */
class LeaseController extends Controller
{
    protected function attempt(callable $fn, string|callable $ok)
    {
        try {
            $r = $fn();

            return back()->with('success', is_callable($ok) ? $ok($r) : $ok);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function index()
    {
        $today = LS::today();

        return view('leases.index', [
            'stats' => LS::stats(),
            'units' => LeaseUnit::with(['currentLease', 'room'])->where('is_active', true)->orderBy('block')->orderBy('code')->get(),
            'moving' => Lease::with('unit')->where('status', 'notice')->orderBy('move_out_on')->get(),
            'starting' => Lease::with('unit')->where('status', 'draft')->orderBy('start_date')->get(),
            'reviews' => Lease::with('unit')->whereIn('status', ['active', 'notice'])->whereNotNull('next_review_on')
                ->whereDate('next_review_on', '<=', Carbon::parse($today)->addDays(60)->toDateString())->orderBy('next_review_on')->get(),
            'today' => $today,
        ]);
    }

    public function list(Request $request)
    {
        $status = $request->query('status', 'live');
        $q = trim((string) $request->query('q', ''));
        $leases = Lease::with('unit', 'company')
            ->when($status === 'live', fn ($w) => $w->whereIn('status', ['draft', 'active', 'notice']))
            ->when($status !== 'live' && $status !== 'all', fn ($w) => $w->where('status', $status))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('lease_no', 'like', "%{$q}%")->orWhere('tenant_name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")
                ->orWhereHas('unit', fn ($u) => $u->where('code', 'like', "%{$q}%"))))
            ->orderByRaw("FIELD(status,'notice','active','draft','ended','terminated','cancelled')")->orderBy('end_date')->paginate(30)->withQueryString();
        $bal = $leases->getCollection()->mapWithKeys(fn ($l) => [$l->id => LB::balance($l)]);

        return view('leases.list', ['leases' => $leases, 'bal' => $bal, 'status' => $status, 'q' => $q]);
    }

    // ───────────── units & settings ─────────────

    public function units()
    {
        $linked = LeaseUnit::whereNotNull('room_id')->pluck('room_id');

        return view('leases.units', [
            'units' => LeaseUnit::with(['currentLease', 'room.roomType'])->orderBy('block')->orderBy('code')->get(),
            'rooms' => Room::with('roomType')->where('is_active', true)->orderBy('room_no')->get(),
            'linked' => $linked,
            'settings' => collect(LS::SETTINGS)->map(fn ($s, $k) => ['label' => $s[0], 'value' => LS::cfg($k)]),
        ]);
    }

    public function saveUnit(Request $request, ?LeaseUnit $leaseunit = null)
    {
        $d = $request->validate(['code' => 'required|string|max:20', 'name' => 'required|string|max:100', 'type' => 'required|string', 'room_id' => 'nullable|integer', 'block' => 'nullable|string|max:40',
            'floor' => 'nullable|string|max:10', 'bedrooms' => 'nullable|integer|min:0|max:9', 'size_sqm' => 'nullable|numeric|min:0', 'furnished' => 'nullable|boolean', 'tax_class' => 'required|string',
            'list_rent' => 'required|numeric|min:0', 'service_charge' => 'nullable|numeric|min:0', 'elec_meter' => 'nullable|string|max:30', 'water_meter' => 'nullable|string|max:30',
            'amenities' => 'nullable|string|max:255', 'is_active' => 'nullable|boolean', 'status' => 'nullable|in:vacant,maintenance']);
        $d['furnished'] = $request->boolean('furnished');
        $d['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : true;

        return $this->attempt(fn () => LS::saveUnit($d, $leaseunit?->exists ? $leaseunit : null), fn ($u) => "Unit {$u->code} saved.");
    }

    public function settings(Request $request)
    {
        $rules = collect(LS::SETTINGS)->mapWithKeys(fn ($s, $k) => [$k => 'required|numeric|min:0|max:100000'])->all();
        $d = $request->validate($rules);
        foreach ($d as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => (string) $v]);
        }
        Setting::$version++;

        return back()->with('success', 'Lease settings saved.');
    }

    // ───────────── leases ─────────────

    protected function formData(?Lease $lease = null): array
    {
        return ['lease' => $lease, 'units' => LeaseUnit::where('is_active', true)->where('status', '!=', 'maintenance')->orderBy('code')->get(),
            'companies' => Company::orderBy('name')->get(['id', 'name']), 'cfg' => collect(LS::SETTINGS)->map(fn ($s, $k) => LS::cfg($k))];
    }

    public function create(Request $request)
    {
        return view('leases.form', $this->formData() + ['unitId' => (int) $request->query('unit')]);
    }

    protected function rules(): array
    {
        return ['lease_unit_id' => 'required|integer', 'tenant_name' => 'required|string|max:120', 'company_id' => 'nullable|integer', 'phone' => 'nullable|string|max:30', 'email' => 'nullable|email|max:120',
            'id_type' => 'nullable|string|max:30', 'id_no' => 'nullable|string|max:40', 'occupants' => 'nullable|string|max:255', 'tax_class' => 'nullable|string', 'start_date' => 'required|date',
            'term_months' => 'required|integer|min:1|max:60', 'rent' => 'required|numeric|min:1', 'service_charge' => 'nullable|numeric|min:0', 'cycle_months' => 'required|integer',
            'deposit_amount' => 'nullable|numeric|min:0', 'escalation_pct' => 'nullable|numeric|min:0|max:100', 'escalation_months' => 'nullable|integer|min:1|max:60', 'notice_days' => 'nullable|integer|min:0|max:365',
            'elec_mode' => 'required|string', 'water_mode' => 'required|string', 'elec_tariff' => 'nullable|numeric|min:0', 'water_tariff' => 'nullable|numeric|min:0', 'wht_pct' => 'nullable|numeric|min:0|max:20',
            'inclusions' => 'nullable|string|max:255', 'auto_renew' => 'nullable|boolean', 'notes' => 'nullable|string|max:2000'];
    }

    public function store(Request $request)
    {
        $d = $request->validate($this->rules());
        $d['auto_renew'] = $request->boolean('auto_renew');
        try {
            $l = LS::create($d, $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('leases.show', $l)->with('success', "Lease {$l->lease_no} drafted. Print the agreement, record the signature and the deposit, then move the tenant in.");
    }

    public function edit(Lease $lease)
    {
        if ($lease->status !== 'draft') {
            return redirect()->route('leases.show', $lease)->with('error', 'Only a draft lease can be edited.');
        }

        return view('leases.form', $this->formData($lease) + ['unitId' => $lease->lease_unit_id]);
    }

    public function update(Request $request, Lease $lease)
    {
        $d = $request->validate($this->rules());
        $d['auto_renew'] = $request->boolean('auto_renew');
        try {
            LS::updateDraft($lease, $d);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('leases.show', $lease)->with('success', 'Lease terms updated.');
    }

    public function show(Lease $lease)
    {
        $lease->load('unit.room', 'company', 'creator');
        $charges = LeaseCharge::with('creditOf')->where('lease_id', $lease->id)->orderByDesc('date')->orderByDesc('id')->get();
        $payments = LeasePayment::with('paymentMode', 'user')->where('lease_id', $lease->id)->orderByDesc('date')->orderByDesc('id')->get();
        $unit = $lease->unit;
        $modes = PaymentMode::where('is_active', true)->orderBy('id')->get();
        $splitModes = $modes->whereIn('type', ['cash', 'card', 'bank', 'wallet'])->mapWithKeys(fn ($mode) => [$mode->id => $mode->name]);

        return view('leases.show', [
            'l' => $lease, 'unit' => $unit, 'charges' => $charges, 'payments' => $payments,
            'balance' => LB::balance($lease), 'deposit' => LB::depositHeld($lease), 'overdue' => LB::overdue($lease), 'cap' => LB::advanceCap($lease),
            'readings' => LeaseMeterReading::with('charge')->where('lease_unit_id', $unit->id)->orderByDesc('date')->orderByDesc('id')->limit(40)->get(),
            'inspections' => LeaseInspection::with('user', 'charge')->where('lease_id', $lease->id)->orderByDesc('date')->get(),
            'tickets' => MaintenanceRequest::where(fn ($q) => $q->when($unit->room_id, fn ($w) => $w->where('room_id', $unit->room_id))->orWhere('location', 'like', "%{$unit->code}%"))
                ->where('created_at', '>=', $lease->start_date->copy()->subDays(7))->latest()->limit(20)->get(),
            'modes' => $modes, 'splitModes' => $splitModes,
            'settlement' => in_array($lease->status, ['ended', 'terminated'], true) ? LS::settlement($lease) : null,
            'checklist' => LeaseInspection::CHECKLIST,
            'lastReadings' => collect(LeaseMeterReading::METERS)->mapWithKeys(fn ($m, $k) => [$k => LeaseMeterReading::where('lease_unit_id', $unit->id)->where('meter', $k)->orderByDesc('date')->orderByDesc('id')->first()]),
            'minNotice' => Carbon::parse(LS::today())->addDays($lease->notice_days)->toDateString(),
            'today' => LS::today(),
        ]);
    }

    public function sign(Request $request, Lease $lease)
    {
        $d = $request->validate(['signed_by' => 'required|string|max:120']);

        return $this->attempt(fn () => LS::sign($lease, $d['signed_by']), 'Signature recorded.');
    }

    protected function readings(Request $request): array
    {
        return collect(['electricity', 'water'])->mapWithKeys(fn ($m) => [$m => $request->input("reading_{$m}")])->filter(fn ($v) => $v !== null && $v !== '')->all();
    }

    protected function items(Request $request): ?array
    {
        $items = collect($request->input('items', []))->filter(fn ($i) => ! empty($i['item']))->values()->all();

        return $items ?: null;
    }

    public function activate(Request $request, Lease $lease)
    {
        $request->validate(['reading_electricity' => 'nullable|numeric|min:0', 'reading_water' => 'nullable|numeric|min:0', 'items' => 'nullable|array']);

        return $this->attempt(fn () => LS::activate($lease, $this->readings($request), $request->boolean('with_inspection') ? $this->items($request) : null, $request->user()),
            fn () => "{$lease->tenant_name} moved in. Invoiced to ".dmy($lease->refresh()->billed_to).' – balance '.money(LB::balance($lease)).'.');
    }

    public function cancel(Request $request, Lease $lease)
    {
        $d = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => LS::cancelDraft($lease, $d['reason']), "Lease {$lease->lease_no} cancelled.");
    }

    public function notice(Request $request, Lease $lease)
    {
        $d = $request->validate(['by' => 'required|in:tenant,landlord', 'move_out_on' => 'required|date', 'reason' => 'required|string|max:255', 'notice_on' => 'nullable|date']);

        return $this->attempt(fn () => LS::giveNotice($lease, $d['by'], Carbon::parse($d['move_out_on'])->toDateString(), $d['reason'], $request->user(),
            ! empty($d['notice_on']) ? Carbon::parse($d['notice_on'])->toDateString() : null), 'Notice recorded – move-out '.dmy($d['move_out_on']).'.');
    }

    public function withdrawNotice(Lease $lease)
    {
        return $this->attempt(fn () => LS::withdrawNotice($lease), 'Notice withdrawn – the lease runs to '.dmy($lease->end_date).'.');
    }

    public function renew(Request $request, Lease $lease)
    {
        $d = $request->validate(['months' => 'required|integer|min:1|max:60', 'rent' => 'nullable|numeric|min:1']);

        return $this->attempt(fn () => LS::renew($lease, (int) $d['months'], isset($d['rent']) ? (float) $d['rent'] : null, $request->user()),
            fn () => 'Renewed to '.dmy($lease->refresh()->end_date).'.');
    }

    public function moveOut(Request $request, Lease $lease)
    {
        $d = $request->validate(['date' => 'required|date', 'reading_electricity' => 'nullable|numeric|min:0', 'reading_water' => 'nullable|numeric|min:0', 'items' => 'nullable|array', 'tenant_ack' => 'nullable|string|max:120']);

        return $this->attempt(fn () => LS::moveOut($lease, Carbon::parse($d['date'])->toDateString(), $this->readings($request), $this->items($request), $d['tenant_ack'] ?? null, $request->user()),
            fn () => "Moved out. Balance ".money(LB::balance($lease)).', deposit held '.money(LB::depositHeld($lease)).' – settle the deposit next.');
    }

    public function settle(Request $request, Lease $lease)
    {
        $d = $request->validate(['payment_mode_id' => 'nullable|integer', 'reference' => 'nullable|string|max:80']);

        return $this->attempt(fn () => LS::settle($lease, ! empty($d['payment_mode_id']) ? (int) $d['payment_mode_id'] : null, $d['reference'] ?? null, $request->user()),
            fn ($s) => 'Deposit settled: applied '.money($s['apply']).', refunded '.money($s['refund']).($s['due'] > 0 ? ' – the tenant still owes '.money($s['due']).'.' : '.'));
    }

    public function inspect(Request $request, Lease $lease)
    {
        $d = $request->validate(['kind' => 'required|in:move_in,periodic', 'items' => 'required|array', 'tenant_ack' => 'nullable|string|max:120', 'notes' => 'nullable|string|max:500']);

        return $this->attempt(fn () => LS::inspect($lease, $d['kind'], $this->items($request) ?? [], $d['tenant_ack'] ?? null, $d['notes'] ?? null, $request->user()),
            fn ($i) => LeaseInspection::KINDS[$i->kind].' inspection recorded'.((float) $i->damages > 0 ? ' – damages '.money($i->damages).' charged.' : '.'));
    }

    public function inspection(LeaseInspection $leaseinspection)
    {
        $leaseinspection->load('lease.unit', 'user');

        return view('leases.print-inspection', ['i' => $leaseinspection, 'l' => $leaseinspection->lease, 'hotel' => Tenant::hotel()]);
    }

    public function agreement(Lease $lease)
    {
        $lease->load('unit', 'company');

        return view('leases.print-agreement', ['l' => $lease, 'hotel' => Tenant::hotel(), 'rate' => LB::rateFor($lease->tax_class), 'cap' => LS::residentialMaxCycle($lease->term_months),
            'tariffs' => ['electricity' => LS::tariff($lease, 'electricity'), 'water' => LS::tariff($lease, 'water')], 'grace' => (int) LS::cfg('lease_grace_days'), 'lateFee' => (float) LS::cfg('lease_late_fee_pct')]);
    }

    // ───────────── meters ─────────────

    public function meters(Request $request)
    {
        $date = $request->query('date', LS::today());
        $units = LeaseUnit::with('currentLease')->where('is_active', true)->orderBy('code')->get();
        $last = LeaseMeterReading::whereIn('lease_unit_id', $units->pluck('id'))->orderBy('date')->orderBy('id')->get()->groupBy(fn ($r) => $r->lease_unit_id.'-'.$r->meter)->map->last();

        return view('leases.meters', ['units' => $units, 'last' => $last, 'date' => $date,
            'recent' => LeaseMeterReading::with('unit', 'charge', 'user')->orderByDesc('date')->orderByDesc('id')->limit(60)->get()]);
    }

    public function saveReadings(Request $request)
    {
        $d = $request->validate(['date' => 'required|date', 'r' => 'required|array']);
        $date = Carbon::parse($d['date'])->toDateString();
        if ($date > LS::today()) {
            return back()->with('error', 'Readings cannot be dated in the future.');
        }
        $ok = 0;
        $charged = 0.0;
        $errors = [];
        foreach ($d['r'] as $unitId => $meters) {
            $unit = LeaseUnit::find($unitId);
            if (! $unit) {
                continue;
            }
            foreach ((array) $meters as $meter => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                try {
                    $r = LS::recordReading($unit, $meter, (float) $v, $request->user(), $date);
                    $ok++;
                    $charged += (float) ($r->charge?->total ?? 0);
                } catch (RuntimeException $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }

        return back()->with($errors ? 'warning' : 'success', "{$ok} reading(s) saved, ".money($charged).' recharged to tenants.'.($errors ? ' '.implode(' ', $errors) : ''));
    }

    public function nightly()
    {
        $r = LS::nightly();

        return back()->with('success', "Billing run: {$r['invoices']} rent invoice(s), {$r['late_fees']} late fee(s)".($r['renewed'] ? ', renewed '.implode(', ', $r['renewed']) : '').'.');
    }
}
