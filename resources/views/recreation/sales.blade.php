@extends('layouts.app')
@section('title', 'Ticket Sales')
@section('crumbs') <a href="{{ route('club.index') }}">Wellness &amp; Recreation</a> / Ticket sales @endsection
@section('actions')
<a class="btn btn-primary" href="{{ route('recreation.outlet') }}"><x-icon name="plus"/> Open the outlet</a>
@endsection
@section('content')
<form method="get" class="filters">
  <div class="field"><label for="f_date">Date</label><input type="date" name="date" id="f_date" value="{{ $date }}"></div>
  <div class="field" style="align-self:flex-end"><button class="btn">Show</button> <a class="btn btn-ghost" href="{{ route('recreation.sales') }}">All</a></div>
</form>
<div class="stats">
  <x-stat label="Sales" :value="$sumCount" icon="receipt" tone="blue"/>
  <x-stat label="Takings" :value="money($sumTotal)" icon="money" tone="green" sub="incl. tax"/>
  <x-stat label="Tax collected" :value="money($sumTax)" icon="trend" sub="VAT, NHIL, GETFund, levy"/>
  <x-stat label="Tickets issued" :value="$sumTickets" icon="users" tone="purple" sub="entry credentials"/>
</div>
<div class="card flush"><div class="card-body table-wrap"><table class="table">
  <thead><tr><th>Sale</th><th>When</th><th>Guest</th><th>Items</th><th>Tickets</th><th>Settled</th><th class="num">Total</th><th></th></tr></thead>
  <tbody>@forelse($rows as $s)
    <tr class="{{ $s->isVoid() ? 'muted' : '' }}">
      <td class="bold">{{ $s->sale_no }}</td>
      <td class="small">{{ $s->sold_at->format('D d M · H:i') }}<div class="tiny muted">{{ $s->user?->name }}</div></td>
      <td>{{ $s->guest_name ?: '—' }}@if($s->phone)<div class="tiny muted">{{ $s->phone }}</div>@endif @if($s->host)<div class="tiny muted">guest of {{ $s->host->name }}</div>@endif</td>
      <td class="small">{{ $s->items->sum('qty') }} ×<div class="tiny muted">{{ $s->items->pluck('description')->take(2)->implode(', ') }}{{ $s->items->count() > 2 ? '…' : '' }}</div></td>
      <td class="num">{{ $s->passes->sum(fn ($p) => $p->adults + $p->children) }}</td>
      <td class="small">{{ $s->stay ? 'Room '.$s->stay->room?->room_no : $s->payments->pluck('paymentMode.name')->filter()->implode(' + ') }}@if($s->reference)<div class="tiny muted">{{ $s->reference }}</div>@endif</td>
      <td class="num bold">{{ money($s->total, false) }}</td>
      <td class="num">
        <a class="btn btn-sm btn-ghost" href="{{ route('recreation.receipt', $s) }}" target="_blank">Receipt</a>
        @if(! $s->isVoid() && can_do('club.manage'))<button class="btn btn-sm btn-ghost" data-modal="voidModal" data-title="Void {{ $s->sale_no }}" data-action="{{ route('recreation.void', $s) }}">Void</button>@endif
      </td>
    </tr>
  @empty <tr><td colspan="8"><x-empty text="No ticket sales yet — open the outlet and ring one up." icon="receipt"/></td></tr> @endforelse</tbody>
</table></div></div>
{{ $rows->links() }}

<div class="modal" id="voidModal"><div class="modal-box"><form method="post" action="" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Void sale</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required placeholder="Wrong price, walk-out, duplicate…"/><p class="tiny muted">The sale's entry tickets are cancelled and any room charge is reversed. Refund the visitor at the desk if payment was taken.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Keep sale</button><button class="btn btn-danger">Void sale</button></div>
</form></div></div>
@endsection
