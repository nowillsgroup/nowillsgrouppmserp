@extends('layouts.app')
@section('title', 'USALI Statement')
@section('crumbs') Owner Reporting / USALI statement @endsection
@php $fmtFlow = fn ($v) => $v === null ? '–' : number_format($v, 1).'%'; @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'usali_summary', 'from' => $st['month']->format('Y-m-01'), 'to' => $st['month']->copy()->endOfMonth()->toDateString()]) }}"><x-icon name="download"/> Report / export</a>
  <button class="btn" onclick="window.print()"><x-icon name="print"/> Print</button>
@endsection
@section('content')
<form class="filters card card-body" method="get" id="usaliFilter">
  <div class="field"><label for="uPeriod">Month</label><select name="period" id="uPeriod" onchange="this.form.submit()">@foreach($periods as $k => $l)<option value="{{ $k }}" @selected($k === $period)>{{ $l }}</option>@endforeach</select></div>
  <div class="field"><label>Format</label><div class="seg">
    <a class="btn btn-sm {{ $format === 'operator' ? 'btn-primary' : '' }}" href="{{ route('owner.usali', ['period' => $period, 'format' => 'operator']) }}" id="fmtOperator">Operator (EBITDA less reserve)</a>
    <a class="btn btn-sm {{ $format === 'owner' ? 'btn-primary' : '' }}" href="{{ route('owner.usali', ['period' => $period, 'format' => 'owner']) }}" id="fmtOwner">Owner (to net income)</a></div></div>
  <div class="small muted" style="margin-left:auto;max-width:28rem">{{ $fyLabel }} · fiscal month {{ $st['m'] }}.@if($st['mtd']) <b>Month to date</b> – the night audit has not closed the whole month.@endif Click a line to see its departmental schedule.</div>
</form>

<div class="card flush mt-1" id="statementCard"><div class="card-head"><h3><x-icon name="chart"/> Summary Operating Statement – {{ $st['month']->format('F Y') }}</h3><span class="tiny muted">GH₵ · USALI 11th edition</span></div>
  <div class="card-body">@include('owner._usali', ['cols' => $st['cols'], 'layout' => $layout, 'drill' => $period])</div>
</div>

<div class="grid g-2-1 mt-1">
  <div class="card flush" id="kpis"><div class="card-head"><h3><x-icon name="star"/> Key performance indicators</h3></div>
    <div class="card-body">@include('owner._kpis', ['kpis' => $kpis])</div></div>
  <div class="card" id="flowCard"><div class="card-head"><h3><x-icon name="trend"/> Flow-through</h3></div><div class="card-body">
    <p class="tiny muted" style="margin-top:0">The share of a change in revenue that reached gross operating profit. 50% or more is generally good; above 100% means costs fell while revenue rose.</p>
    <dl class="kv"><dt>Month vs budget</dt><dd id="flowBud">{{ $fmtFlow($flow['bud']) }}</dd><dt>Month vs last year</dt><dd id="flowLy">{{ $fmtFlow($flow['ly']) }}</dd><dt>YTD vs budget</dt><dd>{{ $fmtFlow($flow['ybud']) }}</dd></dl>
    <hr>
    <dl class="kv"><dt>Labour cost</dt><dd>{{ money($st['cols']['m_act']['labor']) }}</dd><dt>F&B cost of sales</dt><dd>{{ money($st['cols']['m_act']['fnb_cos']) }}</dd><dt>Ledger profit</dt><dd id="glNet">{{ money($st['cols']['m_act']['gl_net']) }}</dd></dl>
    <p class="tiny muted">Ledger profit = income − expenses in the general ledger for the month; it equals net income plus any accounts excluded from the statement.</p>
  </div></div>
</div>
@endsection
