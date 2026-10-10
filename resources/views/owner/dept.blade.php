@extends('layouts.app')
@section('title', 'Schedule – '.$title)
@section('crumbs') Owner Reporting / <a href="{{ route('owner.usali', ['period' => $period]) }}">USALI statement</a> / {{ $title }} @endsection
@php
  $U = \App\Services\UsaliService::class;
  $keys = ['m_act' => 'Month', 'm_bud' => 'Budget', 'y_act' => 'YTD', 'y_bud' => 'YTD budget'];
  // merge the four schedules into sections → categories → accounts
  $secs = [];
  foreach ($keys as $k => $l) {
      foreach ($sch[$k] as $sec) {
          $d = $sec['dept'];
          $secs[$d] ??= ['label' => $sec['label'], 'cats' => [], 'revenue' => [], 'expense' => [], 'profit' => []];
          $secs[$d]['revenue'][$k] = $sec['revenue']; $secs[$d]['expense'][$k] = $sec['expense']; $secs[$d]['profit'][$k] = $sec['profit'];
          foreach ($sec['cats'] as $cat => $c) {
              $secs[$d]['cats'][$cat] ??= ['label' => $c['label'], 'lines' => [], 'total' => []];
              $secs[$d]['cats'][$cat]['total'][$k] = $c['total'];
              foreach ($c['lines'] as $ln) {
                  $secs[$d]['cats'][$cat]['lines'][$ln['head_id']] ??= ['code' => $ln['code'], 'name' => $ln['name'], 'split' => $ln['split'], 'v' => []];
                  $secs[$d]['cats'][$cat]['lines'][$ln['head_id']]['v'][$k] = $ln['amount'];
              }
          }
      }
  }
  $catOrder = array_keys($U::CATS);
  $isRevenueDept = fn ($d) => in_array($d, ['misc', 'nonop_income'], true);
@endphp
@section('actions')
  <a class="btn" href="{{ route('owner.usali', ['period' => $period]) }}">← Statement</a>
@endsection
@section('content')
<form class="filters card card-body" method="get">
  <div class="field"><label for="dPeriod">Month</label><select name="period" id="dPeriod" onchange="this.form.submit()">@foreach($periods as $k => $l)<option value="{{ $k }}" @selected($k === $period)>{{ $l }}</option>@endforeach</select></div>
  <div class="field"><label for="dCode">Schedule</label><select id="dCode" onchange="location.href=this.value">
    @foreach(array_merge($U::OPERATED, ['ood' => 'Other Operated Departments', 'misc' => 'Miscellaneous Income'], $U::UNDISTRIBUTED, ['mgmt' => 'Management Fees', 'nonop' => 'Non-Operating Income and Expenses', 'below' => 'Interest, Depreciation, Amortization and Taxes']) as $k => $l)
      <option value="{{ route('owner.usali.dept', ['dept' => $k, 'period' => $period]) }}" @selected($k === $code)>{{ $l }}</option>@endforeach</select></div>
  <div class="small muted" style="margin-left:auto">{{ $fyLabel }}. Accounts marked <span class="badge badge-slate">split</span> are payroll costs shared across departments in proportion to each department's payroll.</div>
</form>

@forelse($secs as $d => $s)
  @continue(! $s['cats'])
  <div class="card flush mt-1" data-section="{{ $d }}"><div class="card-head"><h3>{{ $s['label'] }}</h3>
    @if(! $isRevenueDept($d) && array_sum($s['revenue']))<span class="small">Departmental profit this month <b>{{ money($s['profit']['m_act'] ?? 0) }}</b>@if(($s['revenue']['m_act'] ?? 0) > 0) ({{ number_format(($s['profit']['m_act'] ?? 0) * 100 / $s['revenue']['m_act'], 1) }}%)@endif</span>@endif</div>
  <div class="card-body table-wrap"><table class="table table-compact usali-sched">
    <thead><tr><th>Account</th>@foreach($keys as $k => $l)<th class="num">{{ $l }}</th>@endforeach<th class="num">% of rev.</th></tr></thead>
    <tbody>
    @foreach($catOrder as $cat) @continue(! isset($s['cats'][$cat])) @php $c = $s['cats'][$cat]; @endphp
      <tr class="usali-head"><td colspan="6">{{ $c['label'] }}</td></tr>
      @foreach(collect($c['lines'])->sortBy('code') as $hid => $ln)
        <tr data-head="{{ $hid }}"><td class="usali-in">{{ $ln['code'] }} {{ $ln['name'] }}@if($ln['split']) <span class="badge badge-slate">split</span>@endif</td>
          @foreach($keys as $k => $l)<td class="num">{{ isset($ln['v'][$k]) ? money($ln['v'][$k], false) : '' }}</td>@endforeach
          <td class="num pct">{{ ($s['revenue']['m_act'] ?? 0) > 0 && isset($ln['v']['m_act']) ? number_format($ln['v']['m_act'] * 100 / $s['revenue']['m_act'], 1) : '' }}</td></tr>
      @endforeach
      <tr class="usali-total"><td>Total {{ strtolower($c['label']) }}</td>@foreach($keys as $k => $l)<td class="num">{{ money($c['total'][$k] ?? 0, false) }}</td>@endforeach<td></td></tr>
    @endforeach
    @if(! $isRevenueDept($d) && array_sum($s['revenue']))
      <tr class="usali-strong"><td>Departmental profit</td>@foreach($keys as $k => $l)<td class="num">{{ money($s['profit'][$k] ?? 0, false) }}</td>@endforeach<td></td></tr>
    @endif
    </tbody>
  </table></div></div>
@empty
  <div class="card mt-1"><div class="empty">Nothing posted to this schedule in the period.</div></div>
@endforelse
@if(collect($secs)->every(fn ($s) => ! $s['cats']) && $secs)
  <div class="card mt-1"><div class="empty">Nothing posted to this schedule in the period.</div></div>
@endif

@if($shares)
  <div class="card mt-1" id="payrollShares"><div class="card-head"><h3><x-icon name="users"/> Payroll split for {{ \Illuminate\Support\Carbon::parse($period.'-01')->format('F Y') }}</h3></div><div class="card-body small">
    @foreach($shares as $d => $sh)<span class="chip-lite">{{ $U::label($d) }} <b>{{ number_format($sh * 100, 1) }}%</b></span>@endforeach
  </div></div>
@endif
@endsection
