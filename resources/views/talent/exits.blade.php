@extends('layouts.app')
@section('title', 'Exits & Turnover')
@section('crumbs') Talent Management / Exits &amp; Turnover @endsection
@section('actions')<button class="btn btn-primary" data-modal="talExitModal" id="talExitNew"><x-icon name="logout"/> Record a leaver</button>@endsection
@section('content')
<div class="stats" id="talTurnover">
  <x-stat label="Turnover · 12 months" :value="$turnover['rate'].'%'" icon="trend" :tone="$turnover['rate'] > 25 ? 'red' : 'green'" :sub="$turnover['leavers'].' leaver(s) / '.number_format($turnover['average'], 1).' average headcount'"/>
  <x-stat label="Voluntary turnover" :value="$turnover['voluntary_rate'].'%'" icon="logout" tone="amber" :sub="$turnover['voluntary'].' resignation(s) / retirement(s)'"/>
  <x-stat label="Regretted leavers" :value="$turnover['regretted']" icon="star" tone="purple" sub="people we wanted to keep"/>
  <x-stat label="Working notice" :value="$rows->where('status', 'notice')->count()" icon="clock" tone="blue"/>
</div>
<div class="g-2-1">
  <div class="card flush"><div class="card-body table-wrap"><table class="table" id="talExitTable"><thead><tr><th>Exit</th><th>Employee</th><th>Type</th><th>Notice</th><th>Last day</th><th>Final pay by</th><th>Status</th></tr></thead><tbody>
  @forelse($rows as $x)@php $done = collect($x->clearance ?? [])->where('done', true)->count(); @endphp<tr class="{{ $x->status === 'notice' && $x->last_day->isPast() && ! $x->last_day->isToday() ? 'row-red' : '' }}">
    <td><a href="{{ route('talent.exit', $x) }}"><b>{{ $x->number }}</b></a></td><td>{{ $x->employee->name }}<div class="tiny muted">{{ $x->employee->designation }}</div></td>
    <td class="small">{{ $x->typeLabel() }}</td><td class="small">{{ $x->notice_days }} d{{ $x->pilon ? ' · pay in lieu' : '' }}</td><td class="small nowrap">{{ $x->last_day->format('d M Y') }}</td><td class="small nowrap">{{ $x->final_pay_due->format('d M Y') }}</td>
    <td>@include('talent._badge', ['s' => $x->status, 'l' => $x->statusLabel()])@if($x->status === 'notice')<div class="tiny muted">clearance {{ $done }}/{{ count(\App\Services\TalentService::CLEARANCE) }}</div>@endif</td></tr>
  @empty<tr><td colspan="7" class="muted">No exits recorded.</td></tr>@endforelse
  </tbody></table></div></div>
  <div class="card"><div class="card-head"><h3>Why people leave</h3></div><div class="card-body" id="talReasons">
    @php $tot = max(1, $reasons->sum()); @endphp
    @forelse($reasons as $k => $n)<div class="tal-funnel-row"><span class="small">{{ \App\Services\TalentService::EXIT_REASONS[$k] ?? $k }}</span><div class="tal-funnel-bar"><i style="width:{{ round($n / $tot * 100) }}%" class="tal-interview"></i></div><b class="small">{{ $n }}</b></div>
    @empty<p class="small muted">No exit interviews yet.</p>@endforelse
  </div></div>
</div>
<div class="modal" id="talExitModal"><div class="modal-box"><form method="post" action="{{ route('talent.exits.store') }}" data-reset>@csrf
  <div class="modal-head"><h3>Record a leaver</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="employee_id" label="Employee" type="select" :options="$employees->mapWithKeys(fn($e) => [$e->id => $e->name.' – '.$e->designation])" required class="span-all"/>
    <x-field name="type" label="Reason" type="select" :options="collect(\App\Models\TalentExit::TYPES)->except('dismissal')" required class="span-all"/>
    <x-field name="notice_on" label="Notice given on" type="date" :value="bdate()->toDateString()" required/>
    <x-field name="last_day" label="Last day (resignation)" type="date" hint="Leave blank to use the statutory notice"/>
    <x-field name="contract_notice_days" label="Contractual notice (days)" type="number" min="0" max="180" hint="If the contract / CBA gives longer notice"/>
    <x-field name="clo_notified_on" label="Chief Labour Officer notified" type="date" hint="Redundancy only – 3 months before (s. 65)"/>
    <x-field name="reason" label="Details" type="textarea" class="span-all"/>
    <label class="check span-all small"><input type="checkbox" name="pilon" value="1"> Pay in lieu of notice (employment ends today)</label>
    <label class="check span-all small"><input type="checkbox" name="fair_confirmed" value="1"> Employer termination: none of the unfair reasons in s. 63 applies</label>
  </div><p class="tiny muted">Notice is worked out under s. 17: two weeks, one month after three years' service, a week for weekly-paid, none for casual workers; at least a month for a worker with a disability (s. 51). Dismissals are decided through a disciplinary case.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Record</button></div>
</form></div></div>
@endsection
