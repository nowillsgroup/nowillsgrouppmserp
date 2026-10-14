@extends('layouts.app')
@section('title', 'Pay Components')
@section('crumbs') <a href="{{ route('payroll.index') }}">Payroll</a> / Pay components @endsection
@section('actions')<a class="btn btn-primary" href="{{ route('payroll.components') }}#compForm"><x-icon name="plus"/> New component</a>@endsection
@section('content')
<div class="grid g-2-1">
<div>
@foreach(\App\Models\PayComponent::KINDS as $kind => $title)
<div class="card flush {{ $loop->first ? '' : 'mt-2' }}"><div class="card-head"><h3>{{ $title }}s</h3></div><div class="card-body table-wrap"><table class="table table-compact comp-table" data-kind="{{ $kind }}">
<thead><tr><th>Code</th><th>Name</th><th>Rule</th><th>Applies to</th><th>Ledger account</th><th></th></tr></thead><tbody>
@forelse($components[$kind] ?? [] as $c)
<tr class="{{ $c->is_active ? '' : 'comp-off' }}"><td><span class="badge {{ $c->isSystem() ? 'badge-purple' : 'badge-slate' }}">{{ $c->code }}</span></td><td class="bold">{{ $c->name }}@if(! $c->is_active) <span class="badge badge-slate">inactive</span>@endif</td>
<td class="small">{{ $c->ruleLabel() }}@if($kind === 'earning' && ! $c->taxable) <span class="badge badge-green">tax-free</span>@endif</td>
<td class="small">{{ $c->isSystem() ? 'System' : ($c->is_default ? 'Everyone' : 'Assigned only') }}</td>
<td class="small">@if($c->expenseHead)<div>Dr {{ $c->expenseHead->code }} {{ $c->expenseHead->name }}</div>@endif @if($c->liabilityHead)<div>Cr {{ $c->liabilityHead->code }} {{ $c->liabilityHead->name }}</div>@endif @if(! $c->expenseHead && ! $c->liabilityHead)<span class="muted">Salaries &amp; Wages</span>@endif</td>
<td class="right"><a class="btn btn-sm btn-ghost" href="{{ route('payroll.components', ['edit' => $c->id]) }}#compForm" title="Edit"><x-icon name="edit"/></a></td></tr>
@empty<tr><td colspan="6" class="muted small">None</td></tr>@endforelse
</tbody></table></div></div>
@endforeach

@php $e = $edit; $sys = $e?->isSystem(); @endphp
<div class="card mt-2" id="compForm"><div class="card-head"><h3>{{ $e ? 'Edit '.$e->code : 'New pay component' }}</h3>@if($e)<a class="btn btn-sm" href="{{ route('payroll.components') }}">Cancel</a>@endif</div>
<form method="post" action="{{ $e ? route('payroll.components.update', $e) : route('payroll.components.store') }}" class="card-body form-grid">@csrf
  @if($sys)<div class="alert alert-info span-all"><x-icon name="lock"/><div>{{ $e->code }} is calculated by the payroll engine – only its name, account and order can change.</div></div>@endif
  <div class="field"><label>Code <span class="req">*</span></label><input name="code" class="input" value="{{ old('code', $e?->code) }}" maxlength="12" required pattern="[A-Za-z0-9_+\-]+" @readonly($sys) style="text-transform:uppercase"></div>
  <div class="field"><label>Name <span class="req">*</span></label><input name="name" class="input" value="{{ old('name', $e?->name) }}" maxlength="60" required></div>
  <div class="field"><label>Type</label><select name="kind" class="input" id="cKind" @disabled($sys)>@foreach(\App\Models\PayComponent::KINDS as $k => $v)<option value="{{ $k }}" @selected(old('kind', $e?->kind) === $k)>{{ $v }}</option>@endforeach</select></div>
  <div class="field"><label>Calculation</label><select name="calc" class="input" id="cCalc" @disabled($sys)>@foreach(\App\Models\PayComponent::CALCS as $k => $v)@if($k !== 'system' || $sys)<option value="{{ $k }}" @selected(old('calc', $e?->calc) === $k)>{{ $v }}</option>@endif @endforeach</select></div>
  <div class="field c-when" data-calc="fixed percent_basic percent_gross"><label>Amount or %</label><input name="value" type="number" step="0.01" min="0" class="input" value="{{ old('value', $e ? (float) $e->value : '') }}"></div>
  <div class="field c-when" data-calc="percent_basic"><label>Max base (e.g. 15000) – blank = no cap</label><input name="cap_base" type="number" step="0.01" min="0" class="input" value="{{ old('cap_base', $e?->cap_base) }}"></div>
  <div class="field c-when" data-calc="percent_gross"><label>Applies while gross ≤ (blank = always)</label><input name="gross_ceiling" type="number" step="0.01" min="0" class="input" value="{{ old('gross_ceiling', $e?->gross_ceiling) }}"></div>
  <div class="field span-all c-when" data-calc="slab"><label>Slabs on monthly gross (last row: leave "up to" blank)</label>
    <table class="table table-compact" style="max-width:420px"><thead><tr><th>Gross up to</th><th>Amount</th></tr></thead><tbody>
    @php $sl = $e?->slabs ?? []; @endphp
    @for($i = 0; $i < 5; $i++)<tr><td><input name="slab_upto[]" type="number" step="0.01" min="0" class="input input-sm" value="{{ $sl[$i][0] ?? '' }}"></td><td><input name="slab_amount[]" type="number" step="0.01" min="0" class="input input-sm" value="{{ isset($sl[$i]) ? $sl[$i][1] : '' }}"></td></tr>@endfor
    </tbody></table></div>
  <div class="field c-kind" data-kind="earning employer"><label>Expense account (debit)</label><select name="expense_head_id" class="input"><option value="">— default —</option>@foreach($heads as $id => $n)<option value="{{ $id }}" @selected((int) old('expense_head_id', $e?->expense_head_id) === $id)>{{ $n }}</option>@endforeach</select></div>
  <div class="field c-kind" data-kind="deduction employer"><label>Liability account (credit) <span class="req">*</span></label><select name="liability_head_id" class="input" @disabled($sys)><option value="">— choose —</option>@foreach($liabilities as $id => $n)<option value="{{ $id }}" @selected((int) old('liability_head_id', $e?->liability_head_id) === $id)>{{ $n }}</option>@endforeach</select></div>
  <div class="field"><label>Order on payslip</label><input name="sort_order" type="number" min="0" max="999" class="input" value="{{ old('sort_order', $e?->sort_order ?? 100) }}"></div>
  @unless($sys)
  <label class="check"><input type="checkbox" name="is_default" value="1" @checked(old('is_default', $e?->is_default ?? true))> Applies to every employee (untick = only where assigned)</label>
  <label class="check c-when" data-calc="fixed"><input type="checkbox" name="prorate" value="1" @checked(old('prorate', $e?->prorate ?? true))> Pro-rate by paid days</label>
  <label class="check c-kind" data-kind="earning"><input type="checkbox" name="taxable" value="1" @checked(old('taxable', $e?->taxable ?? true))> Taxable (counts for income tax)</label>
  <label class="check c-kind" data-kind="deduction"><input type="checkbox" name="tax_relief" value="1" @checked(old('tax_relief', $e?->tax_relief ?? false))> Tax relief (deducted before PAYE, e.g. SSNIT 5.5%)</label>
  <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $e?->is_active ?? true))> Active</label>
  @endunless
  <div class="span-all"><button class="btn btn-primary"><x-icon name="check"/> Save component</button></div>
</form></div>
</div>

<div>
<div class="card"><div class="card-head"><h3>Payroll settings</h3></div>
<form method="post" action="{{ route('payroll.settings') }}" class="card-body">@csrf
  <div class="form-grid" style="grid-template-columns:1fr 1fr">
  <div class="field"><label>Shift length (hours)</label><input name="payroll_shift_hours" type="number" step="0.25" min="1" max="16" class="input" value="{{ setting('payroll_shift_hours', 9) }}"></div>
  <div class="field"><label>Overtime rate (× hourly) · s. 35</label><input name="payroll_ot_multiplier" type="number" step="0.05" min="1" max="5" class="input" value="{{ setting('payroll_ot_multiplier', 1.5) }}"></div>
  <div class="field"><label>Public-holiday work rate (× hourly) · s. 77</label><input name="payroll_holiday_multiplier" type="number" step="0.25" min="1" max="4" class="input" value="{{ setting('payroll_holiday_multiplier', \App\Services\HolidayService::DEFAULTS['payroll_holiday_multiplier']) }}"></div>
  <div class="field"><label>Unmarked days count as</label><select name="payroll_unmarked" class="input">@foreach(['present' => 'Present (paid)', 'absent' => 'Absent (loss of pay)'] as $k => $v)<option value="{{ $k }}" @selected(setting('payroll_unmarked', 'present') === $k)>{{ $v }}</option>@endforeach</select></div>
  <div class="field"><label>Advance limit (× monthly salary)</label><input name="payroll_advance_limit_months" type="number" step="0.5" min="0" max="24" class="input" value="{{ setting('payroll_advance_limit_months', 2) }}"></div>
  <div class="field"><label>Junior-staff overtime: qualifying income cap a year ({{ hotel()->currency_symbol }})</label><input name="payroll_ot_junior_cap" type="number" step="100" min="0" max="9999999" class="input" value="{{ setting('payroll_ot_junior_cap', \App\Services\PayrollService::OT_JUNIOR_CAP) }}"></div>
  <div class="field"><label>… overtime free of the bands up to this share of the month's basic</label><input name="payroll_ot_junior_ratio" type="number" step="0.05" min="0" max="1" class="input" value="{{ setting('payroll_ot_junior_ratio', \App\Services\PayrollService::OT_JUNIOR_RATIO) }}"></div>
  <div class="field"><label>… final tax on that part (%)</label><input name="payroll_ot_junior_rate" type="number" step="0.5" min="0" max="100" class="input" value="{{ setting('payroll_ot_junior_rate', \App\Services\PayrollService::OT_JUNIOR_RATE) }}"></div>
  <div class="field"><label>… final tax on the excess (%)</label><input name="payroll_ot_junior_excess_rate" type="number" step="0.5" min="0" max="100" class="input" value="{{ setting('payroll_ot_junior_excess_rate', \App\Services\PayrollService::OT_JUNIOR_EXCESS_RATE) }}"></div>
  <div class="field"><label>Bonus: final-tax cap as a share of the year's basic (%) · GRA</label><input name="payroll_bonus_cap_pct" type="number" step="0.5" min="0" max="100" class="input" value="{{ setting('payroll_bonus_cap_pct', \App\Services\PayrollService::BONUS_CAP_PCT) }}">
    <div class="hint">A bonus up to this share of 12 × basic salary carries the final rate below; anything above it is added to the month's chargeable income and taxed on the bands.</div></div>
  <div class="field"><label>… final tax on that part (%)</label><input name="payroll_bonus_rate" type="number" step="0.5" min="0" max="100" class="input" value="{{ setting('payroll_bonus_rate', \App\Services\PayrollService::BONUS_RATE) }}">
    <div class="hint">A casual worker's bonus is not taxed here — it stays inside the 5% final tax on the whole gross pay.</div></div>
  <div class="field"><label>… tax on a non-resident's bonus and overtime (%)</label><input name="payroll_bonus_nonresident_rate" type="number" step="0.5" min="0" max="100" class="input" value="{{ setting('payroll_bonus_nonresident_rate', \App\Services\PayrollService::BONUS_NON_RESIDENT_RATE) }}">
    <div class="hint">GRA's flat rate for a non-resident's bonus; the rest of a non-resident's employment income stays at 25%.</div></div>
  <div class="field"><label>Payday (day of the month)</label><input name="payroll_payday" type="number" min="1" max="28" step="1" class="input" value="{{ setting('payroll_payday', '28') }}">
    <div class="hint">The day the run is expected to be paid. Past this day an approved but unpaid run is flagged as late by the nightly HR task.</div></div>
  <div class="field"><label>Text workers about their payslip</label><select name="payroll_notify" class="input"><option value="0" @selected(setting('payroll_notify', '0') !== '1')>Off</option><option value="1" @selected(setting('payroll_notify', '0') === '1')>On – send when a run is approved and when it is paid</option></select>
    <div class="hint">The message never carries an amount: pay is sensitive personal data under the Data Protection Act 2012 (Act 843).</div></div>
  <div class="field" style="grid-column:1/-1"><label>Leave days</label><select name="leave_count_all_days" class="input"><option value="0" @selected(setting('leave_count_all_days', '0') !== '1')>Count working days – skip public holidays and the worker's rest days (Act 651 ss. 20, 22)</option><option value="1" @selected(setting('leave_count_all_days', '0') === '1')>Count every calendar day of the span</option></select></div>
  <div class="field" style="grid-column:1/-1"><label>Default salary payment mode</label><select name="payroll_default_mode" class="input"><option value="">Bank transfer</option>@foreach($modes as $id => $n)<option value="{{ $id }}" @selected((int) setting('payroll_default_mode') === $id)>{{ $n }}</option>@endforeach</select></div>
  </div>
  <h4 class="mt-2">PAYE income tax (GRA)</h4>
  <p class="tiny muted">Bands in force for <b>{{ bdate()->format('F Y') }}</b>: <b>{{ $schedule['name'] }}</b> (effective {{ dmy(\Illuminate\Support\Carbon::parse($schedule['from'])) }})@if($overridden) – <span class="text-red">overridden by the table below, which then applies to every month</span>@endif. Payroll always taxes a month with the schedule that was in force in it, so reopening an earlier run recomputes it as it should have been:@foreach($schedules as $sc) <span class="badge {{ $sc['from'] === $schedule['from'] ? 'badge-green' : 'badge-slate' }}">{{ \Illuminate\Support\Carbon::parse($sc['from'])->format('M Y') }}: {{ $sc['name'] }}</span>@endforeach</p>
  <table class="table table-compact" id="taxSlabs"><thead><tr><th>Monthly chargeable income up to ({{ hotel()->currency_symbol }})</th><th>Rate %</th></tr></thead><tbody>
  @for($i = 0; $i < max(9, count($slabs) + 2); $i++)<tr><td><input name="tax_upto[]" type="number" step="0.01" min="0" class="input input-sm" value="{{ $slabs[$i][0] ?? '' }}" placeholder="{{ isset($slabs[$i]) && $slabs[$i][0] === null ? 'and above' : '' }}"></td><td><input name="tax_rate[]" type="number" step="0.01" min="0" max="100" class="input input-sm" value="{{ isset($slabs[$i]) ? $slabs[$i][1] : '' }}"></td></tr>@endfor
  </tbody></table>
  <label class="check"><input type="checkbox" name="reset_bands" value="1" id="resetBands"> Reset to the statutory GRA bands in force today ({{ $schedule['name'] }})</label>
  <p class="tiny muted">Chargeable income = taxable earnings − the employee's SSNIT 5.5% (capped at {{ money(\App\Services\PayrollService::SSNIT_CAP) }} insurable earnings) − the personal reliefs on the employee record (GRA: marriage {{ money(1200) }}, child education {{ money(600) }} a child, old age {{ money(1500) }}, aged dependant {{ money(1000) }}, education {{ money(2000) }} – a year each). PAYE is computed on the monthly bands cumulatively; the top band is open-ended (leave "up to" blank). Three other GRA rules run on their own: a <b>non-resident</b> is taxed at a flat {{ \App\Services\PayrollService::NON_RESIDENT_RATE }}%, a <b>casual worker</b> at a final {{ \App\Services\PayrollService::CASUAL_RATE }}% of gross, and a <b>junior employee's overtime</b> at a final {{ \App\Services\PayrollService::OT_JUNIOR_RATE }}% / {{ \App\Services\PayrollService::OT_JUNIOR_EXCESS_RATE }}% instead of the bands. File and pay PAYE to GRA by the 15th of the following month; SSNIT and Tier 2 by the 14th.</p>
  <button class="btn btn-primary mt-1"><x-icon name="check"/> Save settings</button>
</form></div>
<div class="card mt-2"><div class="card-body small">
  <b>Per-employee values</b><p class="muted">Override amounts or switch components on/off for one employee from <i>Pay setup</i> (the pencil on a payslip row, or Employees → the employee's pay setup).</p>
  <b>Segregation of duties</b><p class="muted">{{ setting('payroll_segregation', '1') === '1' ? 'On' : 'Off' }} – a run must be approved by someone other than its preparer (Hotel Settings).</p>
</div></div>
</div>
</div>
<script>
(() => {
  const kind = document.getElementById('cKind'), calc = document.getElementById('cCalc');
  const sync = () => {
    document.querySelectorAll('.c-when').forEach(el => el.style.display = el.dataset.calc.split(' ').includes(calc.value) ? '' : 'none');
    document.querySelectorAll('.c-kind').forEach(el => el.style.display = el.dataset.kind.split(' ').includes(kind.value) ? '' : 'none');
  };
  kind.addEventListener('change', sync); calc.addEventListener('change', sync); sync();
})();
</script>
@endsection
