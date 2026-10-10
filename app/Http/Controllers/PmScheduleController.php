<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\PmSchedule;
use App\Models\Room;
use App\Services\ActivityLogger;
use App\Services\PmService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PmScheduleController extends Controller
{
    public const CATEGORIES = ['general' => 'General', 'electrical' => 'Electrical', 'plumbing' => 'Plumbing', 'ac' => 'AC / HVAC', 'carpentry' => 'Carpentry', 'it' => 'IT / TV / Wi-Fi', 'civil' => 'Civil'];

    public function index(Request $request)
    {
        $filter = $request->input('show', 'all');
        $all = PmSchedule::with('asset', 'room', 'employee', 'openTicket')->orderBy('next_due')->get();
        $rows = $all->filter(fn ($s) => $filter === 'all' || $s->state() === $filter)->values();
        $from = bdate()->copy()->subDays(90)->toDateString();

        return view('assets.pm', [
            'schedules' => $rows,
            'counts' => $all->countBy(fn ($s) => $s->state()),
            'total' => $all->count(),
            'assets' => Asset::where('status', '!=', 'disposed')->orderBy('name')->get()->mapWithKeys(fn ($a) => [$a->id => $a->name.' · '.$a->asset_no]),
            'rooms' => Room::orderBy('room_no')->pluck('room_no', 'id'),
            'staff' => Employee::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'compliance' => PmService::compliance($from, bdate()->toDateString()),
            'toRaise' => $all->filter(fn ($s) => $s->is_active && ! $s->openTicket && $s->next_due->copy()->subDays($s->lead_days)->lte(bdate()) && (! $s->asset || ! $s->asset->isDisposed()))->count(),
        ]);
    }

    public function store(Request $request)
    {
        $s = PmSchedule::create($this->validated($request) + ['created_by' => auth()->id()]);
        ActivityLogger::log('maintenance', 'pm_schedule', "PM schedule \"{$s->title}\" created – {$s->frequency()}, next due {$s->next_due->format('d M Y')}", $s);

        return back()->with('success', "Schedule \"{$s->title}\" created.");
    }

    public function update(Request $request, PmSchedule $pmsched)
    {
        $pmsched->update($this->validated($request, $pmsched));
        ActivityLogger::log('maintenance', 'pm_schedule', "PM schedule \"{$pmsched->title}\" updated", $pmsched);

        return back()->with('success', 'Schedule updated.');
    }

    public function toggle(PmSchedule $pmsched)
    {
        if (! $pmsched->is_active && $pmsched->asset?->isDisposed()) {
            return back()->with('error', 'The asset has been disposed.');
        }
        $pmsched->forceFill(['is_active' => ! $pmsched->is_active])->save();

        return back()->with('success', "Schedule \"{$pmsched->title}\" ".($pmsched->is_active ? 'resumed' : 'paused').'.');
    }

    public function destroy(PmSchedule $pmsched)
    {
        if ($pmsched->tickets()->exists()) {
            $pmsched->forceFill(['is_active' => false])->save();

            return back()->with('warning', 'The schedule has ticket history, so it was paused instead of deleted.');
        }
        $pmsched->delete();

        return back()->with('success', 'Schedule deleted.');
    }

    public function generate()
    {
        $made = PmService::generate();

        return back()->with($made ? 'success' : 'warning', $made ? count($made).' preventive-maintenance ticket(s) raised: '.collect($made)->pluck('ticket_no')->implode(', ').'.' : 'Nothing is due yet – no tickets raised.');
    }

    protected function validated(Request $request, ?PmSchedule $s = null): array
    {
        $d = $request->validate([
            'title' => 'required|string|max:150', 'asset_id' => 'nullable|integer|owned:assets', 'room_id' => 'nullable|integer|owned:rooms', 'location' => 'nullable|string|max:120',
            'category' => ['required', Rule::in(array_keys(self::CATEGORIES))], 'priority' => 'required|in:low,normal,high,urgent', 'checklist' => 'nullable|string|max:3000',
            'every' => 'required|integer|min:1|max:365', 'every_unit' => ['required', Rule::in(array_keys(PmSchedule::UNITS))], 'next_due' => 'required|date',
            'lead_days' => 'required|integer|min:0|max:90', 'employee_id' => 'nullable|integer|owned:employees', 'vendor' => 'nullable|string|max:120',
        ]);
        if (empty($d['asset_id']) && empty($d['room_id']) && empty($d['location'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['asset_id' => 'Pick the asset, room or location this schedule covers.']);
        }
        if (! empty($d['asset_id']) && Asset::find($d['asset_id'])?->isDisposed()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['asset_id' => 'That asset has been disposed.']);
        }

        return $d;
    }
}
