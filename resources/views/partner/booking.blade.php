@extends('partner.layout')
@section('title', 'Booking '.$r->reservation_no)
@section('crumbs') <a href="{{ route('partner.bookings') }}">Bookings</a> / {{ $r->reservation_no }} @endsection
@section('actions') <button class="btn" type="button" onclick="window.print()"><x-icon name="print"/> Print</button> @endsection
@section('content')
@foreach(['cancel', 'amend', 'request'] as $k)@if($errors->has($k))<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first($k) }}</div></div>@endif @endforeach
@if($r->status === 'tentative')<div class="alert alert-warn" id="ptnAwaiting"><x-icon name="clock"/><div><b>Awaiting the hotel's confirmation.</b> {{ $requests->firstWhere('type', 'approval')?->reason }}. The rooms are held for you; you will get an e-mail once it is approved.</div></div>@endif
<div class="g-2-1">
  <div>
    <div class="card" id="ptnBookingCard"><div class="card-head"><h3><x-icon name="book"/> Stay details</h3>@include('partner._status', ['r' => $r])</div><div class="card-body">
      <div class="kv-grid">
        <div><span>Traveller</span><b>{{ $r->guest?->full_name }}</b></div>
        <div><span>Dates</span><b>{{ $r->arrival_date->format('D d M Y') }} – {{ $r->departure_date->format('D d M Y') }}</b> ({{ $r->nights }} night(s))</div>
        <div><span>Guests</span><b>{{ $r->adults }} adult(s){{ $r->children ? ', '.$r->children.' child(ren)' : '' }}</b></div>
        <div><span>Rate</span><b>{{ $r->rateContract ? 'Contract '.$r->rateContract->code : ((float) $company->discount_pct > 0 ? 'Corporate discount' : 'Best available rate') }}</b></div>
        <div><span>Bill to</span><b>{{ $r->billing_instruction === 'company' ? $company->name.' (room & tax)' : 'Traveller pays at the hotel' }}</b></div>
        <div><span>Your reference</span><b>{{ $r->partner_ref ?: '—' }}</b></div>
        <div><span>Booked by</span><b>{{ $r->partnerUser?->name ?? 'Hotel reservations' }}</b> {{ $r->created_at->format('d M Y H:i') }}</div>
        <div><span>Stay value</span><b>{{ money($value) }}</b> <span class="tiny muted">taxes extra</span></div>
        @if($r->special_request)<div class="span-2"><span>Special requests</span><b>{{ $r->special_request }}</b></div>@endif
        @if($r->status === 'cancelled')<div class="span-2"><span>Cancelled</span><b>{{ $r->cancelled_at?->format('d M Y H:i') }}</b> – {{ $r->cancel_reason }}{{ (float) $r->cancellation_fee > 0 ? ' · fee '.money($r->cancellation_fee) : '' }}</div>@endif
      </div>
      <table class="table table-compact mt-2"><thead><tr><th>#</th><th>Room type</th><th>Room</th><th>Adults</th><th class="right">Rate / night</th><th>Status</th></tr></thead><tbody>
      @foreach($r->rooms as $i => $s)<tr><td>{{ $i + 1 }}</td><td>{{ $s->roomType?->name }}</td><td>{{ $s->room?->room_no ?? 'assigned on arrival' }}</td><td>{{ $s->adults }}</td><td class="right">{{ money($s->rate) }}</td><td class="small">{{ ucfirst(str_replace('_', ' ', $s->status)) }}</td></tr>@endforeach
      </tbody></table>
    </div></div>
    @if($requests->isNotEmpty())
    <div class="card" id="ptnBookingRequests"><div class="card-head"><h3><x-icon name="message"/> Requests on this booking</h3></div><div class="card-body table-wrap">
      <table class="table table-compact"><thead><tr><th>No.</th><th>Type</th><th>Asked</th><th>Status</th><th>Hotel's answer</th></tr></thead><tbody>
      @foreach($requests as $q)<tr><td>{{ $q->number }}</td><td>{{ \App\Models\PartnerRequest::TYPES[$q->type] }}</td><td class="small">{{ $q->details ?: $q->reason }}<div class="tiny muted">{{ $q->created_at->format('d M H:i') }} · {{ $q->partnerUser?->name }}</div></td><td><span class="badge {{ $q->badge() }}">{{ \App\Models\PartnerRequest::STATUSES[$q->status] }}</span></td><td class="small">{{ $q->decision_note ?: '—' }}</td></tr>@endforeach
      </tbody></table>
    </div></div>
    @endif
  </div>
  <div class="no-print">
    @if($partner->can('book') && $cancellable)
    <div class="card" id="ptnCancelCard"><div class="card-head"><h3><x-icon name="x"/> Cancel booking</h3></div><div class="card-body">
      <p class="small {{ $cancelQuote['amount'] > 0 ? 'ptn-warn' : '' }}" id="ptnCancelTerms">@if($cancelQuote['amount'] > 0)<b>A late-cancellation fee of {{ money($cancelQuote['amount']) }} applies</b> ({{ $cancelQuote['basis'] }}).@else{{ $cancelQuote['basis'] }}.@endif</p>
      @if(setting('partner_cancel', '1') === '1')
      <form method="post" action="{{ route('partner.booking.cancel', $r->id) }}" id="ptnCancelForm" onsubmit="return confirm('Cancel booking {{ $r->reservation_no }}?')">
        @csrf
        <div class="field"><label for="reason">Reason</label><input class="input" id="reason" name="reason" required maxlength="200" placeholder="Trip postponed…"></div>
        <button class="btn btn-danger btn-block" type="submit">Cancel booking</button>
      </form>
      @else<p class="small muted">Please contact the hotel to cancel.</p>@endif
    </div></div>
    @endif
    @if($partner->can('book') && in_array($r->status, ['confirmed', 'tentative', 'checked_in']) && setting('partner_amend', '1') === '1')
    <div class="card" id="ptnAmendCard"><div class="card-head"><h3><x-icon name="edit"/> Request a change</h3></div><div class="card-body">
      @if($requests->where('type', 'amendment')->where('status', 'pending')->isNotEmpty())
      <p class="small m-0" id="ptnAmendOpen"><x-icon name="clock" size="sm"/> Your change request {{ $requests->where('type', 'amendment')->where('status', 'pending')->first()->number }} is with the reservations team – you will get an e-mail when it is done.</p>
      @else
      <form method="post" action="{{ route('partner.booking.amend', $r->id) }}" id="ptnAmendForm">
        @csrf
        <div class="g-2"><div class="field"><label>New arrival</label><input class="input" type="date" name="arrival" value="{{ old('arrival') }}"></div><div class="field"><label>New departure</label><input class="input" type="date" name="departure" value="{{ old('departure') }}"></div></div>
        <div class="field"><label>Rooms</label><input class="input" type="number" name="rooms" min="1" max="50" value="{{ old('rooms') }}" placeholder="unchanged"></div>
        <div class="field"><label for="details">What should change?</label><textarea class="input" id="details" name="details" rows="3" required maxlength="2000" placeholder="Extend by one night, change traveller name to…">{{ old('details') }}</textarea></div>
        <button class="btn btn-block" type="submit"><x-icon name="mail"/> Send change request</button>
      </form>
      @endif
      <p class="tiny muted mt-1 m-0">The reservations team applies the change and confirms by e-mail. Rates may change for new dates.</p>
    </div></div>
    @endif
  </div>
</div>
@endsection
