<?php

namespace App\Http\Controllers;

use App\Models\Folio;
use App\Models\Outlet;
use App\Models\Package;
use App\Models\PackageEntitlement;
use App\Models\PackageRedemption;
use App\Models\RatePlan;
use App\Models\ReservationRoom;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\PackageService as PS;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

/** v5.5 Rate packages, meal plans & inclusions: dashboard, meal desk, entitlement register, catalogue & rate-plan inclusions. */
class PackageController extends Controller
{
    public const SETTINGS = [
        'pkg_grace_days' => ['0', 'Days an entitlement stays usable after its service date (0 = that day only)'],
        'pkg_overage_default' => ['folio', 'Extra covers beyond the entitlement are by default'],
        'pkg_breakage_to' => ['breakage', 'Unused package value (breakage) is recognised in'],
    ];

    public function index()
    {
        $d = bdate();
        $pkgs = Package::where('is_allowance', true)->where('is_active', true)->orderBy('sort')->orderBy('name')->get();
        $forecast = PS::forecast($d->copy(), $d->copy()->addDays(6));
        $today = PackageEntitlement::with('package')->whereDate('service_date', $d->toDateString())->whereIn('status', ['open', 'partial', 'consumed'])->where('is_allowance', true)->get();
        $mtd = [$d->copy()->startOfMonth()->toDateString(), $d->toDateString()];
        $stats = [
            'covers_today' => (int) $today->sum('qty'),
            'served_today' => (int) $today->sum('consumed_qty'),
            'liability' => PS::openLiability(),
            'allocated_mtd' => (float) PackageEntitlement::whereBetween('night_date', $mtd)->where('status', '!=', 'cancelled')->sum('amount'),
            'breakage_mtd' => (float) PackageEntitlement::whereBetween('expired_date', $mtd)->sum('breakage_amount'),
            'extras_mtd' => (float) PackageRedemption::whereBetween('business_date', $mtd)->whereNull('voided_at')->where('overage_mode', 'folio')->sum('extra_amount'),
        ];
        $recent = PackageRedemption::with('package', 'stay.room', 'stay.guest', 'recorder')->orderByDesc('id')->limit(12)->get();
        $byPkg = PackageEntitlement::whereBetween('night_date', $mtd)->where('status', '!=', 'cancelled')
            ->selectRaw('package_id, SUM(amount) allocated, SUM(consumed_amount) consumed, SUM(breakage_amount) breakage, SUM(qty) covers, SUM(consumed_qty) used')
            ->groupBy('package_id')->get()->keyBy('package_id');

        return view('packages.index', compact('pkgs', 'forecast', 'today', 'stats', 'recent', 'byPkg') + ['allPkgs' => Package::orderBy('sort')->orderBy('name')->get()->keyBy('id')]);
    }

    /** Restaurant door: rooms entitled to today's meal, covers still to serve, record usage. */
    public function desk(Request $request)
    {
        $pkgs = Package::where('is_allowance', true)->where('is_active', true)->orderBy('sort')->orderBy('name')->get();
        $pkg = $pkgs->firstWhere('id', (int) $request->input('package')) ?? $pkgs->first();
        $q = trim((string) $request->input('q'));
        $rows = collect();
        if ($pkg) {
            $rows = PS::usable()->with('stay.room', 'stay.guest', 'stay.folio', 'stay.ratePlan')->where('package_id', $pkg->id)
                ->when($q !== '', fn ($w) => $w->whereHas('stay', fn ($s) => $s->whereHas('room', fn ($r) => $r->where('room_no', 'like', "%{$q}%"))
                    ->orWhereHas('guest', fn ($g) => $g->where('first_name', 'like', "%{$q}%")->orWhere('last_name', 'like', "%{$q}%"))))
                ->get()->sortBy(fn ($e) => $e->stay?->room?->room_no)->values();
        }
        $served = $pkg ? PackageRedemption::with('stay.room', 'stay.guest', 'recorder')->where('package_id', $pkg->id)->whereDate('business_date', bdate()->toDateString())
            ->orderByDesc('id')->get() : collect();
        // in-house rooms without the package (walk-in to breakfast, charged to the room)
        $inHouse = ReservationRoom::with('room', 'guest')->where('status', 'checked_in')->get()->sortBy(fn ($s) => $s->room?->room_no)->values();
        $counts = $pkgs->mapWithKeys(fn ($p) => [$p->id => (int) PS::usable()->where('package_id', $p->id)->selectRaw('COALESCE(SUM(qty - consumed_qty),0) n')->value('n')]);

        return view('packages.desk', compact('pkgs', 'pkg', 'rows', 'served', 'inHouse', 'q', 'counts') + ['outlets' => Outlet::orderBy('name')->get()]);
    }

    public function redeem(Request $request)
    {
        $data = $request->validate([
            'reservation_room_id' => ['required', Rule::exists('reservation_rooms', 'id')->where('hotel_id', Tenant::id())],
            'package_id' => ['required', Rule::exists('packages', 'id')->where('hotel_id', Tenant::id())],
            'covers' => 'nullable|integer|min:0|max:50', 'extra_covers' => 'nullable|integer|min:0|max:50', 'amount' => 'nullable|numeric|min:0|max:1000000',
            'overage_mode' => 'nullable|in:'.implode(',', array_keys(PackageRedemption::OVERAGE)),
            'outlet_id' => ['nullable', Rule::exists('outlets', 'id')->where('hotel_id', Tenant::id())],
            'pos_ref' => 'nullable|string|max:40', 'notes' => 'nullable|string|max:255',
        ]);

        return $this->attempt(function () use ($data) {
            $r = PS::redeem(ReservationRoom::with('folio', 'room')->findOrFail($data['reservation_room_id']), Package::findOrFail($data['package_id']), $data);
            $msg = "{$r->redemption_no}: room {$r->stay?->room?->room_no} – ".($r->package->redeem_mode === 'amount' ? money($r->amount).' of allowance used' : "{$r->covers} cover(s) served");
            if ((float) $r->extra_amount > 0) {
                $msg .= ', extra '.money($r->extra_amount).' '.strtolower(PackageRedemption::OVERAGE[$r->overage_mode]);
            }

            return redirect()->route('packages.desk', ['package' => $r->package_id])->with('success', $msg.'.');
        });
    }

    public function void(Request $request, PackageRedemption $pkgredeem)
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:255']);

        return $this->attempt(function () use ($pkgredeem, $data) {
            PS::void($pkgredeem, $data['reason']);

            return back()->with('success', "{$pkgredeem->redemption_no} voided".($pkgredeem->folio_transaction_id ? ' and the extra charge reversed on the folio.' : '.'));
        });
    }

    public function register(Request $request)
    {
        $from = $request->date('from') ?? bdate()->copy()->subDays(6);
        $to = $request->date('to') ?? bdate()->copy()->addDay();
        $rows = PackageEntitlement::with('package', 'stay.room', 'stay.guest')
            ->whereBetween('service_date', [$from->toDateString(), $to->toDateString()])
            ->when($request->filled('package'), fn ($q) => $q->where('package_id', (int) $request->input('package')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->input('source')))
            ->orderByDesc('service_date')->orderBy('id')->paginate(50)->withQueryString();
        $tot = PackageEntitlement::whereBetween('service_date', [$from->toDateString(), $to->toDateString()])
            ->when($request->filled('package'), fn ($q) => $q->where('package_id', (int) $request->input('package')))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->input('source')))
            ->where('status', '!=', 'cancelled')
            ->selectRaw('COUNT(*) n, COALESCE(SUM(amount),0) allocated, COALESCE(SUM(consumed_amount),0) consumed, COALESCE(SUM(breakage_amount),0) breakage, COALESCE(SUM(qty),0) covers, COALESCE(SUM(consumed_qty),0) used')->first();

        return view('packages.register', compact('rows', 'from', 'to', 'tot') + ['pkgs' => Package::orderBy('name')->get()]);
    }

    public function catalogue()
    {
        $pkgs = Package::with('outlet')->withCount('ratePlans')->orderByDesc('is_active')->orderBy('sort')->orderBy('name')->get();
        $plans = RatePlan::with('roomType', 'packages')->orderBy('room_type_id')->orderBy('rate')->get();
        $settings = collect(self::SETTINGS)->mapWithKeys(fn ($d, $k) => [$k => setting($k, $d[0])]);

        return view('packages.catalogue', compact('pkgs', 'plans', 'settings') + ['outlets' => Outlet::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $p = Package::create($this->validated($request));
        ActivityLogger::log('packages', 'create', "Created package {$p->code} – {$p->name} ({$p->priceLabel()})", $p);

        return redirect()->route('packages.catalogue')->with('success', "Package {$p->code} created.");
    }

    public function update(Request $request, Package $pkgitem)
    {
        $pkgitem->update($this->validated($request, $pkgitem));
        foreach ($pkgitem->ratePlans as $plan) {
            $plan->update(['meal_plan' => PS::mealPlanFor($plan)]);
        }
        ActivityLogger::log('packages', 'update', "Updated package {$pkgitem->code} ({$pkgitem->priceLabel()})", $pkgitem);

        return redirect()->route('packages.catalogue')->with('success', "Package {$pkgitem->code} updated. New prices apply from tonight's allocation.");
    }

    public function toggle(Package $pkgitem)
    {
        $pkgitem->update(['is_active' => ! $pkgitem->is_active]);
        foreach ($pkgitem->ratePlans as $plan) {
            $plan->update(['meal_plan' => PS::mealPlanFor($plan)]);
        }
        ActivityLogger::log('packages', 'toggle', ($pkgitem->is_active ? 'Activated' : 'Deactivated')." package {$pkgitem->code}", $pkgitem);

        return back()->with('success', "Package {$pkgitem->code} ".($pkgitem->is_active ? 'activated.' : 'deactivated – it is no longer allocated or sold.'));
    }

    /** Rate plan × package matrix. The meal-plan code of each rate plan follows its included meals. */
    public function inclusions(Request $request)
    {
        $inc = (array) $request->input('inc', []);
        $valid = Package::pluck('id')->all();
        $changed = 0;
        DB::transaction(function () use ($inc, $valid, &$changed) {
            foreach (RatePlan::with('packages')->get() as $plan) {
                $ids = array_values(array_intersect(array_map('intval', (array) ($inc[$plan->id] ?? [])), $valid));
                $before = $plan->packages->pluck('id')->sort()->values()->all();
                sort($ids);
                if ($before === $ids) {
                    continue;
                }
                $plan->packages()->syncWithPivotValues($ids, ['hotel_id' => Tenant::id()]);
                $plan->update(['meal_plan' => PS::mealPlanFor($plan)]);
                $changed++;
            }
        });
        ActivityLogger::log('packages', 'inclusions', "Updated package inclusions of {$changed} rate plan(s)");

        return redirect()->to(route('packages.catalogue').'#inclusions')->with('success', $changed ? "Inclusions saved for {$changed} rate plan(s) – meal plan codes updated." : 'No changes.');
    }

    public function settings(Request $request)
    {
        $data = $request->validate(['pkg_grace_days' => 'required|integer|min:0|max:3', 'pkg_overage_default' => 'required|in:folio,paid,waived',
            'pkg_breakage_to' => 'required|in:breakage,room,package']);
        foreach ($data as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => (string) $v]);
        }
        Setting::$version++;
        ActivityLogger::log('packages', 'settings', 'Updated package settings');

        return redirect()->to(route('packages.catalogue').'#settings')->with('success', 'Package settings saved.');
    }

    /** Sell an add-on package from the folio. */
    public function addOn(Request $request, Folio $folio)
    {
        $data = $request->validate([
            'package_id' => ['required', Rule::exists('packages', 'id')->where('hotel_id', Tenant::id())],
            'start_date' => 'required|date', 'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);
        abort_unless($folio->status === 'open' && $folio->stay, 422, 'Packages need the open folio of a stay.');

        return $this->attempt(function () use ($folio, $data) {
            $fc = PS::addToStay($folio->stay, Package::findOrFail($data['package_id']), $data['start_date'], $data['end_date'] ?? null);

            return back()->with('success', "{$fc->description} added – ".money($fc->amount).($fc->frequency === 'once' ? ' once.' : ' per night, posted with the room tariff.'));
        });
    }

    private function validated(Request $request, ?Package $p = null): array
    {
        $d = $request->validate([
            'code' => ['required', 'string', 'max:12', 'regex:/^[A-Z0-9_-]+$/i', Rule::unique('packages', 'code')->where('hotel_id', Tenant::id())->ignore($p?->id)],
            'name' => 'required|string|max:80', 'description' => 'nullable|string|max:255',
            'category' => 'required|in:'.implode(',', array_keys(config('pms.charge_categories'))),
            'outlet_id' => ['nullable', Rule::exists('outlets', 'id')->where('hotel_id', Tenant::id())],
            'meal' => 'nullable|in:'.implode(',', array_keys(Package::MEALS)),
            'calc' => 'required|in:'.implode(',', array_keys(Package::CALCS)),
            'price_adult' => 'required|numeric|min:0|max:1000000', 'price_child' => 'nullable|numeric|min:0|max:1000000',
            'rhythm' => 'required|in:'.implode(',', array_keys(Package::RHYTHMS)),
            'service_day' => 'required|in:'.implode(',', array_keys(Package::SERVICE_DAYS)),
            'redeem_mode' => 'required|in:'.implode(',', array_keys(Package::MODES)),
            'overage_price' => 'nullable|numeric|min:0|max:1000000', 'sort' => 'nullable|integer|min:0|max:999',
        ]);
        $d['code'] = strtoupper($d['code']);
        $d['price_child'] = $d['price_child'] ?? 0;
        $d['overage_price'] = $d['overage_price'] ?? 0;
        $d['sort'] = $d['sort'] ?? 0;
        foreach (['is_allowance', 'apply_tax', 'is_sellable'] as $b) {
            $d[$b] = $request->boolean($b);
        }

        return $d;
    }

    private function attempt(callable $fn)
    {
        try {
            return $fn();
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }
}
