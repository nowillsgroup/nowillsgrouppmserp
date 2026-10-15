@extends('layouts.app')
@section('title', 'Statutory Returns')
@section('crumbs') HR &amp; Payroll / <a href="{{ route('payroll.index') }}">Payroll</a> / Statutory returns @endsection
@php
  $pl = \Illuminate\Support\Carbon::parse($period.'-01')->format('F Y');
  $cur = $o[$tab];
  $badge = ['remitted' => ['green', 'Paid'], 'late' => ['amber', 'Paid late'], 'due' => ['blue', 'Due'], 'overdue' => ['red', 'Overdue'], 'draft' => ['slate', 'Payroll not approved'], 'no_payroll' => ['slate', 'No payroll'], 'nil' => ['slate', 'Nil']];
  $run = $cur['run'];
@endphp
@section('actions')
<form class="filters" style="margin:0;display:inline-flex"><input type="hidden" name="tab" value="{{ $tab }}"><select name="period" class="input" onchange="this.form.submit()" id="statPeriod">@foreach($periods as $p)<option value="{{ $p }}" @selected($p === $period)>{{ \Illuminate\Support\Carbon::parse($p.'-01')->format('F Y') }}</option>@endforeach</select></form>
@if($run)<a class="btn" href="{{ route('payroll.show', $run) }}"><x-icon name="money"/> {{ $run->run_no }}</a>@endif
@if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', ['statutory_remittances', 'from' => bdate()->copy()->startOfYear()->toDateString(), 'to' => bdate()->toDateString()]) }}"><x-icon name="chart"/> Remittance register</a>@endif
@endsection
@section('content')
@if($alerts->where('overdue', true)->isNotEmpty())
<div class="alert alert-error" id="statOverdue"><x-icon name="alert"/><div><b>Overdue:</b>
  @foreach($alerts->where('overdue', true) as $a)<a href="{{ route('payroll.statutory', ['period' => $a['period'], 'tab' => $a['kind']]) }}">{{ $a['label'] }} · {{ \Illuminate\Support\Carbon::parse($a['period'].'-01')->format('M Y') }}</a> ({{ money($a['total']) }}, due {{ $a['due']->format('d M') }})@if(! $loop->last); @endif @endforeach
  – late SSNIT contributions attract a penalty; PAYE paid late attracts GRA interest and penalties.</div></div>
@endif
@if($belowMin->isNotEmpty())
<div class="alert alert-warn" id="minWageAlert"><x-icon name="alert"/><div>Below the national minimum wage ({{ money($cfg['payroll_min_wage_daily']) }} a day × {{ $cfg['payroll_min_wage_days'] }} days = <b>{{ money($minWage) }}</b> a month): <b>{{ $belowMin->pluck('name')->join(', ') }}</b>. Raise the basic salary under Masters › Employees.</div></div>
@endif

<div class="stats" id="statCards">
@foreach($o as $k => $r)
  @php [$tone, $txt] = $badge[$r['status']]; @endphp
  <div id="stat{{ ['ssnit' => 'Ssnit', 'tier2' => 'Tier2', 'paye' => 'Paye'][$k] }}" data-status="{{ $r['status'] }}" style="display:contents">
  <x-stat :label="$r['label'].' · '.$pl" :value="money($r['total'])" :icon="$r['remittance'] ? 'check' : 'clock'" :tone="$tone" :href="route('payroll.statutory', ['period' => $period, 'tab' => $k])"
    :sub="$txt.($r['remittance'] ? ' '.$r['remittance']->paid_on->format('d M').' · '.$r['remittance']->reference : ' · due '.$r['due']->format('d M Y'))"/></div>
@endforeach
  <x-stat label="Employees on the payroll" :value="$run ? $run->employees : '—'" icon="users" tone="slate" :sub="$run ? ucfirst($run->status).' · '.$run->run_no : 'no payroll run for '.$pl"/>
</div>

<div class="tabs mt-1">@foreach($o as $k => $r)<a href="{{ route('payroll.statutory', ['period' => $period, 'tab' => $k]) }}" class="{{ $k === $tab ? 'active' : '' }}">{{ $r['label'] }}</a>@endforeach</div>

<div class="card flush">
  <div class="card-head"><h3>{{ $cur['label'] }} · {{ $pl }}</h3>
    <div class="flex gap-1">
      <span class="small muted">Pay to <b>{{ $cur['payee'] }}</b> by <b>{{ $cur['due']->format('d M Y') }}</b></span>
      @if($cur['rows']->isNotEmpty())<a class="btn btn-sm" id="statExport" href="{{ route('payroll.statutory.export', ['kind' => $tab, 'period' => $period]) }}"><x-icon name="download"/> Schedule (CSV)</a>@endif
      @if(in_array($cur['status'], ['due', 'overdue'], true) && can_do('payroll.approve'))<button class="btn btn-sm btn-primary" data-modal="remitModal" id="statRemitBtn"><x-icon name="check"/> Record payment</button>@endif
    </div>
  </div>
  @if($cur['status'] === 'draft')
    <div class="card-body"><div class="alert alert-info" id="statDraft"><x-icon name="clock"/><div>The {{ $pl }} payroll ({{ $run->run_no }}) is still a draft. The return is built from the approved payslips – approve the run first.</div></div></div>
  @elseif($cur['status'] === 'no_payroll')
    <div class="card-body"><x-empty text="No payroll run for {{ $pl }}." icon="money"/></div>
  @else
    @if($cur['issues'])
    <div class="card-body" style="padding-bottom:0"><div class="alert alert-warn" id="statIssues"><x-icon name="alert"/><div>{{ count($cur['issues']) }} employee(s) missing an identifier the return needs: {{ implode('; ', $cur['issues']) }}. Add them under Masters › Employees.</div></div></div>
    @endif
    @if($cur['gl'] !== null && abs($cur['gl'] - $cur['total']) > 0.01)
    <div class="card-body" style="padding-bottom:0"><div class="alert alert-info" id="statGlDiff"><x-icon name="alert"/><div>The payroll journal credited {{ money($cur['gl']) }} to the liability account against {{ money($cur['total']) }} on this return.</div></div></div>
    @endif
    @if($cur['remittance'])
    @php $rm = $cur['remittance']; @endphp
    <div class="card-body" style="padding-bottom:0"><div class="alert {{ $cur['status'] === 'late' ? 'alert-warn' : 'alert-success' }}" id="statPaid"><x-icon name="check"/><div>
      Paid {{ money($rm->amount) }} on <b>{{ dmy($rm->paid_on) }}</b> from {{ $rm->paymentMode?->name ?? '—' }} · reference <b>{{ $rm->reference }}</b> · {{ $rm->remit_no }}{{ $cur['status'] === 'late' ? ' – '.$rm->paid_on->diffInDays($cur['due']).' day(s) after the due date' : '' }}. Recorded by {{ $rm->creator?->name ?? '—' }}.
    </div></div></div>
    @endif
    <div class="card-body table-wrap"><table class="table" id="statTable">
    @if($tab === 'paye')
      @php
        $extra = [];
        if (($cur['ot_tax'] ?? 0) > 0) { $extra[] = 'overtime tax on junior staff '.money($cur['ot_tax']); }
        if (($cur['bonus_tax'] ?? 0) > 0) { $extra[] = 'tax on bonuses '.money($cur['bonus_tax']).' on '.money($cur['bonus'] ?? 0).' of ex-gratia pay (final rate, outside the bands)'; }
        if (($cur['personal_relief'] ?? 0) > 0) { $extra[] = 'personal reliefs claimed '.money($cur['personal_relief']); }
      @endphp
      <div class="card-body" style="padding-bottom:0"><p class="tiny muted"><b>Bands applied:</b> {{ $cur['schedule']['name'] ?? '—' }} (in force {{ isset($cur['schedule']) ? dmy(\Illuminate\Support\Carbon::parse($cur['schedule']['from'])) : '' }}) – {{ $cur['non_residents'] }} non-resident(s) at a flat 25%{{ $extra ? ' · '.implode(' · ', $extra) : '' }}. The DT 107 return below carries the same rule per employee.</p></div>
      <thead><tr><th>Employee</th><th>TIN / Ghana Card</th><th>Position</th><th class="num">Basic</th><th class="num">Allowances</th><th class="num">Overtime</th><th class="num">Gross</th><th class="num">SSNIT relief</th><th class="num">Reliefs</th><th class="num">Chargeable</th><th class="num">OT tax</th><th class="num">Bonus</th><th class="num">Bonus tax</th><th class="num">PAYE</th></tr></thead>
      <tbody>@foreach($cur['rows'] as $x)
      <tr><td>{{ $x['name'] }}<div class="tiny muted">{{ $x['emp_code'] }}@if($x['resident'] === 'N') · <span class="badge badge-amber">non-resident 25%</span>@endif<div class="tiny muted">{{ $x['basis'] }}</div></div></td><td class="mono small {{ \App\Services\GstService::validTin($x['ghana_card']) ? '' : 'text-red' }}">{{ $x['ghana_card'] ?: 'missing' }}</td><td class="small">{{ $x['position'] }}</td>
        <td class="num">{{ money($x['basic'], false) }}</td><td class="num">{{ money($x['allowances'], false) }}</td><td class="num">{{ money($x['overtime'], false) }}</td><td class="num">{{ money($x['gross'], false) }}</td>
        <td class="num">{{ money($x['relief'], false) }}</td><td class="num">{{ $x['personal_relief'] ? money($x['personal_relief'], false) : '—' }}</td><td class="num">{{ money($x['chargeable'], false) }}</td><td class="num">{{ $x['ot_tax'] ? money($x['ot_tax'], false) : '—' }}</td><td class="num">{{ ($x['bonus'] ?? 0) ? money($x['bonus'], false) : '—' }}</td><td class="num">{{ ($x['bonus_tax'] ?? 0) ? money($x['bonus_tax'], false) : '—' }}</td><td class="num bold">{{ money($x['total'], false) }}</td></tr>
      @endforeach</tbody>
      <tfoot><tr><th colspan="3">{{ $cur['employees'] }} employee(s)</th><th class="num">{{ money($cur['rows']->sum('basic'), false) }}</th><th class="num">{{ money($cur['rows']->sum('allowances'), false) }}</th><th class="num">{{ money($cur['rows']->sum('overtime'), false) }}</th><th class="num">{{ money($cur['rows']->sum('gross'), false) }}</th><th class="num">{{ money($cur['rows']->sum('relief'), false) }}</th><th class="num">{{ money($cur['personal_relief'], false) }}</th><th class="num">{{ money($cur['rows']->sum('chargeable'), false) }}</th><th class="num">{{ money($cur['ot_tax'], false) }}</th><th class="num">{{ money($cur['bonus'], false) }}</th><th class="num">{{ money($cur['bonus_tax'], false) }}</th><th class="num" id="statTotal">{{ money($cur['total'], false) }}</th></tr></tfoot>
    @else
      <thead><tr><th>Employee</th><th>SSNIT no.</th><th>Ghana Card</th><th class="num">Basic</th><th class="num">Insurable</th><th class="num">Employee</th><th class="num">Employer</th><th class="num">Total</th></tr></thead>
      <tbody>@foreach($cur['rows'] as $x)
      @php $okS = \App\Services\StatutoryService::validSsnit($x['ssnit_no']); $okG = \App\Services\StatutoryService::isGhanaCard($x['ghana_card']); @endphp
      <tr><td>{{ $x['name'] }}<div class="tiny muted">{{ $x['emp_code'] }} · {{ $x['position'] }}</div></td><td class="mono small {{ $okS || $okG ? '' : 'text-red' }}">{{ $x['ssnit_no'] ?: '—' }}</td><td class="mono small">{{ $x['ghana_card'] ?: '—' }}</td>
        <td class="num">{{ money($x['basic'], false) }}</td><td class="num">{{ money($x['insurable'], false) }}</td><td class="num">{{ money($x['ee'], false) }}</td><td class="num">{{ money($x['er'], false) }}</td><td class="num bold">{{ money($x['total'], false) }}</td></tr>
      @endforeach</tbody>
      <tfoot><tr><th colspan="3">{{ $cur['employees'] }} employee(s)</th><th class="num">{{ money($cur['rows']->sum('basic'), false) }}</th><th class="num">{{ money($cur['rows']->sum('insurable'), false) }}</th><th class="num">{{ money($cur['ee'], false) }}</th><th class="num">{{ money($cur['er'], false) }}</th><th class="num" id="statTotal">{{ money($cur['total'], false) }}</th></tr></tfoot>
    @endif
    </table></div>
  @endif
</div>

<div class="grid-2 mt-2">
  <div class="card flush"><div class="card-head"><h3><x-icon name="clock"/> Payments recorded</h3></div><div class="card-body table-wrap"><table class="table" id="remitHistory">
    <thead><tr><th>Paid</th><th>Return</th><th>Month</th><th class="num">Amount</th><th>Reference</th><th></th></tr></thead><tbody>
    @forelse($history as $h)
    <tr class="{{ $h->isCancelled() ? 'muted' : '' }}"><td class="small">{{ dmy($h->paid_on) }}<div class="tiny muted">{{ $h->remit_no }}</div></td><td class="small">{{ \App\Services\StatutoryService::label($h->kind) }}</td>
      <td class="small">{{ \Illuminate\Support\Carbon::parse($h->period.'-01')->format('M Y') }}</td><td class="num">{{ money($h->amount, false) }}</td>
      <td class="small">{{ $h->reference }}@if($h->isCancelled())<div class="tiny text-red">cancelled: {{ $h->cancel_reason }}</div>@endif</td>
      <td>@if(! $h->isCancelled() && can_do('payroll.approve'))<form method="post" action="{{ route('payroll.statutory.cancel', $h) }}" data-confirm="Cancel this payment record? The ledger entry is reversed.">@csrf<input type="hidden" name="reason" value="Recorded in error"><button class="btn btn-sm btn-ghost" title="Cancel"><x-icon name="x" size="sm"/></button></form>@endif</td></tr>
    @empty<tr><td colspan="6"><x-empty text="No statutory payments recorded yet." icon="money"/></td></tr>@endforelse
    </tbody></table></div></div>

  <div class="card"><div class="card-head"><h3><x-icon name="tool"/> Settings</h3></div><div class="card-body">
    @if(can_do('payroll.manage'))
    <form method="post" action="{{ route('payroll.statutory.settings') }}" class="form-grid" id="statSettings">@csrf
      <div class="field"><label for="f_stat_ssnit_employer_no">SSNIT employer number</label><input name="stat_ssnit_employer_no" id="f_stat_ssnit_employer_no" value="{{ $cfg['stat_ssnit_employer_no'] }}" maxlength="30"></div>
      <div class="field"><label for="f_stat_insurable_cap">Maximum insurable earnings / month</label><input type="number" step="0.01" min="0" name="stat_insurable_cap" id="f_stat_insurable_cap" value="{{ $cfg['stat_insurable_cap'] }}" required></div>
      <div class="field"><label for="f_stat_tier2_trustee">Tier 2 corporate trustee</label><input name="stat_tier2_trustee" id="f_stat_tier2_trustee" value="{{ $cfg['stat_tier2_trustee'] }}" maxlength="120"></div>
      <div class="field"><label for="f_stat_tier2_scheme">Tier 2 scheme / employer number</label><input name="stat_tier2_scheme" id="f_stat_tier2_scheme" value="{{ $cfg['stat_tier2_scheme'] }}" maxlength="40"></div>
      <div class="field"><label for="f_payroll_min_wage_daily">National daily minimum wage</label><input type="number" step="0.01" min="0" name="payroll_min_wage_daily" id="f_payroll_min_wage_daily" value="{{ $cfg['payroll_min_wage_daily'] }}" required></div>
      <div class="field"><label for="f_payroll_min_wage_days">Working days a month (for the check)</label><input type="number" min="1" max="31" name="payroll_min_wage_days" id="f_payroll_min_wage_days" value="{{ $cfg['payroll_min_wage_days'] }}" required></div>
      <div class="span-2"><button class="btn btn-primary">Save</button></div>
    </form>
    @else
    <dl class="kv"><dt>SSNIT employer no.</dt><dd>{{ $cfg['stat_ssnit_employer_no'] ?: '—' }}</dd><dt>Tier 2 trustee</dt><dd>{{ $cfg['stat_tier2_trustee'] ?: '—' }}</dd><dt>Minimum wage</dt><dd>{{ money($minWage) }} / month</dd></dl>
    @endif
    <p class="tiny muted mt-1">Tier 1 (13.5 % of basic: employee 5.5 % + employer 8 %) goes to SSNIT and Tier 2 (employer 5 %) to the trustee, both by the 14th of the following month; PAYE goes to GRA by the 15th. A month with a payment recorded cannot be reopened in payroll.</p>
  </div></div>
</div>

@if(in_array($cur['status'], ['due', 'overdue'], true) && can_do('payroll.approve'))
<div class="modal" id="remitModal"><div class="modal-box"><form method="post" action="{{ route('payroll.statutory.remit') }}">@csrf
  <input type="hidden" name="kind" value="{{ $tab }}"><input type="hidden" name="period" value="{{ $period }}">
  <div class="modal-head"><h3>Record payment · {{ $cur['label'] }} · {{ $pl }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <p class="small">Pay <b>{{ money($cur['total']) }}</b> to <b>{{ $cur['payee'] }}</b> ({{ $cur['employees'] }} employees · employee share {{ money($cur['ee']) }} · employer share {{ money($cur['er']) }}).</p>
    <div class="form-grid">
      <x-field name="paid_on" label="Paid on" type="date" required :value="bdate()->toDateString()"/>
      <x-field name="payment_mode_id" label="Paid from" type="select" required :options="$modes" :placeholder="false" :value="$modes->search(fn ($n) => str_contains($n, 'Bank'))"/>
      <x-field name="reference" label="{{ $tab === 'paye' ? 'GRA payment reference' : 'Receipt / payment reference' }}" required maxlength="60" class="span-2"/>
      <x-field name="notes" label="Notes" maxlength="255" class="span-2"/>
    </div>
    <p class="tiny muted">Posts Dr {{ \App\Models\AccountHead::find(\App\Models\AccountHead::sys(\App\Services\StatutoryService::KINDS[$tab][3]))?->name }} / Cr the account paid from.</p>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="remitSave">Record payment</button></div>
</form></div></div>
@endif
@endsection
