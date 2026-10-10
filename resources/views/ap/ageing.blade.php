@extends('layouts.app')
@section('title', 'Accounts Payable Ageing')
@section('crumbs') Accounts / Payables @endsection
@section('actions')
<form method="get" class="flex gap-1 items-center"><label class="small">As at</label><input type="date" name="as_of" value="{{ $asOf }}" max="{{ bdate()->toDateString() }}" class="input input-sm" style="width:auto"><button class="btn btn-sm">Go</button></form>
<a class="btn" href="{{ route('reports.show', ['ap_ageing', 'to' => $asOf, 'export' => 'csv']) }}"><x-icon name="download"/> CSV</a>
@endsection
@section('content')
<div class="stats">
  <x-stat label="Total payable" :value="money($tot['balance'])" icon="wallet" tone="blue"/>
  <x-stat label="Not yet due" :value="money($tot['current'])" icon="clock" tone="green"/>
  <x-stat label="Overdue" :value="money($tot['d30'] + $tot['d60'] + $tot['d90'] + $tot['d90p'])" icon="alert" :tone="($tot['d30'] + $tot['d60'] + $tot['d90'] + $tot['d90p']) > 0 ? 'red' : 'green'"/>
  <x-stat label="Unapplied credits" :value="money($tot['credit'])" icon="money" tone="amber" sub="advances & debit notes"/>
</div>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="ageTable">
<thead><tr><th>Supplier</th><th class="num">Terms</th><th class="num">Not due</th><th class="num">1–30</th><th class="num">31–60</th><th class="num">61–90</th><th class="num">&gt; 90</th><th class="num">Credits</th><th class="num">Balance</th><th class="num">of which unbilled</th><th></th></tr></thead><tbody>
@forelse($rows as $r)
<tr><td class="bold">{{ $r['supplier'] }}@if($r['oldest'] > 0)<div class="tiny {{ $r['oldest'] > 30 ? 'text-red' : 'muted' }}">oldest {{ $r['oldest'] }} days past due</div>@endif</td><td class="num">{{ $r['terms'] }}d</td>
@foreach(['current', 'd30', 'd60', 'd90', 'd90p'] as $k)<td class="num {{ $k !== 'current' && $r[$k] > 0 ? 'age-bad' : '' }}">{{ $r[$k] ? money($r[$k], false) : '—' }}</td>@endforeach
<td class="num">{{ $r['credit'] ? '−'.money($r['credit'], false) : '—' }}</td><td class="num bold">{{ money($r['balance'], false) }}</td><td class="num muted">{{ $r['unbilled'] ? money($r['unbilled'], false) : '—' }}</td>
<td class="right nowrap"><a class="btn btn-sm" href="{{ route('ap.statement', $r['supplier_id']) }}">Statement</a> @if(can_do('ap.pay'))<a class="btn btn-sm btn-primary" href="{{ route('ap.payments.create', ['supplier' => $r['supplier_id']]) }}">Pay</a>@endif</td></tr>
@empty<tr><td colspan="11" class="empty">Nothing owed to suppliers.</td></tr>@endforelse
</tbody>@if($rows->count())<tfoot><tr class="bold"><td colspan="2">Total</td>@foreach(['current', 'd30', 'd60', 'd90', 'd90p'] as $k)<td class="num">{{ money($tot[$k], false) }}</td>@endforeach<td class="num">−{{ money($tot['credit'], false) }}</td><td class="num">{{ money($tot['balance'], false) }}</td><td class="num">{{ money($tot['unbilled'], false) }}</td><td></td></tr></tfoot>@endif</table></div></div>
<p class="small muted">Buckets are days past the due date (bill date + supplier terms). Goods received but not yet billed are aged from the receipt date. The balance ties to the Accounts Payable control account on the General Ledger page once the day is closed.</p>
@endsection
