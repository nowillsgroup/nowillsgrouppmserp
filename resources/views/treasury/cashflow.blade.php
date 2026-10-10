@extends('layouts.app')
@section('title', 'Cash-flow Statement')
@section('crumbs') Treasury / Cash-flow statement @endsection
@php
  $pr = fn (string $sec) => collect($prior[$sec])->keyBy('id');
  $prW = $pr('working'); $prN = $pr('noncash'); $prI = $pr('investing'); $prF = $pr('financing');
  $fmt = fn ($v) => $v === null ? '' : ($v < 0 ? '('.number_format(abs($v), 2).')' : number_format($v, 2));
  $F = \Illuminate\Support\Carbon::parse($from); $T = \Illuminate\Support\Carbon::parse($to);
@endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['cash_flow', 'from' => $from, 'to' => $to]) }}"><x-icon name="download"/> Report / export</a>
  <a class="btn" href="{{ route('treasury.accounts') }}#cfCard"><x-icon name="tool"/> Classification</a>
@endsection
@section('content')
<form method="get" class="card"><div class="card-body flex gap-1 wrap items-center">
  <label class="small">From <input type="date" name="from" value="{{ $from }}"></label>
  <label class="small">To <input type="date" name="to" value="{{ $to }}"></label>
  <button class="btn btn-sm">Show</button>
  @foreach(['This month' => [bdate()->copy()->startOfMonth(), bdate()], 'Last month' => [bdate()->copy()->subMonthNoOverflow()->startOfMonth(), bdate()->copy()->subMonthNoOverflow()->endOfMonth()], 'Fiscal year to date' => [\Illuminate\Support\Carbon::parse(\App\Services\CloseService::fyStart(\App\Services\BudgetService::currentFy())), bdate()]] as $l => [$a, $b])
    <a class="btn btn-sm btn-ghost" href="{{ route('treasury.cashflow', ['from' => $a->toDateString(), 'to' => $b->toDateString()]) }}">{{ $l }}</a>
  @endforeach
</div></form>

@if(abs($cf['difference']) >= 0.01)
  <div class="alert alert-error mt-1" id="cfDiff"><x-icon name="alert"/> Opening cash plus the net change differs from closing cash by {{ money($cf['difference']) }} – check the cash-flow classification of the Cash &amp; Bank accounts.</div>
@endif

<div class="stats tr-stats mt-1">
  <x-stat label="Operating activities" :value="money($cf['operating'])" icon="hotel" :tone="$cf['operating'] >= 0 ? 'green' : 'red'"/>
  <x-stat label="Investing activities" :value="money($cf['investing_total'])" icon="tool" tone="blue"/>
  <x-stat label="Financing activities" :value="money($cf['financing_total'])" icon="building" tone="purple"/>
  <x-stat label="Net change in cash" :value="money($cf['net'])" icon="wallet" :tone="$cf['net'] >= 0 ? 'green' : 'amber'" :sub="money($cf['opening']).' → '.money($cf['closing'])"/>
</div>

<div class="card flush mt-1" id="cfStatement"><div class="card-head"><h3><x-icon name="book"/> Statement of cash flows – indirect method (IAS 7)</h3><span class="small muted">{{ $F->format('d M Y') }} – {{ $T->format('d M Y') }}</span></div>
<div class="card-body table-wrap"><table class="table cf-table">
  <thead><tr><th></th><th class="num">{{ $F->format('d M y') }} – {{ $T->format('d M y') }}</th><th class="num muted">Same period last year</th></tr></thead>
  <tbody>
    <tr class="cf-head"><td colspan="3">Cash flows from operating activities</td></tr>
    <tr><td>Net profit / (loss) for the period</td><td class="num">{{ $fmt($cf['profit']) }}</td><td class="num muted">{{ $fmt($prior['profit']) }}</td></tr>
    @if(count($cf['noncash']))<tr class="cf-sub"><td colspan="3">Adjustments for non-cash items</td></tr>@endif
    @foreach($cf['noncash'] as $l)<tr><td class="cf-in">{{ $l['label'] }}</td><td class="num">{{ $fmt($l['amount']) }}</td><td class="num muted">{{ $fmt($prN[$l['id']]['amount'] ?? null) }}</td></tr>@endforeach
    @if(count($cf['working']))<tr class="cf-sub"><td colspan="3">Changes in working capital</td></tr>@endif
    @foreach($cf['working'] as $l)<tr data-acct="{{ $l['code'] }}"><td class="cf-in">{{ $l['label'] }} <span class="tiny muted">{{ $l['code'] }}</span></td><td class="num">{{ $fmt($l['amount']) }}</td><td class="num muted">{{ $fmt($prW[$l['id']]['amount'] ?? null) }}</td></tr>@endforeach
    <tr class="cf-total"><td>Net cash from operating activities</td><td class="num" id="cfOperating">{{ $fmt($cf['operating']) }}</td><td class="num muted">{{ $fmt($prior['operating']) }}</td></tr>

    <tr class="cf-head"><td colspan="3">Cash flows from investing activities</td></tr>
    @forelse($cf['investing'] as $l)<tr><td class="cf-in">{{ $l['amount'] < 0 ? 'Purchase of' : 'Proceeds from' }} {{ lcfirst($l['label']) }} <span class="tiny muted">{{ $l['code'] }}</span></td><td class="num">{{ $fmt($l['amount']) }}</td><td class="num muted">{{ $fmt($prI[$l['id']]['amount'] ?? null) }}</td></tr>
    @empty<tr><td class="cf-in muted">None</td><td></td><td></td></tr>@endforelse
    <tr class="cf-total"><td>Net cash from investing activities</td><td class="num">{{ $fmt($cf['investing_total']) }}</td><td class="num muted">{{ $fmt($prior['investing_total']) }}</td></tr>

    <tr class="cf-head"><td colspan="3">Cash flows from financing activities</td></tr>
    @forelse($cf['financing'] as $l)<tr><td class="cf-in">{{ $l['label'] }} <span class="tiny muted">{{ $l['code'] }}</span></td><td class="num">{{ $fmt($l['amount']) }}</td><td class="num muted">{{ $fmt($prF[$l['id']]['amount'] ?? null) }}</td></tr>
    @empty<tr><td class="cf-in muted">None</td><td></td><td></td></tr>@endforelse
    <tr class="cf-total"><td>Net cash from financing activities</td><td class="num">{{ $fmt($cf['financing_total']) }}</td><td class="num muted">{{ $fmt($prior['financing_total']) }}</td></tr>

    <tr class="cf-grand"><td>Net increase / (decrease) in cash and cash equivalents</td><td class="num" id="cfNet">{{ $fmt($cf['net']) }}</td><td class="num muted">{{ $fmt($prior['net']) }}</td></tr>
    <tr><td>Cash and cash equivalents at the beginning of the period</td><td class="num">{{ $fmt($cf['opening']) }}</td><td class="num muted">{{ $fmt($prior['opening']) }}</td></tr>
    <tr class="cf-grand"><td>Cash and cash equivalents at the end of the period</td><td class="num" id="cfClosing">{{ $fmt($cf['closing']) }}</td><td class="num muted">{{ $fmt($prior['closing']) }}</td></tr>
  </tbody>
</table></div></div>
<p class="tiny muted mt-1">Built from general-ledger movements for the period (year-end closing entries excluded). Cash and cash equivalents are the bank, Mobile Money, cash, petty cash, foreign currency, cheques-in-hand and cash-in-transit accounts; card and gateway clearing accounts are receivables (operating). Change the classification of any balance-sheet account under <a href="{{ route('treasury.accounts') }}#cfCard">Bank accounts › Cash-flow classification</a>.</p>
@endsection
