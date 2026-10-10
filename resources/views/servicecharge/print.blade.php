@extends('layouts.print')
@section('title', $d->dist_no)
@section('doctitle', 'SERVICE CHARGE DISTRIBUTION')
@section('docmeta')<div class="small">No. <b>{{ $d->dist_no }}</b><br>Period {{ $d->label() }}<br>Status: <b>{{ strtoupper($d->status) }}</b></div>@endsection
@section('content')
@php $td = 'padding:5px 7px;border-bottom:1px solid #e2e8f0;font-size:13px'; @endphp
<table style="width:100%;border-collapse:collapse;margin-top:1rem;font-size:13px">
  <tr><td style="{{ $td }}">Collected (service charge &amp; tips)</td><td style="{{ $td }};text-align:right">{{ money($d->collected) }}</td></tr>
  <tr><td style="{{ $td }}">Less house retention {{ rtrim(rtrim(number_format((float) $d->retention_pct, 2), '0'), '.') }}%</td><td style="{{ $td }};text-align:right">({{ money($d->retained) }})</td></tr>
  <tr><td style="{{ $td }}">Add rounding carried in</td><td style="{{ $td }};text-align:right">{{ money($d->carried_in) }}</td></tr>
  <tr><td style="{{ $td }}"><b>Pool</b></td><td style="{{ $td }};text-align:right"><b>{{ money($d->pool) }}</b></td></tr>
</table>
<table style="width:100%;border-collapse:collapse;margin-top:1rem" id="scPrintTable">
  <tr style="background:#f1f5f9"><th style="{{ $td }};text-align:left">Employee</th><th style="{{ $td }};text-align:left">Department</th><th style="{{ $td }};text-align:right">Points</th><th style="{{ $td }};text-align:right">Days</th><th style="{{ $td }};text-align:right">Amount</th><th style="{{ $td }}">Signature</th></tr>
  @foreach($d->lines->where('amount', '>', 0)->sortBy(fn ($l) => [$l->department?->name, $l->employee?->name]) as $l)
  <tr><td style="{{ $td }}">{{ $l->employee?->name }} ({{ $l->employee?->emp_code }})</td><td style="{{ $td }}">{{ $l->department?->name }}</td><td style="{{ $td }};text-align:right">{{ rtrim(rtrim(number_format((float) $l->points, 2), '0'), '.') }}</td><td style="{{ $td }};text-align:right">{{ rtrim(rtrim(number_format((float) $l->days, 1), '0'), '.') }}</td><td style="{{ $td }};text-align:right">{{ money($l->amount, false) }}</td><td style="{{ $td }};width:22%"></td></tr>
  @endforeach
  <tr><td style="{{ $td }}" colspan="4"><b>Total distributed</b> (rounding carried forward {{ money($d->carry_out) }})</td><td style="{{ $td }};text-align:right"><b>{{ money($d->distributed, false) }}</b></td><td style="{{ $td }}"></td></tr>
</table>
<p class="small" style="margin-top:.6rem">Paid through payroll {{ $d->run?->run_no ?? '(not yet posted)' }} as a taxable earning (PAYE applies).</p>
<table style="width:100%;margin-top:2.5rem;font-size:13px"><tr><td>Prepared: {{ $d->creator?->name }}</td><td>Approved: {{ $d->approver?->name ?? '________________' }}</td><td>Staff representative: ________________</td></tr></table>
@endsection
