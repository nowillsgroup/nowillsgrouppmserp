<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\ShiftSwap;
use App\Models\ShiftTemplate;
use App\Models\TimePunch;
use App\Services\ActivityLogger;
use App\Services\RosterService;
use App\Services\TimeClockService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use RuntimeException;

/** v4.9 Duty roster, shift templates & swaps; time-clock day board, corrections, approval and the shared kiosk. */
class WorkforceController extends Controller
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

    protected function dept(Request $request): ?int
    {
        $d = (int) $request->input('dept');
        if ($d && Department::whereKey($d)->exists()) {
            return $d;
        }
        $mine = Employee::where('user_id', auth()->id())->value('department_id');

        return $mine ?: Department::whereHas('employees', fn ($q) => $q->where('is_active', true))->orderBy('id')->value('id');
    }

    // ───────────────────────── roster ─────────────────────────

    public function roster(Request $request)
    {
        $dept = $this->dept($request);
        $week = RosterService::weekStart($request->input('week'));

        return view('workforce.roster', ['dept' => $dept, 'week' => $week, 'departments' => Department::orderBy('name')->pluck('name', 'id'),
            'g' => $dept ? RosterService::grid($dept, $week) : null, 'allTemplates' => ShiftTemplate::with('department')->orderBy('start_time')->get(),
            'swaps' => ShiftSwap::with('requester', 'colleague', 'shift', 'colleagueShift', 'decider')->where(fn ($q) => $q->whereIn('status', ShiftSwap::OPEN)->orWhere('updated_at', '>=', now()->subDays(14)))
                ->latest('id')->limit(40)->get(),
            'canManage' => can_do('roster.manage')]);
    }

    public function saveRoster(Request $request)
    {
        $d = $request->validate(['dept' => 'required|integer', 'week' => 'required|date', 'cells' => 'required|array']);
        $dept = $this->dept($request);
        abort_unless($dept === (int) $d['dept'], 404);
        $r = RosterService::saveWeek($dept, RosterService::weekStart($d['week']), $d['cells'], auth()->user());

        return back()->with($r['saved'] ? 'success' : 'warning', $r['saved'] ? "Roster saved – {$r['saved']} change(s)".($r['skipped'] ? ", {$r['skipped']} locked day(s) skipped (past dates or approved leave)." : '.') : 'No changes to save'.($r['skipped'] ? " – {$r['skipped']} locked day(s) skipped." : '.'));
    }

    public function copyRoster(Request $request)
    {
        $d = $request->validate(['dept' => 'required|integer', 'week' => 'required|date', 'from_week' => 'required|date']);
        $dept = $this->dept($request);

        return $this->attempt(fn () => RosterService::copyWeek($dept, RosterService::weekStart($d['from_week']), RosterService::weekStart($d['week']), auth()->user()),
            fn ($n) => "Copied {$n} shift(s) from the week of ".dmy(RosterService::weekStart($d['from_week'])).' – review and publish.');
    }

    public function publishRoster(Request $request)
    {
        $d = $request->validate(['dept' => 'required|integer', 'week' => 'required|date']);
        $dept = $this->dept($request);

        return $this->attempt(fn () => RosterService::publish($dept, RosterService::weekStart($d['week']), auth()->user()), 'Roster published – staff can see it in self-service.');
    }

    public function printRoster(Request $request)
    {
        $dept = $this->dept($request);
        $week = RosterService::weekStart($request->input('week'));
        abort_unless($dept, 404);

        return view('workforce.roster-print', ['dept' => Department::find($dept), 'week' => $week, 'g' => RosterService::grid($dept, $week)]);
    }

    public function saveTemplate(Request $request)
    {
        $d = $request->validate(['id' => 'nullable|integer', 'code' => 'required|string|max:8|regex:/^[A-Za-z0-9]+$/', 'name' => 'required|string|max:60',
            'department_id' => 'nullable|integer|exists:departments,id', 'start_time' => 'required|date_format:H:i', 'end_time' => 'required|date_format:H:i|different:start_time',
            'break_minutes' => 'required|integer|min:0|max:240', 'min_staff' => 'nullable|integer|min:0|max:50', 'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        $d['code'] = strtoupper($d['code']);
        if (strtoupper($d['code']) === 'OFF') {
            return back()->withInput()->with('error', '"OFF" is reserved for days off.');
        }
        $t = ! empty($d['id']) ? ShiftTemplate::findOrFail($d['id']) : new ShiftTemplate;
        if (ShiftTemplate::where('code', $d['code'])->when($t->exists, fn ($q) => $q->whereKeyNot($t->id))->exists()) {
            return back()->withInput()->with('error', "Shift code {$d['code']} is already used.");
        }
        if (\App\Models\RosterShift::span($d['start_time'], $d['end_time']) - $d['break_minutes'] < 60) {
            return back()->withInput()->with('error', 'The shift must have at least one paid hour after the break.');
        }
        $t->fill(collect($d)->except('id')->all() + ['min_staff' => (int) ($d['min_staff'] ?? 0)])->save();
        ActivityLogger::log('roster', 'template', "Shift template {$t->code} {$t->label()} saved", $t);

        return back()->with('success', "Shift {$t->code} saved. Existing rostered shifts keep their times.");
    }

    public function toggleTemplate(ShiftTemplate $stpl)
    {
        $stpl->update(['is_active' => ! $stpl->is_active]);

        return back()->with('success', "Shift {$stpl->code} ".($stpl->is_active ? 'activated' : 'retired').'.');
    }

    public function decideSwap(Request $request, ShiftSwap $swap)
    {
        $d = $request->validate(['decision' => 'required|in:approve,reject', 'note' => 'nullable|string|max:255']);

        return $this->attempt(fn () => RosterService::decide($swap, $d['decision'] === 'approve', $d['note'] ?? null, auth()->user()),
            $d['decision'] === 'approve' ? 'Swap approved – the roster has been updated.' : 'Swap refused.');
    }

    public function rosterSettings(Request $request)
    {
        $d = $request->validate(['roster_min_rest_hours' => 'required|numeric|min:0|max:24', 'roster_max_days' => 'required|integer|min:1|max:14',
            'roster_week_hours' => 'required|numeric|min:1|max:84', 'roster_swap_notice_hours' => 'required|integer|min:0|max:168']);
        foreach ($d as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => (string) $v]);
        }
        Setting::$version++;
        ActivityLogger::log('roster', 'settings', 'Roster rules updated');

        return back()->with('success', 'Roster rules saved.');
    }

    // ───────────────────────── time clock ─────────────────────────

    public function clock(Request $request)
    {
        $date = Carbon::parse($request->input('date', now()->toDateString()))->toDateString();
        $dept = $request->filled('dept') ? $this->dept($request) : null;
        $rows = TimeClockService::board($date, $dept);

        return view('workforce.clock', ['date' => $date, 'dept' => $dept, 'rows' => $rows, 'departments' => Department::orderBy('name')->pluck('name', 'id'),
            'employees' => Employee::where('is_active', true)->orderBy('name')->get(['id', 'name', 'emp_code', 'clock_pin', 'department_id']),
            'stale' => TimePunch::with('employee')->where('status', 'open')->where('clock_in', '<', now()->subHours(16))->orderBy('clock_in')->get(),
            'pendingDays' => TimePunch::where('status', 'closed')->whereDate('work_date', '<', now()->toDateString())->selectRaw('work_date, COUNT(*) n')->groupBy('work_date')->orderByDesc('work_date')->limit(10)->pluck('n', 'work_date'),
            'canApprove' => can_do('timeclock.approve')]);
    }

    public function savePunch(Request $request, ?TimePunch $punch = null)
    {
        $d = $request->validate(['employee_id' => ($punch?->exists ? 'nullable' : 'required').'|integer', 'in' => 'required|date', 'out' => 'nullable|date', 'reason' => 'required|string|max:255']);
        $e = $punch?->exists ? $punch->employee : Employee::findOrFail($d['employee_id']);

        return $this->attempt(fn () => TimeClockService::managerSave($e, $d['in'], $d['out'] ?? null, $d['reason'], auth()->user(), $punch?->exists ? $punch : null),
            "Time punch for {$e->name} saved".($punch?->status === 'approved' ? ' – approve the day again to update attendance.' : '.'));
    }

    public function updatePunch(Request $request, TimePunch $punch)
    {
        return $this->savePunch($request, $punch);
    }

    public function deletePunch(Request $request, TimePunch $punch)
    {
        $d = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => TimeClockService::deletePunch($punch, $d['reason'], auth()->user()), 'Punch deleted.');
    }

    public function approveDay(Request $request)
    {
        $d = $request->validate(['date' => 'required|date', 'dept' => 'nullable|integer']);
        $dept = ! empty($d['dept']) ? $this->dept($request) : null;
        try {
            $r = TimeClockService::approveDay(Carbon::parse($d['date'])->toDateString(), $dept, auth()->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($r['blocked'] ? 'warning' : 'success', "Attendance updated for {$r['approved']} employee(s) on ".dmy($d['date']).'.'
            .($r['blocked'] ? ' Still clocked in (close these punches first): '.implode(', ', $r['blocked']).'.' : ''));
    }

    public function setPin(Request $request, Employee $employee)
    {
        $d = $request->validate(['pin' => 'required|string']);

        return $this->attempt(fn () => TimeClockService::setPin($employee, $d['pin']), "Kiosk PIN set for {$employee->name}.");
    }

    public function clockSettings(Request $request)
    {
        $d = $request->validate(['timeclock_grace_min' => 'required|integer|min:0|max:60', 'timeclock_early_min' => 'required|integer|min:0|max:480',
            'timeclock_break_min' => 'required|integer|min:0|max:120', 'timeclock_self_ips' => ['nullable', 'string', 'max:500', 'regex:/^[0-9a-fA-F:.\/,\s]*$/']]);
        $d['timeclock_self'] = $request->boolean('timeclock_self') ? '1' : '0';
        $d['timeclock_unrostered'] = $request->boolean('timeclock_unrostered') ? '1' : '0';
        foreach ($d as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => (string) ($v ?? '')]);
        }
        Setting::$version++;
        ActivityLogger::log('timeclock', 'settings', 'Time clock settings updated');

        return back()->with('success', 'Time clock settings saved.');
    }

    // ───────────────────────── kiosk ─────────────────────────

    public function kiosk()
    {
        return view('workforce.kiosk', ['last' => session('kiosk_last')]);
    }

    public function kioskPunch(Request $request)
    {
        $d = $request->validate(['code' => 'required|string|max:30', 'pin' => 'required|string|max:6']);
        try {
            $e = TimeClockService::kioskEmployee($d['code'], $d['pin']);
            [$action, $p] = TimeClockService::toggle($e, 'kiosk', $request->ip());
        } catch (RuntimeException $ex) {
            return back()->with('error', $ex->getMessage());
        }
        $shift = $p->shift;
        $msg = $action === 'in'
            ? "Welcome, {$e->name} – clocked in at ".$p->clock_in->format('H:i').($shift ? " for {$shift->label()}" : ' (not rostered)').($p->late_minutes ? " · {$p->late_minutes} min late" : '')
            : "Goodbye, {$e->name} – clocked out at ".$p->clock_out->format('H:i').' · '.number_format($p->hours(), 2).' h worked';

        return back()->with('success', $msg)->with('kiosk_last', ['action' => $action, 'name' => $e->name]);
    }
}
