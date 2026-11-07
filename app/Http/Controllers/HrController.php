<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\HrDocument;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\EmployeeSelfService;
use App\Services\HolidayService;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class HrController extends Controller
{
    /**
     * Human Resources › Settings — the policy the HR desk works to.
     *
     * Two rules that were invisible until they bit somebody: how long a leave request may
     * sit unanswered before the nightly HR run escalates it, and whether leave is counted
     * in working days (Act 651 s.20/s.22 — the default) or in calendar days.
     */
    public function settings()
    {
        return view('hr.settings', [
            'c' => [
                'hr_leave_sla_hours' => (string) setting('hr_leave_sla_hours', '24'),
                'leave_count_all_days' => (string) setting('leave_count_all_days', '0'),
            ],
        ]);
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'hr_leave_sla_hours' => 'required|integer|min:1|max:168',
            'leave_count_all_days' => 'nullable|in:0,1',
        ]);
        $data['leave_count_all_days'] = $request->boolean('leave_count_all_days') ? '1' : '0';

        foreach ($data as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
        }
        ActivityLogger::log('settings', 'hr', 'Updated HR leave policy settings');

        return redirect()->route('hr.settings')->with('success', 'HR settings saved.');
    }

    public function attendance(Request $request)
    {
        $date = $request->input('date', bdate()->toDateString());
        $employees = Employee::with(['department', 'attendances' => fn ($q) => $q->whereDate('date', $date)->with('leaveRequest.leaveType')])->where('is_active', true)
            ->when($request->department, fn ($q, $d) => $q->where('department_id', $d))->orderBy('name')->get();
        $month = Carbon::parse($date);
        $summary = Attendance::whereYear('date', $month->year)->whereMonth('date', $month->month)->get()->groupBy('employee_id')->map(fn ($g) => $g->countBy('status'));

        return view('hr.attendance', ['employees' => $employees, 'date' => $date, 'summary' => $summary, 'departments' => Department::pluck('name', 'id'),
            'locked' => PayrollService::lockedRunFor($date), 'holiday' => HolidayService::on($date), 'nextHoliday' => HolidayService::upcoming((int) HolidayService::cfg('hr_holiday_notify_days'))->first()]);
    }

    public function saveAttendance(Request $request)
    {
        $data = $request->validate(['date' => 'required|date', 'att' => 'required|array', 'att.*.status' => 'required|in:P,A,L,H,WO,PH', 'att.*.in_time' => 'nullable|date_format:H:i', 'att.*.out_time' => 'nullable|date_format:H:i', 'att.*.remarks' => 'nullable|string|max:120']);
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
            $existing = Attendance::where('employee_id', $empId)->whereDate('date', $data['date'])->first();
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
        $att = Attendance::whereIn('employee_id', $employees->pluck('id'))->whereDate('date', '>=', $month->toDateString())->whereDate('date', '<=', $end->toDateString())->get()
            ->groupBy('employee_id')->map(fn ($g) => $g->keyBy(fn ($a) => $a->date->day));
        $until = $end->lt(bdate()) ? $end : bdate()->copy();
        $summary = $employees->mapWithKeys(fn ($e) => [$e->id => $month->lte($until) ? PayrollService::attendanceSummary($e, $month, $until) : null]);

        if ($request->input('export') === 'csv') {
            $holidays = HolidayService::inRange($month, $end);

            return response()->streamDownload(function () use ($employees, $att, $month, $summary, $holidays) {
                $out = fopen('php://output', 'w');
                fputcsv($out, array_merge(['Code', 'Employee'], range(1, $month->daysInMonth), ['Paid days', 'LOP', 'Public holidays', 'OT hours', 'Holiday hours']));
                foreach ($employees as $e) {
                    $row = [$e->emp_code, $e->name];
                    for ($d = 1; $d <= $month->daysInMonth; $d++) {
                        $row[] = $att[$e->id][$d]->status ?? (isset($holidays[$month->copy()->setDay($d)->toDateString()]) ? 'PH' : '');
                    }
                    $s = $summary[$e->id];
                    fputcsv($out, array_merge($row, [$s['paid'] ?? '', $s['lop'] ?? '', $s['holiday'] ?? '', $s['ot_hours'] ?? '', $s['holiday_hours'] ?? '']));
                }
                fclose($out);
            }, 'attendance-'.$month->format('Y-m').'.csv', ['Content-Type' => 'text/csv']);
        }

        return view('hr.register', ['employees' => $employees, 'att' => $att, 'month' => $month, 'summary' => $summary, 'until' => $until,
            'departments' => Department::pluck('name', 'id'), 'locked' => PayrollService::lockedRunFor($month->toDateString()),
            'holidays' => HolidayService::inRange($month, $end)]);
    }

    // ───────────── the employee file: photograph and papers ─────────────

    /**
     * One person's file. Until now HR could see a name in a register and nothing else — no
     * photograph for a roster or a door pass, and no way to load a signed contract on a
     * member of staff's behalf; only the member of staff could send paper in.
     */
    public function employee(Employee $employee)
    {
        $employee->load('department');
        $month = bdate()->copy()->startOfMonth();
        $attendance = Attendance::where('employee_id', $employee->id)->whereDate('date', '>=', $month->toDateString())
            ->whereDate('date', '<=', bdate()->toDateString())->get();

        return view('hr.employee', [
            'e' => $employee,
            'docs' => $employee->documents()->get(),
            'kinds' => EmployeeSelfService::DOC_KINDS,
            'photoUrl' => $employee->photoUrl(),
            'month' => $month,
            'monthStatus' => $attendance->countBy('status'),
        ]);
    }

    public function savePhoto(Request $request, Employee $employee)
    {
        EmployeeSelfService::savePhoto($employee, $request->file('photo'), Auth::user());

        return back()->with('success', "Photograph saved for {$employee->name}.");
    }

    public function removePhoto(Employee $employee)
    {
        EmployeeSelfService::removePhoto($employee, Auth::user());

        return back()->with('success', "The photograph of {$employee->name} was removed.");
    }

    public function employeePhoto(Employee $employee)
    {
        abort_unless($employee->photo_path && Storage::disk('local')->exists($employee->photo_path), 404);

        return Storage::disk('local')->response($employee->photo_path, null, ['Cache-Control' => 'private, max-age=300']);
    }

    public function uploadDocument(Request $request, Employee $employee)
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'kind' => ['nullable', 'string'],
            'file' => ['required', 'file'],
        ]);
        EmployeeSelfService::uploadOnBehalf($employee, $request->file('file'), (string) ($data['title'] ?? ''), $data['kind'] ?? null, Auth::user());

        return back()->with('success', 'Document loaded into the employee file.');
    }

    public function document(HrDocument $doc)
    {
        abort_unless($doc->file_path && Storage::disk('local')->exists($doc->file_path), 404);
        $name = str_replace(['/', '\\'], '-', $doc->title).'.'.pathinfo($doc->file_path, PATHINFO_EXTENSION);

        return $doc->isImage()
            ? Storage::disk('local')->response($doc->file_path, null, ['Cache-Control' => 'private, max-age=300'])
            : Storage::disk('local')->download($doc->file_path, $name);
    }

    public function deleteDocument(HrDocument $doc)
    {
        EmployeeSelfService::deleteDocument($doc, Auth::user());

        return back()->with('success', 'The document was removed from the file.');
    }

    // ───────────── v7.43 public holidays (Act 601 as amended by Act 1142) ─────────────

    /**
     * The gazetted calendar the roster, the attendance register, leave and payroll all read: a public holiday is a paid
     * day (Act 651 s. 72), it does not use annual leave (s. 22), it is not a rest period (s. 43) and a temporary or
     * casual worker who works on it earns the day again for the hours worked (s. 77).
     */
    public function holidays(Request $request)
    {
        $years = HolidayService::years();
        $year = (int) $request->input('year', bdate()->format('Y'));
        if (! in_array($year, $years, true)) {
            $year = (int) bdate()->format('Y');
        }
        $rows = HolidayService::list($year);
        $worked = Attendance::where('status', 'PH')->whereYear('date', $year)->whereNotNull('in_time')
            ->with('employee')->orderBy('date')->get();

        return view('hr.holidays', ['year' => $year, 'years' => $years, 'rows' => $rows, 'worked' => $worked,
            'custom' => HolidayService::isCustom($year), 'upcoming' => HolidayService::upcoming(60),
            'multiplier' => (float) HolidayService::cfg('payroll_holiday_multiplier'),
            'notifyDays' => (int) HolidayService::cfg('hr_holiday_notify_days'),
            'shiftHours' => (float) setting('payroll_shift_hours', '9')]);
    }

    public function saveHolidays(Request $request)
    {
        $d = $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
            'date' => 'array|max:80', 'date.*' => 'nullable|date_format:Y-m-d',
            'name' => 'array|max:80', 'name.*' => 'nullable|string|max:90',
            'commemorative' => 'array|max:80', 'commemorative.*' => 'nullable',
            'payroll_holiday_multiplier' => 'nullable|numeric|min:1|max:4',
            'hr_holiday_notify_days' => 'nullable|integer|min:0|max:60',
        ]);
        try {
            $rows = [];
            foreach ($d['date'] as $i => $date) {
                $rows[] = ['date' => $date, 'name' => $d['name'][$i] ?? '', 'commemorative' => ! empty($d['commemorative'][$i])];
            }
            $n = HolidayService::save((int) $d['year'], $rows);
            foreach (['payroll_holiday_multiplier' => '1', 'hr_holiday_notify_days' => HolidayService::DEFAULTS['hr_holiday_notify_days']] as $k => $def) {
                if (isset($d[$k]) && $d[$k] !== '') {
                    \App\Models\Setting::updateOrCreate(['key' => $k], ['value' => (string) $d[$k]]);
                }
            }
            \App\Models\Setting::$version++;
            ActivityLogger::log('settings', 'hr', "Holiday calendar for {$d['year']} saved ({$n} day(s))".
                (isset($d['payroll_holiday_multiplier']) && $d['payroll_holiday_multiplier'] !== '' ? ", holidays paid at {$d['payroll_holiday_multiplier']}×" : ''));
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$n} calendar day(s) saved for {$d['year']}. Draft payroll runs and new leave requests pick them up at once; approved months keep the calendar they were run on.");
    }

    public function resetHolidays(Request $request)
    {
        $d = $request->validate(['year' => 'required|integer|min:2000|max:2100']);
        HolidayService::reset((int) $d['year']);

        return back()->with('success', "The {$d['year']} calendar is back to the gazetted Ghana public holidays.");
    }
}
