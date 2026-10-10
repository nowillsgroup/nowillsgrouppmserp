@extends('layouts.app')
@section('title', 'Lease Billing & Arrears')
@section('crumbs') <a href="{{ route('leases.index') }}">Apartments &amp; Leases</a> / Billing &amp; arrears @endsection
@section('actions')
@if(can_do('leases.billing'))<form method="post" action="{{ route('leases.billing.run') }}" style="display:inline">@csrf<button class="btn btn-primary" id="runBilling"><x-icon name="refresh"/> Run billing now</button></form>@endif
@endsection
@section('content')
<div class="stats">
  <x-stat label="Tenant receivables" :value="money($receivable)" icon="wallet" tone="blue" :sub="'at '.dmy($to).' · GL 1130'"/>
  <x-stat label="In arrears" :value="money($arrears->sum('amount'))" icon="alert" :tone="$arrears->count() ? 'red' : 'green'" :sub="$arrears->count().' tenant(s) · '.$grace.' days grace'"/>
  <x-stat label="Deposits held" :value="money($deposits)" icon="lock" tone="purple" sub="GL 2140 – a liability"/>
  <x-stat label="Invoiced in period" :value="money($charges->whereNull('cancelled_at')->sum('total'))" icon="receipt" tone="green" :sub="money($receipts->whereNull('voided_at')->where('kind', 'payment')->sum('amount')).' received'"/>
</div>

<div class="card flush mt-1" id="arrears"><div class="card-head"><h3><x-icon name="alert"/> Arrears ageing</h3></div><div class="card-body table-wrap"><table class="table" id="arrearsTable">
  <thead><tr><th>Lease</th><th>Unit</th><th>Payer</th><th>Oldest due</th><th class="num">0–30</th><th class="num">31–60</th><th class="num">61–90</th><th class="num">90+</th><th class="num">Overdue</th><th class="num">Balance</th></tr></thead>
  <tbody>@forelse($arrears as $a)
    <tr data-lease="{{ $a['lease']->lease_no }}"><td><a class="bold" href="{{ route('leases.show', $a['lease']) }}">{{ $a['lease']->lease_no }}</a> {{ $a['lease']->tenant_name }}</td><td class="small">{{ $a['lease']->unit->code }}</td><td class="small">{{ $a['lease']->company?->name ?? 'individual' }}<div class="tiny muted">{{ $a['lease']->phone }}</div></td>
      <td class="small">{{ dmy($a['since']) }}</td>@foreach($a['buckets'] as $b)<td class="num">{{ $b > 0 ? money($b, false) : '' }}</td>@endforeach<td class="num bold text-red">{{ money($a['amount'], false) }}</td><td class="num">{{ money($a['balance'], false) }}</td></tr>
  @empty <tr><td colspan="10"><x-empty text="No arrears." icon="check"/></td></tr> @endforelse</tbody>
  @if($arrears->count())<tfoot><tr class="bold"><td colspan="4">Total</td>@foreach([0, 1, 2, 3] as $i)<td class="num">{{ money($arrears->sum(fn ($a) => $a['buckets'][$i]), false) }}</td>@endforeach<td class="num">{{ money($arrears->sum('amount'), false) }}</td><td></td></tr></tfoot>@endif
</table></div></div>

<div class="card flush mt-1" id="upcoming"><div class="card-head"><h3><x-icon name="calendar"/> Next invoices</h3><span class="small muted">raised automatically at the night audit {{ $ahead }} days before each period</span></div><div class="card-body table-wrap"><table class="table">
  <thead><tr><th>Lease</th><th>Unit</th><th>Next period from</th><th>Cycle</th><th class="num">Net (rent + service)</th></tr></thead>
  <tbody>@forelse($upcoming->take(15) as $u)
    <tr><td><a href="{{ route('leases.show', $u['lease']) }}" class="bold">{{ $u['lease']->lease_no }}</a> {{ $u['lease']->tenant_name }}</td><td class="small">{{ $u['lease']->unit->code }}</td><td class="small">{{ dmy($u['next']) }}</td><td class="small">{{ \App\Models\Lease::CYCLES[$u['lease']->cycle_months] }}</td><td class="num">{{ money($u['amount'], false) }}</td></tr>
  @empty <tr><td colspan="5"><x-empty text="Every live lease is invoiced to its end." icon="calendar"/></td></tr> @endforelse</tbody></table></div></div>

<form class="filters mt-1" method="get"><div class="field"><label>From</label><input type="date" name="from" value="{{ $from }}"></div><div class="field"><label>To</label><input type="date" name="to" value="{{ $to }}"></div><button class="btn">Show</button></form>
<div class="grid g-2" style="gap:1rem">
  <div class="card flush"><div class="card-head"><h3>Invoices &amp; credit notes</h3></div><div class="card-body table-wrap"><table class="table" id="chargesList">
    <thead><tr><th>No.</th><th>Date</th><th>Lease</th><th>Type</th><th class="num">Total</th></tr></thead>
    <tbody>@forelse($charges as $c)<tr class="{{ $c->cancelled_at ? 'muted' : '' }}"><td><a href="{{ route('leases.charges.invoice', $c) }}" target="_blank">{{ $c->charge_no }}</a></td><td class="small">{{ dmy($c->date) }}</td><td class="small"><a href="{{ route('leases.show', $c->lease_id) }}">{{ $c->lease->unit->code }}</a> {{ \Illuminate\Support\Str::limit($c->lease->tenant_name, 20) }}</td><td class="small">{{ \App\Models\LeaseCharge::TYPES[$c->type] }}{{ $c->cancelled_at ? ' (cancelled)' : '' }}</td><td class="num">{{ money($c->total, false) }}</td></tr>
    @empty <tr><td colspan="5"><x-empty text="None in the period." icon="receipt"/></td></tr> @endforelse</tbody></table></div></div>
  <div class="card flush"><div class="card-head"><h3>Receipts, deposits &amp; refunds</h3></div><div class="card-body table-wrap"><table class="table" id="receiptsList">
    <thead><tr><th>No.</th><th>Date</th><th>Lease</th><th>Type</th><th class="num">Amount</th></tr></thead>
    <tbody>@forelse($receipts as $p)<tr class="{{ $p->voided_at ? 'muted' : '' }}"><td><a href="{{ route('leases.receipts.show', $p) }}" target="_blank">{{ $p->receipt_no }}</a></td><td class="small">{{ dmy($p->date) }}</td><td class="small"><a href="{{ route('leases.show', $p->lease_id) }}">{{ $p->lease->unit->code }}</a> {{ \Illuminate\Support\Str::limit($p->lease->tenant_name, 20) }}</td><td class="small">{{ \App\Models\LeasePayment::KINDS[$p->kind] }}{{ $p->voided_at ? ' (void)' : '' }}</td><td class="num">{{ money($p->amount, false) }}{{ (float) $p->wht_amount > 0 ? ' +'.money($p->wht_amount, false).' WHT' : '' }}</td></tr>
    @empty <tr><td colspan="5"><x-empty text="None in the period." icon="money"/></td></tr> @endforelse</tbody></table></div></div>
</div>
@endsection
