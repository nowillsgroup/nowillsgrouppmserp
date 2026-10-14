@extends('layouts.app')
@section('title', 'Supplier Payments')
@section('crumbs') Accounts / Payables @endsection
@section('actions')
<a class="btn" href="{{ route('ap.bills') }}"><x-icon name="receipt"/> Bills</a>
@if(can_do('ap.pay'))<a class="btn btn-primary" href="{{ route('ap.payments.create') }}"><x-icon name="money"/> Pay supplier</a>@endif
@endsection
@section('content')
<form class="filters mb-1 flex gap-1 wrap items-center" method="get">
  <select name="supplier" class="input input-sm" style="width:auto;min-width:200px" onchange="this.form.submit()"><option value="">All suppliers</option>@foreach($suppliers as $s)<option value="{{ $s->id }}" @selected(request('supplier') == $s->id)>{{ $s->name }}</option>@endforeach</select>
  <input type="date" name="from" value="{{ request('from') }}" class="input input-sm" style="width:auto"> – <input type="date" name="to" value="{{ request('to') }}" class="input input-sm" style="width:auto">
  <input name="q" value="{{ request('q') }}" placeholder="Payment no. / reference" class="input input-sm" style="width:190px">
  <button class="btn btn-sm">Filter</button>
</form>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="payTable">
<thead><tr><th>Payment</th><th>Date</th><th>Supplier</th><th>Mode</th><th>Reference</th><th class="num">Amount</th><th class="num">Allocated</th><th class="num">Unapplied</th><th></th></tr></thead><tbody>
@forelse($payments as $p)
<tr class="{{ $p->cancelled_at ? 'muted' : '' }}"><td><a class="bold" href="{{ route('ap.payments.show', $p) }}">{{ $p->payment_no }}</a></td><td class="nowrap">{{ $p->date->format('d M Y') }}</td><td>{{ $p->supplier->name }}</td><td>{{ $p->paymentMode?->name }}</td><td>{{ $p->reference ?? '—' }}</td>
<td class="num">{{ money($p->amount, false) }}@if($p->isForeign())<div class="tiny muted">{{ $p->fxLabel() }} @ {{ number_format((float) $p->fx_rate, 4) }}{{ $p->fxDifference() != 0 ? ' · '.money(abs($p->fxDifference()), false).' '.($p->fxDifference() > 0 ? 'loss' : 'gain') : '' }}</div>@endif</td><td class="num">{{ money($p->allocated, false) }}</td><td class="num {{ $p->unapplied() > 0 ? 'text-amber bold' : '' }}">{{ money($p->unapplied(), false) }}</td>
<td>@if($p->cancelled_at)<span class="badge badge-red">Cancelled</span>@elseif($p->unapplied() > 0)<span class="badge badge-amber">Credit</span>@else<span class="badge badge-green">Allocated</span>@endif</td></tr>
@empty<tr><td colspan="9" class="empty">No supplier payments yet.</td></tr>@endforelse
</tbody></table></div></div>
{{ $payments->links('partials.pagination') }}
@endsection
