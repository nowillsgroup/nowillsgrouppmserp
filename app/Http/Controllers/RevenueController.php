<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\RmSnapshot;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\YieldRule;
use App\Services\ActivityLogger;
use App\Services\RevenueService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * v1.9 revenue management: demand forecast & pickup dashboard, pricing grid with recommendations
 * (publish / accept / reset), dynamic-pricing rules, rate floors & ceilings and the auto-pilot switch.
 */
class RevenueController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['days' => 'nullable|integer|in:14,30,60,90']);
        $days = (int) $request->input('days', 30);
        $from = bdate()->copy()->startOfDay();
        $all = RevenueService::forecast($from, max($days, 30));
        $rows = array_slice($all, 0, $days, true);
        $since = bdate()->copy()->subDays(7)->startOfDay();
        $sources = Reservation::with('source:id,name', 'rooms')->where('created_at', '>=', $since)->where('status', '!=', 'cancelled')->get()
            ->groupBy(fn ($r) => $r->source?->name ?? 'Unspecified')
            ->map(fn ($g, $name) => ['name' => $name, 'bookings' => $g->count(), 'nights' => $g->sum(fn ($r) => $r->rooms->sum(fn ($s) => $s->nights)),
                'revenue' => $g->sum(fn ($r) => $r->rooms->sum(fn ($s) => $s->nights * $s->nightlyTotal()))])
            ->sortByDesc('revenue')->values();
        $types = RoomType::where('is_active', true)->orderBy('base_rate')->get();
        $rec = RevenueService::recommendations($types, $from, 14);
        $pending = collect($rec['grid'])->flatten(1)->where('change', true)->count();

        return view('revenue.index', [
            'days' => $days, 'rows' => $rows, 'kpi' => RevenueService::kpis($all), 'sources' => $sources,
            'mode' => RevenueService::mode(), 'rulesCount' => YieldRule::where('is_active', true)->count(), 'pending' => $pending,
            'lastSnapshot' => RmSnapshot::max('snapshot_date'),
        ]);
    }

    public function pricing(Request $request)
    {
        $request->validate(['from' => 'nullable|date', 'days' => 'nullable|integer|in:7,14,21,28']);
        $from = Carbon::parse($request->input('from', bdate()->toDateString()))->startOfDay()->max(bdate()->copy()->startOfDay());
        $days = (int) $request->input('days', 14);
        $types = RoomType::where('is_active', true)->orderBy('base_rate')->get();
        $rec = RevenueService::recommendations($types, $from, $days);

        return view('revenue.pricing', [
            'from' => $from, 'days' => $days, 'types' => $types, 'grid' => $rec['grid'], 'occ' => $rec['occ'],
            'mode' => RevenueService::mode(), 'pending' => collect($rec['grid'])->flatten(1)->where('change', true)->count(),
        ]);
    }

    public function publish(Request $request)
    {
        $request->validate([
            'accept_all' => 'nullable|boolean', 'from' => 'required|date', 'days' => 'required|integer|in:7,14,21,28',
            'pick' => 'nullable|array|max:400', 'pick.*' => ['string', 'regex:/^\d+\|\d{4}-\d{2}-\d{2}$/'],
            'rate' => 'nullable|array', 'rate.*' => 'array', 'rate.*.*' => 'nullable|numeric|min:1|max:99999999',
        ]);
        $back = redirect()->route('revenue.pricing', ['from' => $request->input('from'), 'days' => $request->input('days')]);
        if (RevenueService::mode() === 'auto') {
            return $back->with('error', 'Auto-pilot is on – rates are already applied live. Switch to Recommend to publish manually.');
        }
        $cells = [];
        if ($request->boolean('accept_all')) {
            $types = RoomType::where('is_active', true)->get();
            $rec = RevenueService::recommendations($types, Carbon::parse($request->input('from')), (int) $request->input('days'));
            foreach ($rec['grid'] as $typeId => $dates) {
                foreach ($dates as $date => $c) {
                    if ($c['change']) {
                        $cells[$typeId][$date] = $c['rec']['rate'];
                    }
                }
            }
        } else {
            foreach ($request->input('pick', []) as $key) {
                [$typeId, $date] = explode('|', $key);
                $rate = $request->input("rate.$typeId.$date");
                if ($rate !== null && $rate !== '') {
                    $cells[(int) $typeId][$date] = (float) $rate;
                }
            }
        }
        if (! $cells) {
            return $back->with('warning', 'Nothing to publish – tick the dates to change (or use “Accept all”).');
        }
        try {
            $s = RevenueService::publish($cells);
        } catch (RuntimeException $e) {
            return $back->with('error', $e->getMessage());
        }

        return $back->with('success', "Published {$s['published']} day rate(s)".($s['reverted'] ? ", {$s['reverted']} back to base" : '').($s['skipped'] ? ", {$s['skipped']} skipped (past or fixed-price dates)" : '').'.');
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'date_from' => 'required|date', 'date_to' => 'required|date|after_or_equal:date_from',
            'room_type_id' => 'nullable|integer|owned:room_types', 'days' => 'nullable|integer|in:7,14,21,28',
        ]);
        $from = Carbon::parse($data['date_from'])->max(bdate()->copy()->startOfDay());
        $n = RevenueService::reset($from, Carbon::parse($data['date_to']), ! empty($data['room_type_id']) ? [(int) $data['room_type_id']] : null);

        return redirect()->route('revenue.pricing', ['from' => $data['date_from'], 'days' => $data['days'] ?? 14])
            ->with('success', "Removed {$n} revenue-managed day rate(s) – those dates are back on the base / seasonal rates.");
    }

    public function rules()
    {
        return view('revenue.rules', [
            'rules' => YieldRule::with('roomType')->orderBy('priority')->orderBy('id')->get(),
            'types' => RoomType::where('is_active', true)->orderBy('base_rate')->get(),
            'mode' => RevenueService::mode(), 'roundTo' => setting('rm_round_to', 50), 'horizon' => RevenueService::horizon(),
        ]);
    }

    public function settings(Request $request)
    {
        $data = $request->validate([
            'rm_mode' => ['required', Rule::in(array_keys(RevenueService::MODES))],
            'rm_round_to' => 'required|numeric|min:0|max:10000', 'rm_horizon_days' => 'required|integer|min:14|max:365',
        ]);
        $old = RevenueService::mode();
        foreach ($data as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => (string) $v]);
        }
        ActivityLogger::log('revenue', 'settings', "Revenue management mode {$old} → {$data['rm_mode']}, round to {$data['rm_round_to']}, horizon {$data['rm_horizon_days']} days");
        $msg = match (true) {
            $data['rm_mode'] === 'auto' && $old !== 'auto' => 'Auto-pilot is on – the booking engine, channels and front desk now price every future night live from your rules.',
            $data['rm_mode'] === 'off' => 'Revenue management is off – published day rates stay until you reset them; no live pricing.',
            default => 'Revenue management settings saved.',
        };

        return redirect()->route('revenue.rules')->with('success', $msg);
    }

    public function limits(Request $request)
    {
        $request->validate(['min_rate' => 'array', 'min_rate.*' => 'nullable|numeric|min:0|max:99999999', 'max_rate' => 'array', 'max_rate.*' => 'nullable|numeric|min:0|max:99999999']);
        $types = RoomType::where('is_active', true)->get();
        $errors = [];
        foreach ($types as $t) {
            $min = $request->input("min_rate.{$t->id}");
            $max = $request->input("max_rate.{$t->id}");
            if ($min !== null && $min !== '' && $max !== null && $max !== '' && (float) $max < (float) $min) {
                $errors["max_rate.{$t->id}"] = "{$t->name}: the ceiling must be at least the floor.";
            }
        }
        if ($errors) {
            return back()->withInput()->withErrors($errors);
        }
        foreach ($types as $t) {
            $min = $request->input("min_rate.{$t->id}");
            $max = $request->input("max_rate.{$t->id}");
            $t->update(['min_rate' => $min === null || $min === '' ? null : $min, 'max_rate' => $max === null || $max === '' ? null : $max]);
        }
        ActivityLogger::log('revenue', 'limits', 'Updated rate floors & ceilings');

        return redirect()->route('revenue.rules')->with('success', 'Rate floors & ceilings saved.');
    }

    public function createRule()
    {
        return view('revenue.rule-form', ['rule' => new YieldRule(['basis' => 'hotel', 'adjust_type' => 'percent', 'priority' => 100, 'is_active' => true]), 'types' => RoomType::where('is_active', true)->orderBy('base_rate')->get()]);
    }

    public function editRule(YieldRule $yrule)
    {
        return view('revenue.rule-form', ['rule' => $yrule, 'types' => RoomType::where('is_active', true)->orderBy('base_rate')->get()]);
    }

    public function storeRule(Request $request)
    {
        $rule = YieldRule::create($this->ruleData($request) + ['created_by' => auth()->id()]);
        ActivityLogger::log('revenue', 'create', "Pricing rule '{$rule->name}' ({$rule->adjustmentLabel()})", $rule);

        return redirect()->route('revenue.rules')->with('success', "Pricing rule “{$rule->name}” added.");
    }

    public function updateRule(Request $request, YieldRule $yrule)
    {
        $yrule->update($this->ruleData($request));
        ActivityLogger::log('revenue', 'update', "Pricing rule '{$yrule->name}' ({$yrule->adjustmentLabel()})", $yrule);

        return redirect()->route('revenue.rules')->with('success', "Pricing rule “{$yrule->name}” updated.");
    }

    public function toggleRule(YieldRule $yrule)
    {
        $yrule->update(['is_active' => ! $yrule->is_active]);
        ActivityLogger::log('revenue', 'toggle', "Pricing rule '{$yrule->name}' ".($yrule->is_active ? 'enabled' : 'disabled'), $yrule);

        return back()->with('success', "“{$yrule->name}” ".($yrule->is_active ? 'enabled.' : 'disabled.'));
    }

    public function destroyRule(YieldRule $yrule)
    {
        ActivityLogger::log('revenue', 'delete', "Deleted pricing rule '{$yrule->name}'", $yrule);
        $yrule->delete();

        return redirect()->route('revenue.rules')->with('success', 'Pricing rule deleted.');
    }

    /** Rebuild the on-the-books history (pickup needs yesterday's and last week's position). */
    public function snapshot()
    {
        for ($i = 14; $i >= 1; $i--) {
            RevenueService::reconstruct(bdate()->copy()->subDays($i));
        }
        ActivityLogger::log('revenue', 'snapshot', 'Rebuilt 14 days of on-the-books history');

        return back()->with('success', 'On-the-books history rebuilt for the last 14 days.');
    }

    protected function ruleData(Request $request): array
    {
        $pct = $request->input('adjust_type') !== 'amount';
        $d = $request->validate([
            'name' => 'required|string|max:80',
            'room_type_id' => 'nullable|integer|owned:room_types',
            'basis' => 'required|in:hotel,type',
            'occ_min' => 'nullable|integer|min:0|max:100', 'occ_max' => 'nullable|integer|min:0|max:100',
            'lead_min' => 'nullable|integer|min:0|max:730', 'lead_max' => 'nullable|integer|min:0|max:730',
            'days' => 'nullable|array', 'days.*' => 'integer|between:1,7',
            'date_from' => 'nullable|date', 'date_to' => 'nullable|date|after_or_equal:date_from',
            'adjust_type' => 'required|in:percent,amount',
            'adjust_value' => ['required', 'numeric', 'not_in:0', $pct ? 'between:-90,300' : 'between:-9999999,9999999'],
            'min_stay' => 'nullable|integer|min:2|max:14',
            'priority' => 'required|integer|min:1|max:999',
            'is_active' => 'nullable|boolean',
        ], ['adjust_value.not_in' => 'The adjustment cannot be zero.']);
        $bad = [];
        if (isset($d['occ_min'], $d['occ_max']) && $d['occ_max'] < $d['occ_min']) {
            $bad['occ_max'] = 'The upper occupancy must be at least the lower one.';
        }
        if (isset($d['lead_min'], $d['lead_max']) && $d['lead_max'] < $d['lead_min']) {
            $bad['lead_max'] = 'The longest lead time must be at least the shortest.';
        }
        if ($bad) {
            throw \Illuminate\Validation\ValidationException::withMessages($bad);
        }
        $days = array_values(array_unique(array_map('intval', $d['days'] ?? [])));
        sort($days);
        $d['days'] = $days && count($days) < 7 ? implode(',', $days) : null;
        $d['is_active'] = $request->boolean('is_active');
        $d['room_type_id'] = $d['room_type_id'] ?? null;
        foreach (['occ_min', 'occ_max', 'lead_min', 'lead_max', 'date_from', 'date_to', 'min_stay'] as $k) {
            $d[$k] = $d[$k] ?? null;
        }

        return $d;
    }
}
