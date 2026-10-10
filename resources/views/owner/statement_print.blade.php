@extends('layouts.print')
@section('title', 'Owner Pack '.$s->label())
@section('doctitle', 'OWNER REPORT')
@section('pageclass', 'owner-print')
@section('docmeta')<div class="small"><b>{{ $s->label() }}</b> · {{ $snap['fy_label'] ?? '' }}</div>
  @if($snap['agreement'] ?? null)<div class="small">{{ $snap['agreement']['operator'] }} for {{ $snap['agreement']['owner'] }}</div>@if($snap['agreement']['ref'])<div class="small">Agreement {{ $snap['agreement']['ref'] }}</div>@endif @endif
  <div class="small">{{ $s->status === 'draft' ? 'DRAFT – not issued' : 'Issued '.$s->issued_at?->format('d M Y') }}</div>@endsection
@section('content')
<style>@page{size:A4 landscape;margin:10mm}.print-page.owner-print{max-width:1240px;width:auto}@media print{.print-page.owner-print{max-width:none}}</style>
<h3 class="op-h">General manager's commentary</h3>
<div class="pack-commentary small">{{ $s->commentary }}</div>

<h3 class="op-h">Summary Operating Statement (GH₵)</h3>
@include('owner._usali', ['cols' => $snap['cols'], 'layout' => $layout, 'drill' => null])

<div class="grid g-2 mt-1 op-avoid">
  <div><h3 class="op-h">Key performance indicators</h3>@include('owner._kpis', ['kpis' => $snap['kpis']])</div>
  <div>
    <h3 class="op-h">Management fees</h3>
    @if($f = $snap['fee'] ?? null)
      <table class="table table-compact"><tbody>
        <tr><td>Total operating revenue</td><td class="num">{{ money($f['total_revenue'], false) }}</td></tr>
        <tr><td>Base fee ({{ $snap['agreement']['base'] ?? '' }}%)</td><td class="num">{{ money($f['base_fee'], false) }}</td></tr>
        <tr><td>FF&E reserve ({{ rtrim(rtrim(number_format($f['ffe_pct'], 2), '0'), '.') }}%)</td><td class="num">{{ money($f['ffe_amount'], false) }}</td></tr>
        <tr><td>{{ ($snap['agreement']['basis'] ?? 'agop') === 'agop' ? 'Adjusted GOP' : 'GOP' }}</td><td class="num">{{ money($f['agop'], false) }}</td></tr>
        <tr><td>Fee-year AGOP / owner's priority</td><td class="num">{{ money($f['agop_ytd'], false) }} / {{ money($f['priority_ytd'], false) }}</td></tr>
        <tr><td>Incentive fee ({{ $snap['agreement']['incentive'] ?? '' }}%)</td><td class="num">{{ money($f['incentive_fee'], false) }}</td></tr>
        <tr class="bold-row"><td>Total fees this month</td><td class="num">{{ money($f['total_fee'], false) }}</td></tr>
        <tr><td>Fee year to date</td><td class="num">{{ money($snap['fees_ytd'], false) }}</td></tr>
      </tbody></table>
    @else<p class="small">No management fees for the month.</p>@endif
    <h3 class="op-h">FF&E reserve</h3>
    <table class="table table-compact"><tbody><tr><td>Reserve balance at month end</td><td class="num">{{ money($snap['reserve']['balance'], false) }}</td></tr>
      @if($snap['reserve']['funded'] !== null)<tr><td>Cash in the reserve account</td><td class="num">{{ money($snap['reserve']['funded'], false) }}</td></tr>@endif</tbody></table>
  </div>
</div>

@if($snap['capex'])
<h3 class="op-h">Capital projects</h3>
<table class="table table-compact"><thead><tr><th>Project</th><th>Funding</th><th>Status</th><th class="num">Budget</th><th class="num">Spent</th></tr></thead><tbody>
  @foreach($snap['capex'] as $c)<tr><td>{{ $c['number'] }} {{ $c['title'] }}</td><td>{{ $c['funding'] }}</td><td>{{ $c['status'] }}</td><td class="num">{{ money($c['budget'], false) }}</td><td class="num">{{ money($c['spent'], false) }}</td></tr>@endforeach
</tbody></table>
@endif

@if($s->queries->count())
<h3 class="op-h">Owner queries</h3>
@foreach($s->queries as $q)<div class="small op-avoid" style="margin-bottom:.5rem"><b>Q ({{ $q->raiser?->name }}, {{ $q->created_at->format('d M') }}):</b> {{ $q->body }}@if($q->reply)<br><b>A ({{ $q->replier?->name }}):</b> {{ $q->reply }}@endif</div>@endforeach
@endif
<p class="tiny muted mt-1">Prepared under the Uniform System of Accounts for the Lodging Industry, 11th revised edition. Figures from the general ledger{{ ($snap['generated_at'] ?? null) ? ' as at '.\Illuminate\Support\Carbon::parse($snap['generated_at'])->format('d M Y H:i') : '' }}.</p>
@endsection
