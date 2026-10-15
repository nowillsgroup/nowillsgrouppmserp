<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Payslip;
use App\Services\LeaveService;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use RuntimeException;

/** Staff self-service: own payslips (approved runs only), leave balances & requests, attendance. */
class SelfServiceController extends Controller
{
    protected function me(): Employee
    {
        $e = Employee::where('user_id', auth()->id())->where('is_active', true)->first();
        abort_unless($e, 404, 'Your login is not linked to an employee record – ask HR to link it.');

        return $e;
    }

    public function index()
    {
        $e = Employee::with('department')->where('user_id', auth()->id())->where('is_active', true)->first();
        if (! $e) {
            return view('me.unlinked', ['title' => 'My Payslips & Leave']);
        }
        $month = bdate()->copy()->startOfMonth();

        return view('me.hr', ['e' => $e, 'balances' => LeaveService::balances($e), 'types' => LeaveType::where('is_active', true)->orderBy('id')->get(),
            'requests' => $e->leaveRequests()->with('leaveType', 'decider')->orderByDesc('date_from')->limit(20)->get(),
            'slips' => Payslip::with('run')->where('employee_id', $e->id)->whereHas('run', fn ($q) => $q->whereIn('status', ['approved', 'paid']))->latest('id')->limit(12)->get(),
            'advances' => $e->advances()->whereNull('cancelled_at')->orderByDesc('date')->get()->filter(fn ($a) => $a->outstanding() > 0),
            'att' => PayrollService::attendanceSummary($e, $month, bdate()->copy()),
            'recent' => Attendance::where('employee_id', $e->id)->whereDate('date', '>=', bdate()->copy()->subDays(13)->toDateString())->orderByDesc('date')->get(),
            'month' => $month]);
    }

    public function apply(Request $request)
    {
        $e = $this->me();
        $d = $request->validate(['leave_type_id' => 'required|integer|owned:leave_types', 'date_from' => 'required|date', 'date_to' => 'required|date',
            'half_day' => 'nullable|boolean', 'reason' => 'required|string|max:255']);
        if ($d['date_from'] < bdate()->copy()->subDays(7)->toDateString()) {
            return back()->withInput()->with('error', 'Leave more than 7 days in the past must be entered by HR.');
        }
        try {
            $r = LeaveService::request($e, LeaveType::find($d['leave_type_id']), $d['date_from'], $d['date_to'], $request->boolean('half_day'), $d['reason']);
        } catch (RuntimeException $ex) {
            return back()->withInput()->with('error', $ex->getMessage());
        }

        return back()->with('success', "Leave request sent for approval: {$r->leaveType->name}, {$r->periodLabel()}.");
    }

    public function cancel(LeaveRequest $lreq)
    {
        abort_unless((int) $lreq->employee_id === $this->me()->id, 403);
        if ($lreq->status !== 'pending') {
            return back()->with('error', 'Only a pending request can be withdrawn – ask HR to cancel approved leave.');
        }
        LeaveService::cancel($lreq, 'Withdrawn by employee');

        return back()->with('success', 'Leave request withdrawn.');
    }

    public function payslip(Payslip $slip)
    {
        abort_unless((int) $slip->employee_id === $this->me()->id && in_array($slip->run->status, ['approved', 'paid'], true), 403);
        $slip->load('run', 'employee.department', 'lines', 'paymentMode');

        return view('payroll.payslip', ['slips' => collect([$slip]), 'run' => $slip->run, 'self' => true]);
    }

    // ───────────────────────── v4.9 roster & time clock ─────────────────────────

    public function roster(Request $request)
    {
        $e = Employee::with('department')->where('user_id', auth()->id())->where('is_active', true)->first();
        if (! $e) {
            return view('me.unlinked', ['title' => 'My Roster & Time Clock']);
        }
        $today = now()->toDateString();
        $from = now()->subDays(6)->toDateString();
        $to = now()->addDays(20)->toDateString();
        $shifts = \App\Services\RosterService::myShifts($e, $from, $to)->keyBy(fn ($s) => $s->date->toDateString());
        $days = collect(\Carbon\CarbonPeriod::create($from, $to))->map(fn ($d) => $d->toDateString());
        $swapIds = \App\Models\ShiftSwap::where(fn ($q) => $q->where('requester_id', $e->id)->orWhere('colleague_id', $e->id));

        return view('me.roster', ['e' => $e, 'today' => $today, 'days' => $days, 'shifts' => $shifts,
            'open' => \App\Services\TimeClockService::openPunch($e),
            'punches' => \App\Models\TimePunch::with('shift')->where('employee_id', $e->id)->whereDate('work_date', '>=', now()->subDays(13)->toDateString())->orderByDesc('clock_in')->get(),
            'selfOn' => setting('timeclock_self', '1') === '1', 'ipOk' => \App\Services\TimeClockService::ipAllowed($request->ip()),
            'swaps' => (clone $swapIds)->with('requester', 'colleague', 'shift', 'colleagueShift', 'decider')->latest('id')->limit(15)->get(),
            'colleagues' => Employee::where('department_id', $e->department_id)->where('is_active', true)->whereKeyNot($e->id)->orderBy('name')->pluck('name', 'id'),
            'swappable' => $shifts->filter(fn ($s) => $s->isShift() && $s->startsAt()?->gt(now()->addHours((int) setting('roster_swap_notice_hours', '12')))),
            'hasPin' => (bool) $e->clock_pin]);
    }

    public function clock(Request $request)
    {
        $e = $this->me();
        if (setting('timeclock_self', '1') !== '1') {
            return back()->with('error', 'Self-service clocking is switched off – use the time-clock kiosk.');
        }
        if (! \App\Services\TimeClockService::ipAllowed($request->ip())) {
            return back()->with('error', 'You can only clock in from the hotel network ('.$request->ip().' is not allowed) – use the kiosk.');
        }
        try {
            [$action, $p] = \App\Services\TimeClockService::toggle($e, 'self', $request->ip());
        } catch (RuntimeException $ex) {
            return back()->with('error', $ex->getMessage());
        }

        return back()->with('success', $action === 'in' ? 'Clocked in at '.$p->clock_in->format('H:i').($p->late_minutes ? " – {$p->late_minutes} min late" : '').'.'
            : 'Clocked out at '.$p->clock_out->format('H:i').' – '.number_format($p->hours(), 2).' h worked.');
    }

    public function requestSwap(Request $request)
    {
        $e = $this->me();
        $d = $request->validate(['roster_shift_id' => 'required|integer', 'colleague_id' => 'required|integer', 'reason' => 'nullable|string|max:255']);
        $shift = \App\Models\RosterShift::where('employee_id', $e->id)->findOrFail($d['roster_shift_id']);
        $col = Employee::where('is_active', true)->findOrFail($d['colleague_id']);
        try {
            \App\Services\RosterService::requestSwap($e, $shift, $col, $d['reason'] ?? null);
        } catch (RuntimeException $ex) {
            return back()->withInput()->with('error', $ex->getMessage());
        }

        return back()->with('success', "Swap request sent to {$col->name}. Once they accept, a manager approves it.");
    }

    public function respondSwap(Request $request, \App\Models\ShiftSwap $swap)
    {
        $e = $this->me();
        $d = $request->validate(['accept' => 'required|boolean']);
        try {
            \App\Services\RosterService::peerRespond($swap, $e, (bool) $d['accept']);
        } catch (RuntimeException $ex) {
            return back()->with('error', $ex->getMessage());
        }

        return back()->with('success', $d['accept'] ? 'Accepted – waiting for a manager to approve.' : 'Swap declined.');
    }

    public function cancelSwap(\App\Models\ShiftSwap $swap)
    {
        $e = $this->me();
        try {
            \App\Services\RosterService::cancelSwap($swap, $e);
        } catch (RuntimeException $ex) {
            return back()->with('error', $ex->getMessage());
        }

        return back()->with('success', 'Swap request withdrawn.');
    }

    public function pin(Request $request)
    {
        $e = $this->me();
        $d = $request->validate(['password' => 'required|string', 'pin' => 'required|string|confirmed']);
        if (! \Illuminate\Support\Facades\Hash::check($d['password'], auth()->user()->password)) {
            return back()->with('error', 'Your login password is not correct.');
        }
        try {
            \App\Services\TimeClockService::setPin($e, $d['pin']);
        } catch (RuntimeException $ex) {
            return back()->with('error', $ex->getMessage());
        }

        return back()->with('success', 'Kiosk PIN changed.');
    }
}
