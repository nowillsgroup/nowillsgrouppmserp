@extends('layouts.app')
@section('title', 'Exchange '.$x->exchange_no)
@section('crumbs') Foreign Guests / <a href="{{ route('fg.fx') }}">Forex</a> / {{ $x->exchange_no }} @endsection
@section('actions')
  @if(! $x->voided_at && $x->certificate)<a class="btn btn-primary" href="{{ route('fg.fx.certificate', $x) }}" target="_blank" id="certBtn"><x-icon name="print"/> Encashment certificate</a>@endif
  @if($x->payout === 'folio' && $x->stay?->status === 'checked_in' && can_do('frontdesk.checkout'))<a class="btn" href="{{ route('frontdesk.checkout', $x->stay) }}" id="backToCheckout"><x-icon name="logout"/> Back to check-out</a>@endif
  @if($canVoid)<button class="btn btn-danger" data-modal="voidModal" id="voidBtn"><x-icon name="x"/> Void</button>@endif
@endsection
@section('content')
@error('void')<div class="alert alert-danger"><x-icon name="alert"/><div>{{ $message }}</div></div>@enderror
@if($x->voided_at)<div class="alert alert-danger" id="fxVoided"><x-icon name="alert"/><div>Voided {{ $x->voided_at->format('d M Y H:i') }} by {{ $x->voider?->name }} – {{ $x->void_reason }}</div></div>@endif
<div class="grid g-2">
  <div class="card"><div class="card-head"><h3>{{ $x->fc() }}</h3><span class="badge badge-{{ $x->payout === 'cash' ? 'amber' : 'green' }}">{{ $x->payout === 'cash' ? 'Paid in cash' : 'Credited to the room bill' }}</span></div>
    <div class="card-body"><table class="table table-compact" id="fxFigures">
      <tr><th>Tendered as</th><td>{{ $x->instrument === 'tc' ? 'Travellers’ cheques' : 'Currency notes' }}</td></tr>
      <tr><th>Buy rate</th><td class="num">{{ rtrim(rtrim(number_format($x->rate, 4), '0'), '.') }} per {{ $x->unit }} {{ $x->currency?->code }}</td></tr>
      <tr><th>Cedi value</th><td class="num">{{ money($x->inr_amount) }}</td></tr>
      @if($x->fee > 0)<tr><th>Service fee</th><td class="num">– {{ money($x->fee) }}</td></tr>@endif
      @if($x->gst > 0)<tr><th>Tax (pre-v4.3) <span class="tiny muted">on value {{ money($x->gst_value) }}</span></th><td class="num">– {{ money($x->gst) }}</td></tr>@endif
      <tr><th>{{ $x->payout === 'cash' ? 'Paid to the guest' : 'Credited' }}</th><td class="num bold" style="font-size:1.15rem">{{ money($x->net_amount) }}</td></tr>
      <tr><th>USD equivalent</th><td class="num">{{ number_format($x->usd_equiv, 2) }}</td></tr>
    </table></div></div>
  <div class="card"><div class="card-head"><h3>Guest</h3></div><div class="card-body"><table class="table table-compact">
      <tr><th>Name</th><td>{{ $x->guest_name }} @if($x->guest)<a href="{{ route('guests.show', $x->guest) }}" class="small">profile</a>@endif</td></tr>
      <tr><th>Nationality</th><td>{{ $x->nationality ?: '—' }}</td></tr>
      <tr><th>Passport</th><td>{{ \App\Models\Guest::mask($x->passport_no) }}</td></tr>
      <tr><th>Room</th><td>{{ $x->stay?->room?->room_no ?? '—' }}</td></tr>
      @if($x->folio)<tr><th>Bill</th><td><a href="{{ route('folios.show', $x->folio) }}">{{ $x->folio->folio_no }}</a></td></tr>@endif
      <tr><th>Business date</th><td>{{ $x->business_date->format('d M Y') }}</td></tr>
      <tr><th>Cashier</th><td>{{ $x->creator?->name }} · {{ $x->created_at?->format('H:i') }}</td></tr>
      <tr><th>Certificate</th><td>{{ $x->certificate ? ($x->certificate_prints ? 'printed '.$x->certificate_prints.'×' : 'not printed yet') : 'not requested' }}</td></tr>
      @if($x->notes)<tr><th>Notes</th><td>{{ $x->notes }}</td></tr>@endif
  </table></div></div>
</div>
@if($canVoid)
<div class="modal" id="voidModal"><div class="modal-box"><form method="post" action="{{ route('fg.fx.void', $x) }}">@csrf
  <div class="modal-head"><h3>Void {{ $x->exchange_no }}</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="small">{{ $x->payout === 'cash' ? 'Take back '.money($x->net_amount).' from the guest and return the '.$x->fc().'.' : 'The bill credit of '.money($x->net_amount).' will be reversed.' }}</p>
    <x-field name="reason" label="Reason" required/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-danger" id="confirmVoid">Void</button></div>
</form></div></div>
@endif
@endsection
