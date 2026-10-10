<?php

namespace App\Http\Controllers;

use App\Models\AccountHead;
use App\Models\Employee;
use App\Models\EmployeePayComponent;
use App\Models\GlJournal;
use App\Models\PayComponent;
use App\Models\PaymentMode;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\Setting;
use App\Models\StaffAdvance;
use App\Services\ActivityLogger;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

class PayrollController extends Controller
{
    // ───────────── runs ─────────────

    public function index()
    {
        $runs = PayrollRun::with('generator', 'approver')->orderByDesc('period_start')->paginate(24);
        $existing = PayrollRun::pluck('period_start')->map(fn ($d) => $d->format('Y-m'))->all();
        $months = collect(range(0, 11))->map(fn ($i) => bdate()->copy()->startOfMonth()->subMonths($i))
            ->reject(fn ($m) => in_array($m->format('Y-m'), $existing, true))->mapWithKeys(fn ($m) => [$m->format('Y-m') => $m->format('F Y')]);
        $ytd = PayrollRun::whereIn('status', ['approved', 'paid'])->whereYear('period_start', bdate()->year)
            ->selectRaw('SUM(gross) g, SUM(net) n, SUM(employer_cost) e, SUM(deductions) d')->first();

        return view('payroll.index', ['runs' => $runs, 'months' => $months, 'ytd' => $ytd,
            'unpaid' => PayrollRun::where('status', 'approved')->sum('net'), 'drafts' => PayrollRun::where('status', 'draft')->count(),
            'advances' => StaffAdvance::whereNull('cancelled_at')->get()->sum(fn ($a) => $a->outstanding())]);
    }

    public function store(Request $request)
    {
        $d = $request->validate(['month' => 'required|date_format:Y-m', 'notes' => 'nullable|string|max:255']);
        try {
            $run = PayrollService::createRun(Carbon::createFromFormat('Y-m-d', $d['month'].'-01'), $d['notes'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('payroll.show', $run)->with('success', "Payroll {$run->run_no} generated for {$run->employees} employee(s). Review and send for approval.");
    }

    public function show(PayrollRun $prun)
    {
        $prun->load('generator', 'approver', 'payer');
        $slips = $prun->payslips()->with('employee.department', 'paymentMode', 'lines')->get()->sortBy(fn ($s) => $s->employee->emp_code)->values();
        $byCode = PayslipLine::whereIn('payslip_id', $slips->pluck('id'))->selectRaw('kind, code, MIN(name) name, SUM(amount) amt, COUNT(*) n, MIN(sort_order) so')
            ->groupBy('kind', 'code')->orderBy('so')->get()->groupBy('kind');
        $journals = GlJournal::whereIn('source_type', ['payroll', 'payroll_pay'])->where('source_key', (string) $prun->id)->orderBy('id')->get();

        return view('payroll.show', ['run' => $prun, 'slips' => $slips, 'byCode' => $byCode, 'journals' => $journals,
            'modes' => $slips->groupBy(fn ($s) => $s->paymentMode?->name ?? '—')->map(fn ($g) => ['n' => $g->count(), 'net' => $g->sum('net')]),
            'segregated' => setting('payroll_segregation', '1') === '1' && (int) $prun->generated_by === (int) auth()->id()]);
    }

    public function recalc(PayrollRun $prun)
    {
        return $this->act(fn () => PayrollService::generate($prun), 'Payroll recalculated from the latest attendance, leave and advances.');
    }

    public function approve(PayrollRun $prun)
    {
        return $this->act(fn () => PayrollService::approve($prun), "Payroll {$prun->run_no} approved and accrued in the ledger.");
    }

    public function reopen(Request $request, PayrollRun $prun)
    {
        $d = $request->validate(['reason' => 'required|string|max:200']);

        return $this->act(fn () => PayrollService::reopen($prun, $d['reason']), "Payroll {$prun->run_no} reopened as draft; the accrual is reversed.");
    }

    public function pay(Request $request, PayrollRun $prun)
    {
        $d = $request->validate(['paid_date' => 'required|date']);

        return $this->act(fn () => PayrollService::pay($prun, Carbon::parse($d['paid_date'])->toDateString()), "Payroll {$prun->run_no} marked as paid.");
    }

    public function destroy(PayrollRun $prun)
    {
        try {
            PayrollService::deleteRun($prun);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('payroll.index')->with('success', 'Draft run deleted.');
    }

    /** Bank advice / salary transfer file for the bank (net pay per employee). */
    public function bankAdvice(PayrollRun $prun)
    {
        $slips = $prun->payslips()->with('employee', 'paymentMode')->get()->sortBy(fn ($s) => $s->employee->emp_code);
        ActivityLogger::log('payroll', 'export', "Bank advice exported for {$prun->run_no}", $prun);

        return response()->streamDownload(function () use ($slips, $prun) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Run', 'Emp code', 'Employee', 'Bank', 'Account no.', 'Bank sort code', 'Payment mode', 'Net pay', 'Narration']);
            foreach ($slips as $s) {
                fputcsv($out, [$prun->run_no, $s->employee->emp_code, $s->employee->name, $s->employee->bank_name, $s->bank_account ?? $s->employee->bank_account,
                    $s->employee->bank_ifsc, $s->paymentMode?->name, number_format((float) $s->net, 2, '.', ''), 'Salary '.$prun->period_start->format('M Y')]);
            }
            fclose($out);
        }, 'bank-advice-'.$prun->run_no.'.csv', ['Content-Type' => 'text/csv']);
    }

    /** All payslips of a run on one printable page. */
    public function printAll(PayrollRun $prun)
    {
        $slips = $prun->payslips()->with('employee.department', 'lines', 'paymentMode')->get()->sortBy(fn ($s) => $s->employee->emp_code);

        return view('payroll.payslip', ['slips' => $slips, 'run' => $prun]);
    }

    // ───────────── payslips ─────────────

    public function payslip(Payslip $slip)
    {
        $slip->load('run', 'employee.department', 'lines', 'paymentMode');

        return view('payroll.payslip', ['slips' => collect([$slip]), 'run' => $slip->run,
            'editable' => ! $slip->run->isLocked() && can_do('payroll.manage'), 'heads' => $this->expenseHeads()]);
    }

    public function addLine(Request $request, Payslip $slip)
    {
        $d = $request->validate(['kind' => 'required|in:earning,deduction', 'name' => 'required|string|max:80', 'amount' => 'required|numeric|min:0.01|max:9999999',
            'taxable' => 'nullable|boolean', 'account_head_id' => 'nullable|integer|owned:account_heads']);

        return $this->act(fn () => PayrollService::addAdjustment($slip, $d), 'Adjustment added and the payslip recalculated.');
    }

    public function removeLine(PayslipLine $pline)
    {
        return $this->act(fn () => PayrollService::removeAdjustment($pline), 'Adjustment removed.');
    }

    // ───────────── components & settings ─────────────

    public function components(Request $request)
    {
        return view('payroll.components', [
            'components' => PayComponent::with('expenseHead', 'liabilityHead')->orderBy('sort_order')->orderBy('id')->get()->groupBy('kind'),
            'heads' => $this->expenseHeads(), 'liabilities' => AccountHead::where('type', 'liability')->where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn ($h) => [$h->id => $h->code.' · '.$h->name]),
            'modes' => PaymentMode::where('is_active', true)->whereNotIn('type', ['city_ledger', 'gift_voucher'])->pluck('name', 'id'),
            'slabs' => PayrollService::taxSlabs(),
            'edit' => $request->edit ? PayComponent::find($request->edit) : null,
        ]);
    }

    public function saveComponent(Request $request, ?PayComponent $pcomp = null)
    {
        $pcomp = $pcomp?->exists ? $pcomp : null; // the store route has no {pcomp}: the container hands over an empty model
        if ($pcomp?->isSystem()) { // system components: only the name, expense account and order are editable
            $d = $request->validate(['name' => 'required|string|max:60', 'expense_head_id' => 'nullable|integer|owned:account_heads', 'sort_order' => 'nullable|integer|min:0|max:999']);
            $pcomp->update(['name' => $d['name'], 'expense_head_id' => $pcomp->kind === 'earning' ? ($d['expense_head_id'] ?? null) : $pcomp->expense_head_id, 'sort_order' => $d['sort_order'] ?? $pcomp->sort_order]);

            return redirect()->route('payroll.components')->with('success', "{$pcomp->code} updated.");
        }
        $d = $request->validate([
            'code' => 'required|string|max:12|regex:/^[A-Z0-9_+-]+$/', 'name' => 'required|string|max:60', 'kind' => 'required|in:earning,deduction,employer',
            'calc' => 'required|in:fixed,percent_basic,percent_gross,slab,tax', 'value' => 'nullable|numeric|min:0|max:99999999',
            'cap_base' => 'nullable|numeric|min:0', 'gross_ceiling' => 'nullable|numeric|min:0', 'slab_upto' => 'nullable|array|max:12', 'slab_upto.*' => 'nullable|numeric|min:0',
            'slab_amount' => 'nullable|array|max:12', 'slab_amount.*' => 'nullable|numeric|min:0',
            'prorate' => 'nullable|boolean', 'taxable' => 'nullable|boolean', 'is_default' => 'nullable|boolean', 'is_active' => 'nullable|boolean',
            'expense_head_id' => 'nullable|integer|owned:account_heads', 'liability_head_id' => 'nullable|integer|owned:account_heads', 'sort_order' => 'nullable|integer|min:0|max:999',
        ], [], ['calc' => 'calculation']);
        $d['code'] = strtoupper($d['code']);
        $err = match (true) {
            in_array($d['code'], PayComponent::SYSTEM, true) => 'That code is reserved for a system component.',
            PayComponent::where('code', $d['code'])->when($pcomp, fn ($q) => $q->where('id', '!=', $pcomp->id))->exists() => 'That code is already used.',
            $d['kind'] === 'earning' && ! in_array($d['calc'], ['fixed', 'percent_basic'], true) => 'Earnings can be a fixed amount or a % of basic.',
            $d['calc'] === 'tax' && $d['kind'] !== 'deduction' => 'Income tax is a deduction.',
            $d['calc'] === 'tax' && PayComponent::where('calc', 'tax')->where('is_active', true)->when($pcomp, fn ($q) => $q->where('id', '!=', $pcomp->id))->exists() => 'There is already an active income-tax component.',
            in_array($d['calc'], ['percent_basic', 'percent_gross'], true) && (float) ($d['value'] ?? 0) > 100 => 'A percentage cannot exceed 100.',
            $d['kind'] !== 'earning' && empty($d['liability_head_id']) => 'Choose the liability account the deduction / contribution is owed to.',
            default => null,
        };
        $slabs = null;
        if ($d['calc'] === 'slab') {
            $slabs = [];
            foreach ($d['slab_amount'] ?? [] as $i => $amt) {
                if ($amt === null || $amt === '') {
                    continue;
                }
                $upto = $d['slab_upto'][$i] ?? null;
                $slabs[] = [$upto === null || $upto === '' ? null : (float) $upto, (float) $amt];
            }
            usort($slabs, fn ($a, $b) => ($a[0] ?? INF) <=> ($b[0] ?? INF));
            $err ??= ! $slabs ? 'Enter at least one slab.' : (end($slabs)[0] !== null ? 'The last slab must be open-ended (leave "up to" blank).' : null);
        }
        if ($err) {
            return back()->withInput()->with('error', $err);
        }
        $row = ['code' => $d['code'], 'name' => $d['name'], 'kind' => $d['kind'], 'calc' => $d['calc'], 'value' => $d['value'] ?? 0,
            'cap_base' => $d['calc'] === 'percent_basic' ? ($d['cap_base'] ?? null) : null, 'gross_ceiling' => $d['calc'] === 'percent_gross' ? ($d['gross_ceiling'] ?? null) : null,
            'slabs' => $slabs, 'prorate' => $request->boolean('prorate'), 'taxable' => $request->boolean('taxable'), 'tax_relief' => $d['kind'] === 'deduction' && $request->boolean('tax_relief'), 'is_default' => $request->boolean('is_default'),
            'is_active' => $request->boolean('is_active'), 'sort_order' => $d['sort_order'] ?? 100,
            'expense_head_id' => $d['kind'] === 'deduction' ? null : ($d['expense_head_id'] ?? null), 'liability_head_id' => $d['kind'] === 'earning' ? null : ($d['liability_head_id'] ?? null)];
        $pcomp ? $pcomp->update($row) : $pcomp = PayComponent::create($row);
        ActivityLogger::log('payroll', 'component', "Saved pay component {$pcomp->code} – {$pcomp->ruleLabel()}", $pcomp);

        return redirect()->route('payroll.components')->with('success', "Pay component {$pcomp->code} saved. Draft runs pick it up on recalculation.");
    }

    public function saveSettings(Request $request)
    {
        $d = $request->validate([
            'payroll_shift_hours' => 'required|numeric|min:1|max:16', 'payroll_ot_multiplier' => 'required|numeric|min:1|max:5',
            'payroll_unmarked' => 'required|in:present,absent', 'payroll_advance_limit_months' => 'required|numeric|min:0|max:24', 'payroll_default_mode' => 'nullable|integer|owned:payment_modes',
            'tax_upto' => 'required|array|min:1|max:12', 'tax_upto.*' => 'nullable|numeric|min:0', 'tax_rate' => 'required|array', 'tax_rate.*' => 'nullable|numeric|min:0|max:100',
        ]);
        $slabs = [];
        foreach ($d['tax_rate'] as $i => $rate) {
            if ($rate === null || $rate === '') {
                continue;
            }
            $upto = $d['tax_upto'][$i] ?? null;
            $slabs[] = [$upto === null || $upto === '' ? null : (float) $upto, (float) $rate];
        }
        usort($slabs, fn ($a, $b) => ($a[0] ?? INF) <=> ($b[0] ?? INF));
        if ($request->boolean('reset_bands')) {
            $slabs = PayrollService::PAYE_BANDS;
        }
        if (! $slabs || end($slabs)[0] !== null) {
            return back()->withInput()->with('error', 'The PAYE table needs an open-ended top band (leave "up to" blank).');
        }
        foreach (['payroll_shift_hours', 'payroll_ot_multiplier', 'payroll_unmarked', 'payroll_advance_limit_months', 'payroll_default_mode'] as $k) {
            Setting::updateOrCreate(['key' => $k], ['value' => (string) ($d[$k] ?? '')]);
        }
        Setting::updateOrCreate(['key' => 'payroll_paye_bands'], ['value' => json_encode($slabs)]);
        ActivityLogger::log('payroll', 'settings', 'Updated payroll settings');

        return back()->with('success', 'Payroll settings saved. Draft runs pick them up on recalculation.');
    }

    // ───────────── employee pay setup ─────────────

    public function employee(Employee $employee)
    {
        $employee->load('department', 'paymentMode');
        $assigned = $employee->payComponents()->get()->keyBy('pay_component_id');
        $month = bdate()->copy()->startOfMonth();
        $preview = PayrollService::compute($employee, new PayrollRun(['period_start' => $month, 'period_end' => $month->copy()->endOfMonth()->startOfDay()]), collect());

        return view('payroll.employee', ['e' => $employee, 'assigned' => $assigned, 'preview' => $preview, 'month' => $month,
            'components' => PayComponent::where('is_active', true)->whereNotIn('code', PayComponent::SYSTEM)->orderBy('sort_order')->orderBy('id')->get(),
            'slips' => $employee->payslips()->with('run')->latest('id')->limit(12)->get(),
            'advances' => $employee->advances()->latest('date')->get()]);
    }

    public function saveEmployee(Request $request, Employee $employee)
    {
        $d = $request->validate(['c' => 'array', 'c.*.value' => 'nullable|numeric|min:0|max:99999999', 'c.*.state' => 'required|in:default,on,off']);
        foreach (PayComponent::whereNotIn('code', PayComponent::SYSTEM)->get() as $c) {
            $row = $d['c'][$c->id] ?? null;
            if (! $row) {
                continue;
            }
            $value = ($row['value'] ?? '') === '' ? null : (float) $row['value'];
            $state = $row['state'];
            if ($state === 'default' && $value === null) {
                EmployeePayComponent::where('employee_id', $employee->id)->where('pay_component_id', $c->id)->delete();

                continue;
            }
            EmployeePayComponent::updateOrCreate(['employee_id' => $employee->id, 'pay_component_id' => $c->id], ['value' => $value, 'is_excluded' => $state === 'off']);
        }
        ActivityLogger::log('payroll', 'employee_pay', "Updated pay setup for {$employee->name}", $employee);

        return back()->with('success', "Pay setup saved for {$employee->name}.");
    }

    // ───────────── advances ─────────────

    public function advances(Request $request)
    {
        $list = StaffAdvance::with('employee', 'paymentMode', 'creator')->when($request->employee_id, fn ($q, $e) => $q->where('employee_id', $e))
            ->orderByDesc('date')->orderByDesc('id')->paginate(30)->withQueryString();

        return view('payroll.advances', ['advances' => $list,
            'employees' => Employee::where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn ($e) => [$e->id => $e->name.' ('.$e->emp_code.')']),
            'modes' => PaymentMode::where('is_active', true)->whereNotIn('type', ['city_ledger', 'gift_voucher'])->pluck('name', 'id')]);
    }

    public function storeAdvance(Request $request)
    {
        $d = $request->validate(['employee_id' => 'required|integer|owned:employees', 'date' => 'required|date', 'amount' => 'required|numeric|min:1|max:9999999',
            'installment' => 'required|numeric|min:1', 'payment_mode_id' => 'required|integer|owned:payment_modes', 'reason' => 'nullable|string|max:200']);
        if ((float) $d['installment'] > (float) $d['amount']) {
            return back()->withInput()->with('error', 'The monthly instalment cannot exceed the advance.');
        }
        if ($d['date'] > bdate()->toDateString()) {
            return back()->withInput()->with('error', 'An advance cannot be dated in the future.');
        }
        if (($lock = setting('gl_lock_date')) && $d['date'] <= $lock) {
            return back()->withInput()->with('error', 'The ledger is locked up to '.dmy($lock).'.');
        }
        $emp = Employee::find($d['employee_id']);
        $open = StaffAdvance::where('employee_id', $emp->id)->whereNull('cancelled_at')->get()->sum(fn ($a) => $a->outstanding());
        $limit = (float) setting('payroll_advance_limit_months', 2) * (float) $emp->salary;
        if ($limit > 0 && $open + (float) $d['amount'] > $limit) {
            return back()->withInput()->with('error', sprintf('Advance limit exceeded: %s outstanding + %s requested > %s (%s × monthly salary).', money($open), money($d['amount']), money($limit), setting('payroll_advance_limit_months', 2)));
        }
        $adv = PayrollService::giveAdvance($d);

        return back()->with('success', "Advance {$adv->advance_no} of ".money($adv->amount)." recorded; ".money($adv->installment).' will be recovered each payroll.');
    }

    public function cancelAdvance(StaffAdvance $sadv)
    {
        return $this->act(fn () => PayrollService::cancelAdvance($sadv), "Advance {$sadv->advance_no} cancelled.");
    }

    // ───────────── helpers ─────────────

    protected function act(callable $fn, string $ok)
    {
        try {
            $fn();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $ok);
    }

    protected function expenseHeads()
    {
        return AccountHead::where('type', 'expense')->where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn ($h) => [$h->id => $h->code.' · '.$h->name]);
    }
}
