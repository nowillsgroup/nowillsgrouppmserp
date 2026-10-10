@extends('layouts.print')
@section('title', 'Statement – '.$m->membership_no)
@section('doctitle', 'MEMBER STATEMENT')
@section('docmeta')<div class="small">{{ $m->membership_no }} · {{ dmy($from) }} – {{ dmy($to) }}<br>Printed {{ now()->format('d M Y H:i') }}</div>@endsection
@section('content')
@php $td = 'padding:6px 8px;border-bottom:1px solid #e2e8f0'; $run = $opening; @endphp
<div class="flex justify-between" style="margin:1rem 0">
  <div><b>{{ $m->company?->name ?? $m->holder_name }}</b>@if($m->company)<br>Re: {{ $m->holder_name }}@endif<br>{{ $m->phone }}{{ $m->email ? ' · '.$m->email : '' }}</div>
  <div class="right small">{{ $m->plan->name }}<br>Paid-through term ends {{ dmy($m->end_date) }}<br>Card holders: {{ $m->members->where('is_active', true)->pluck('name')->implode(', ') }}</div>
</div>
<form method="get" class="no-print flex gap-1 items-end" style="margin-bottom:.8rem"><div class="field"><label for="sf">From</label><input type="date" name="from" id="sf" value="{{ $from }}"></div><div class="field"><label for="st">To</label><input type="date" name="to" id="st" value="{{ $to }}"></div><button class="btn">Show</button></form>
<table style="width:100%;border-collapse:collapse;font-size:13px" id="stmtTable">
  <thead><tr style="background:#f1f5f9;text-align:left"><th style="{{ $td }}">Date</th><th style="{{ $td }}">Ref.</th><th style="{{ $td }}">Details</th><th style="{{ $td }};text-align:right">Debit</th><th style="{{ $td }};text-align:right">Credit</th><th style="{{ $td }};text-align:right">Balance</th></tr></thead>
  <tbody>
    <tr><td style="{{ $td }}">{{ dmy($from) }}</td><td style="{{ $td }}"></td><td style="{{ $td }}"><i>Opening balance</i></td><td style="{{ $td }}"></td><td style="{{ $td }}"></td><td style="{{ $td }};text-align:right">{{ money($opening, false) }}</td></tr>
    @foreach($lines as $l)@php $run = round($run + $l['debit'] - $l['credit'], 2); @endphp
      <tr><td style="{{ $td }}">{{ dmy($l['date']) }}</td><td style="{{ $td }}">{{ $l['ref'] }}</td><td style="{{ $td }}">{{ $l['desc'] }}</td><td style="{{ $td }};text-align:right">{{ $l['debit'] ? money($l['debit'], false) : '' }}</td><td style="{{ $td }};text-align:right">{{ $l['credit'] ? money($l['credit'], false) : '' }}</td><td style="{{ $td }};text-align:right">{{ money($run, false) }}</td></tr>
    @endforeach
  </tbody>
  <tfoot><tr><th colspan="5" style="{{ $td }};text-align:right">{{ $run >= 0 ? 'Balance due' : 'In credit' }}</th><th style="{{ $td }};text-align:right;font-size:15px" id="stmtClose">{{ money(abs($run)) }}</th></tr></tfoot>
</table>
<p class="small" style="margin-top:1.2rem">Pay by Mobile Money, card or bank transfer quoting <b>{{ $m->membership_no }}</b>. Entry to the club is suspended when a charge is more than {{ \App\Services\ClubService::cfg('club_grace_days') }} days overdue. Amounts in Ghana cedis, including VAT, NHIL, GETFund and Tourism Levy.</p>
@endsection
