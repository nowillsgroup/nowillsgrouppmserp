<?php

namespace App\Http\Controllers;

use App\Models\RateOverride;
use App\Models\RoomType;
use App\Services\ActivityLogger;
use App\Services\RateService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rate management: seasonal / date-range pricing, weekend rates, minimum stay and stop-sell.
 */
class RateController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['from' => 'nullable|date', 'days' => 'nullable|integer|in:14,21,30,45,60']);
        $from = Carbon::parse($request->input('from', bdate()->toDateString()))->startOfDay();
        $days = (int) $request->input('days', 30);
        $types = RoomType::where('is_active', true)->orderBy('base_rate')->get();

        return view('rates.index', [
            'from' => $from, 'days' => $days, 'types' => $types,
            'grid' => RateService::calendar($types, $from, $days),
            'overrides' => RateOverride::with('roomType', 'creator')->where('source', '!=', 'rm')->where('date_to', '>=', bdate()->toDateString())->orderBy('date_from')->get(),
            'rmCount' => RateOverride::where('source', 'rm')->where('date_to', '>=', bdate()->toDateString())->count(),
            'rmMode' => \App\Services\RevenueService::mode(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'room_type_ids' => 'required|array|min:1', 'room_type_ids.*' => 'integer|owned:room_types',
            'date_from' => 'required|date', 'date_to' => 'required|date|after_or_equal:date_from|before:'.Carbon::parse($request->input('date_from', 'today'))->addYears(2)->toDateString(),
            'rate' => 'nullable|numeric|min:0|max:99999999', 'weekend_rate' => 'nullable|numeric|min:0|max:99999999',
            'min_stay' => 'nullable|integer|min:1|max:30', 'stop_sell' => 'nullable|boolean', 'is_fixed' => 'nullable|boolean',
        ]);
        if (($data['rate'] ?? null) === null && ($data['weekend_rate'] ?? null) === null && empty($data['stop_sell']) && (int) ($data['min_stay'] ?? 1) <= 1) {
            return back()->withInput()->with('error', 'Nothing to apply – enter a rate, weekend rate, minimum stay or tick stop-sell.');
        }
        DB::transaction(function () use ($data) {
            foreach (array_unique($data['room_type_ids']) as $typeId) {
                $o = RateOverride::create([
                    'room_type_id' => $typeId, 'name' => $data['name'], 'date_from' => $data['date_from'], 'date_to' => $data['date_to'],
                    'rate' => $data['rate'] ?? null, 'weekend_rate' => $data['weekend_rate'] ?? null, 'min_stay' => $data['min_stay'] ?? 1,
                    'stop_sell' => (bool) ($data['stop_sell'] ?? false), 'is_fixed' => (bool) ($data['is_fixed'] ?? false), 'created_by' => auth()->id(),
                ]);
                ActivityLogger::log('rates', 'create', "Rate override '{$o->name}' {$o->date_from->format('d/m/Y')}–{$o->date_to->format('d/m/Y')} for {$o->roomType->name}", $o);
            }
        });

        return redirect()->route('rates.index', ['from' => $data['date_from']])->with('success', 'Rates updated for '.count($data['room_type_ids']).' room type(s).');
    }

    public function destroy(RateOverride $override)
    {
        ActivityLogger::log('rates', 'delete', "Removed rate override '{$override->name}' for {$override->roomType->name}", $override);
        $override->delete();

        return back()->with('success', 'Rate override removed – base rates apply again for those dates.');
    }
}
