<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveService;
use Illuminate\Http\Request;
use RuntimeException;

class LeaveController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->input('status', 'pending');
        $list = LeaveRequest::with('employee.department', 'leaveType', 'decider', 'requester')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($request->employee_id, fn ($q, $e) => $q->where('employee_id', $e))
            ->orderByRaw("status = 'pending' DESC")->orderByDesc('date_from')->paginate(30)->withQueryString();
        $year = (int) $request->input('year', bdate()->year);
        $types = LeaveType::where('is_active', true)->orderBy('id')->get();
        $employees = Employee::with('department')->where('is_active', true)->orderBy('name')->get();
        $balances = $request->input('tab') === 'balances'
            ? $employees->mapWithKeys(fn ($e) => [$e->id => LeaveService::balances($e, $year)->keyBy(fn ($b) => $b['type']->id)]) : collect();
        $onLeave = LeaveRequest::with('employee', 'leaveType')->where('status', 'approved')
            ->whereDate('date_from', '<=', bdate()->toDateString())->whereDate('date_to', '>=', bdate()->toDateString())->get();

        return view('hr.leave', ['list' => $list, 'status' => $status, 'types' => $types, 'employees' => $employees, 'balances' => $balances, 'year' => $year,
            'onLeave' => $onLeave, 'counts' => LeaveRequest::selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status')]);
    }

    public function store(Request $request)
    {
        $d = $request->validate(['employee_id' => 'required|integer|owned:employees', 'leave_type_id' => 'required|integer|owned:leave_types',
            'date_from' => 'required|date', 'date_to' => 'required|date', 'half_day' => 'nullable|boolean', 'reason' => 'nullable|string|max:255', 'approve' => 'nullable|boolean']);
        try {
            $r = LeaveService::request(Employee::find($d['employee_id']), LeaveType::find($d['leave_type_id']), $d['date_from'], $d['date_to'], $request->boolean('half_day'), $d['reason'] ?? null);
            if ($request->boolean('approve')) {
                LeaveService::approve($r, 'Entered & approved by HR');
            }
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Leave '.($request->boolean('approve') ? 'recorded and approved' : 'request created').": {$r->employee->name}, {$r->leaveType->code} {$r->periodLabel()}.");
    }

    public function approve(Request $request, LeaveRequest $lreq)
    {
        return $this->act(fn () => LeaveService::approve($lreq, $request->input('note')), "Leave approved for {$lreq->employee->name}; attendance updated.");
    }

    public function reject(Request $request, LeaveRequest $lreq)
    {
        $d = $request->validate(['note' => 'required|string|max:255']);

        return $this->act(fn () => LeaveService::reject($lreq, $d['note']), "Leave rejected for {$lreq->employee->name}.");
    }

    public function cancel(Request $request, LeaveRequest $lreq)
    {
        return $this->act(fn () => LeaveService::cancel($lreq, $request->input('note')), 'Leave cancelled'.($lreq->status === 'approved' ? '; attendance restored.' : '.'));
    }

    protected function act(callable $fn, string $ok)
    {
        try {
            $fn();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $ok);
    }
}
