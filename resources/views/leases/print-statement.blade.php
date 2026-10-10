@extends('layouts.print')
@section('title', 'Statement '.$l->lease_no)
@section('doctitle', 'TENANT STATEMENT')
@section('docmeta')<div class="small">Lease <b>{{ $l->lease_no }}</b> · {{ $l->unit->code }}<br>{{ dmy($from) }} – {{ dmy($to) }}</div>@endsection
@section('content')
@php $td = 'padding:6px 8px;border-bottom:1px solid #e2e8f0'; $run = $opening; @endphp
<div style="margin:1rem 0"><b>{{ $l->company?->name ?? $l->tenant_name }}</b>@if($l->company)<br>Occupant: {{ $l->tenant_name }}@endif<br>{{ $l->unit->code }} – {{ $l->unit->name }} · {{ dmy($l->start_date) }} to {{ dmy($l->end_date) }}</div>
<table style="width:100%;border-collapse:collapse;font-size:13px" id="stmtTable">
  <thead><tr style="background:#f1f5f9;text-align:left"><th style="{{ $td }}">Date</th><th style="{{ $td }}">Ref</th><th style="{{ $td }}">Description</th><th style="{{ $td }};text-align:right">Debit</th><th style="{{ $td }};text-align:right">Credit</th><th style="{{ $td }};text-align:right">Balance</th></tr></thead>
  <tbody><tr><td style="{{ $td }}" colspan="5"><i>Opening balance</i></td><td style="{{ $td }};text-align:right">{{ money($opening, false) }}</td></tr>
  @foreach($lines as $x)@php $run += $x['debit'] - $x['credit']; @endphp
    <tr><td style="{{ $td }}">{{ dmy($x['date']) }}</td><td style="{{ $td }}">{{ $x['ref'] }}</td><td style="{{ $td }}">{{ $x['desc'] }}</td><td style="{{ $td }};text-align:right">{{ $x['debit'] ? money($x['debit'], false) : '' }}</td><td style="{{ $td }};text-align:right">{{ $x['credit'] ? money($x['credit'], false) : '' }}</td><td style="{{ $td }};text-align:right">{{ money($run, false) }}</td></tr>
  @endforeach</tbody>
  <tfoot><tr><th style="{{ $td }};text-align:right" colspan="5">{{ $run >= 0 ? 'Balance due' : 'In credit' }}</th><th style="{{ $td }};text-align:right;font-size:15px">{{ money(abs($run)) }}</th></tr></tfoot>
</table>
@if($overdue[0] > 0)<p class="small" style="color:#b91c1c;margin-top:.6rem"><b>{{ money($overdue[0]) }} is overdue since {{ dmy($overdue[1]) }}.</b> Please pay without delay.</p>@endif
<p class="small">Security deposit held (not included above): {{ money($deposit) }}</p>
@endsection
