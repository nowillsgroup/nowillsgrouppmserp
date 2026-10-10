@extends('layouts.app')
@section('title', 'Profit & Loss Statement')
@section('actions')<button class="btn" onclick="window.print()"><x-icon name="print"/> Print</button>@endsection
@section('content')
@php $labels = config('pms.charge_categories') + ['discounts' => 'Less: Discounts & allowances', 'pos_direct' => 'POS sales (direct settlement)']; $exp = $expenses->sum(); @endphp
<div class="card"><div class="card-body"><form class="filters"><div class="field"><label>From</label><input type="date" name="from" value="{{ $from }}"></div><div class="field"><label>To</label><input type="date" name="to" value="{{ $to }}"></div><button class="btn btn-primary">Generate</button></form></div></div>
<div class="grid g-2">
<div class="card flush"><div class="card-head"><h3>Income</h3></div><div class="card-body"><table class="table">
<tbody>@foreach($income as $k=>$v)<tr><td>{{ $labels[$k] ?? $k }}</td><td class="num">{{ money($v, false) }}</td></tr>@endforeach
@foreach($otherIncome as $k=>$v)<tr><td>{{ $k }}</td><td class="num">{{ money($v, false) }}</td></tr>@endforeach</tbody>
<tfoot><tr><td>Total Income</td><td class="num">{{ money($totalIncome) }}</td></tr></tfoot></table></div></div>
<div class="card flush"><div class="card-head"><h3>Expenses</h3></div><div class="card-body"><table class="table">
<tbody>@forelse($expenses as $k=>$v)<tr><td>{{ $k }}</td><td class="num">{{ money($v, false) }}</td></tr>@empty<tr><td colspan="2" class="muted">No expense vouchers.</td></tr>@endforelse</tbody>
<tfoot><tr><td>Total Expenses</td><td class="num">{{ money($exp) }}</td></tr></tfoot></table></div></div>
</div>
<div class="stats"><x-stat label="Net profit / (loss)" :value="money($totalIncome - $exp)" icon="chart" tone="{{ $totalIncome - $exp >= 0 ? 'green' : 'red' }}"/><x-stat label="Taxes collected (liability, excluded)" :value="money($taxCollected)" icon="receipt" tone="slate"/></div>
@endsection
