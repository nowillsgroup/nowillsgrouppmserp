<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

class HrController extends Controller
{
    public function attendance(Request $request)
    {
        $date = $request->input('date', bdate()->toDateString());
        $employees = Employee::with(['department', 'attendances' => fn ($q) => $q->where('date', $date)->with('leaveRequest.leaveType')])->where('is_active', true)
            ->when($request->department, fn ($q, $d) => $q->where('department_id', $d))->orderBy('name')->get();
        $month = Carbon::parse($date);
        $summary = Attendance::whereYear('date', $month->year)->whereMonth('date', $month->month)->get()->groupBy('employee_id')->map(fn ($g) => $g->countBy('status'));

        return view('hr.attendance', ['employees' => $employees, 'date' => $date, 'summary' => $summary, 'departments' => Department::pluck('name', 'id'),
            'locked' => PayrollService::lockedRunFor($date)]);
    }

    public function saveAttendance(Request $request)
    {
        $data = $request->validate(['date' => 'required|date', 'att' => 'required|array', 'att.*.status' => 'required|in:P,A,L,H,WO', 'att.*.in_time' => 'nullable|date_format:H:i', 'att.*.out_time' => 'nullable|date_format:H:i', 'att.*.remarks' => 'nullable|string|max:120']);
        try {
            PayrollService::assertOpen($data['date']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $skipped = 0;
        foreach ($data['att'] as $empId => $row) {
            if (! Employee::whereKey($empId)->exists()) {
                continue;
            }
            $existing = Attendance::where('employee_id', $empId)->where('date', $data['date'])->first();
            if ($existing?->leave_request_id) {
                // approved leave owns the day: only the clock times of a half day may change here
                if ($existing->status === 'H') {
                    $existing->update(['in_time' => $row['in_time'] ?? null, 'out_time' => $row['out_time'] ?? null]);
                }
                $skipped++;

                continue;
            }
            Attendance::updateOrCreate(['employee_id' => $empId, 'date' => $data['date']], $row);
        }

        return back()->with('success', 'Attendance saved for '.dmy($data['date']).($skipped ? " ({$skipped} on approved leave kept – cancel the leave to change them)" : ''));
    }

    /** Month grid: one row per employee, one column per day, with the payroll view of paid days / LOP / OT. */
    public function register(Request $request)
    {
        $month = Carbon::createFromFormat('Y-m-d', ($request->input('month') ?: bdate()->format('Y-m')).'-01')->startOfDay();
        $end = $month->copy()->endOfMonth()->startOfDay();
        $employees = Employee::with('department')->where(fn ($q) => $q->where('is_active', true)->orWhereDate('leaving_date', '>=', $month->toDateString()))
            ->when($request->department, fn ($q, $d) => $q->where('department_id', $d))->orderBy('emp_code')->get();
        $att = Attendance::whereIn('employee_id', $employees->pluck('id'))->whereBetween('date', [$month->toDateString(), $end->toDateString()])->get()
            ->groupBy('employee_id')->map(fn ($g) => $g->keyBy(fn ($a) => $a->date->day));
        $until = $end->lt(bdate()) ? $end : bdate()->copy();
        $summary = $employees->mapWithKeys(fn ($e) => [$e->id => $month->lte($until) ? PayrollService::attendanceSummary($e, $month, $until) : null]);

        if ($request->input('export') === 'csv') {
            return response()->streamDownload(function () use ($employees, $att, $month, $summary) {
                $out = fopen('php://output', 'w');
                fputcsv($out, array_merge(['Code', 'Employee'], range(1, $month->daysInMonth), ['Paid days', 'LOP', 'OT hours']));
                foreach ($employees as $e) {
                    $row = [$e->emp_code, $e->name];
                    for ($d = 1; $d <= $month->daysInMonth; $d++) {
                        $row[] = $att[$e->id][$d]->status ?? '';
                    }
                    $s = $summary[$e->id];
                    fputcsv($out, array_merge($row, [$s['paid'] ?? '', $s['lop'] ?? '', $s['ot_hours'] ?? '']));
                }
                fclose($out);
            }, 'attendance-'.$month->format('Y-m').'.csv', ['Content-Type' => 'text/csv']);
        }

        return view('hr.register', ['employees' => $employees, 'att' => $att, 'month' => $month, 'summary' => $summary, 'until' => $until,
            'departments' => Department::pluck('name', 'id'), 'locked' => PayrollService::lockedRunFor($month->toDateString())]);
    }
}
