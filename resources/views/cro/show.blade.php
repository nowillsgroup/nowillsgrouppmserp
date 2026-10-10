@extends('layouts.app')
@section('title', 'Enquiry '.$e->number)
@section('crumbs') Central Reservations / <a href="{{ route('cro.enquiries') }}">Enquiries</a> / {{ $e->number }} @endsection
@php $E = \App\Models\CroEnquiry::class; @endphp
@section('actions')
  @if($canBook && ($e->status === 'open' || ($e->status === 'lost' && $e->loss_type === 'denial' && $e->departure_date->gt(bdate()))))<a class="btn btn-primary" href="{{ route('cro.index', ['enquiry' => $e->id]) }}" id="findRoomsBtn"><x-icon name="search"/> {{ $e->status === 'open' ? 'Find rooms & book' : ($group ? 'Find a sister hotel' : 'Search again') }}</a>@endif
  @if($canBook && $e->status === 'open')<button class="btn" data-modal="croLostModal" id="markLostBtn"><x-icon name="minus"/> Close as lost</button>@endif
  @if($canBook && $e->status === 'lost' && $e->departure_date->gt(bdate()))<form method="post" action="{{ route('cro.enquiry.reopen', $e) }}" style="display:inline" data-confirm="Reopen this enquiry for follow-up?">@csrf<button class="btn" id="reopenBtn"><x-icon name="refresh"/> Reopen</button></form>@endif
@endsection
@section('content')
<div class="g-2-1">
  <div>
    <div class="card" id="enqCard"><div class="card-head"><h3><x-icon name="phone"/> {{ $e->caller_name }}</h3><span><span class="badge badge-{{ $e->statusTone() }}" id="enqStatus">{{ $e->statusLabel() }}</span>@if($e->loss_type) <span class="badge badge-{{ $E::LOSS_TYPES[$e->loss_type][1] }}">{{ $E::LOSS_TYPES[$e->loss_type][0] }}</span>@endif</span></div>
      <div class="card-body"><div class="kv-grid">
        <div><span>Channel</span>{{ $e->channelLabel() }}</div>
        <div><span>Phone / e-mail</span>{{ collect([$e->phone, $e->email])->filter()->implode(' · ') ?: '—' }}</div>
        <div><span>Stay</span>{{ $e->arrival_date->format('D d M Y') }} → {{ $e->departure_date->format('D d M') }} ({{ $e->nights() }} n)</div>
        <div><span>Party</span>{{ $e->rooms }} room(s) · {{ $e->adults }} adult(s){{ $e->children ? ' · '.$e->children.' child(ren)' : '' }}</div>
        <div><span>Room type asked</span>{{ $e->roomType?->name ?? 'Any' }}</div>
        <div><span>Quoted</span>{{ $e->quoted_rate !== null ? money($e->quoted_rate).' / night' : '—' }}@if($e->quoted_total !== null)<span class="tiny muted">{{ money($e->quoted_total) }} for the stay · {{ $e->roomNights() }} room nights</span>@endif</div>
        <div><span>Company</span>{{ $e->company?->name ?? '—' }}</div>
        <div><span>Taken by</span>{{ $e->agent?->name ?? '—' }}<span class="tiny muted">{{ $e->created_at->format('d M Y H:i') }}{{ $e->duration_sec ? ' · '.gmdate('i:s', $e->duration_sec).' call' : '' }}</span></div>
        @if($e->status === 'open')<div><span>Follow up by</span>{!! $e->follow_up_at ? ($e->isFollowUpDue() ? '<b class="text-red">'.e($e->follow_up_at->format('d M H:i')).' – due</b>' : e($e->follow_up_at->format('d M H:i'))) : '—' !!}</div>@endif
        @if($e->status === 'booked')<div class="span-all"><span>Booking</span>@if($e->reservation)<a href="{{ route('reservations.show', $e->reservation) }}"><b>{{ $e->reservation->reservation_no }}</b></a> · {{ $e->reservation->guest?->full_name }} · {{ money($e->booked_value) }}@else — @endif{{ $e->referredFrom ? ' – referred by '.$e->referredFrom->name : '' }}</div>@endif
        @if($e->status === 'referred')<div class="span-all"><span>Referred to</span><b>{{ $e->referredHotel?->name }}</b> · reservation {{ $e->referred_reservation_no }} · {{ money($e->booked_value) }} kept in the group<span class="tiny muted">Why not here: {{ $e->reasonLabel() }}</span></div>@endif
        @if($e->status === 'lost')<div class="span-all"><span>Lost because</span><b>{{ $e->reasonLabel() }}</b>{{ $e->competitor ? ' – booked '.$e->competitor->name : '' }}<span class="tiny muted">{{ $e->loss_note }} · closed {{ $e->closed_at?->format('d M H:i') }} {{ $e->closer ? 'by '.$e->closer->name : '(automatic)' }}</span></div>@endif
        @if($e->notes)<div class="span-all"><span>Notes</span>{!! nl2br(e($e->notes)) !!}</div>@endif
      </div></div></div>
  </div>
  <div>
    <div class="card" id="contactsCard"><div class="card-head"><h3><x-icon name="message"/> Contacts</h3></div><div class="card-body">
      @if($canBook)<form method="post" action="{{ route('cro.enquiry.contact', $e) }}" class="form-grid one" id="contactForm">@csrf
        <div class="field"><select name="type" aria-label="Type">@foreach($E::CONTACT_TYPES as $k => $l)<option value="{{ $k }}" @selected($k === 'call_out')>{{ $l }}</option>@endforeach</select></div>
        <div class="field"><textarea name="note" rows="2" required maxlength="500" placeholder="What was said / agreed"></textarea></div>
        @if($e->status === 'open')<div class="field"><label>Next follow-up</label><input type="datetime-local" name="follow_up_at" value="{{ now()->addDay()->format('Y-m-d\TH:i') }}"></div>@endif
        <div><button class="btn btn-sm btn-primary">Add contact</button></div>
      </form>@endif
      <ul class="timeline mt-1" id="contactList">
      @forelse($e->contacts as $c)<li><b>{{ $c->typeLabel() }}</b> <span class="tiny muted">{{ $c->created_at->format('d M H:i') }} · {{ $c->user?->name }}</span><div class="small">{{ $c->note }}</div></li>
      @empty<li class="muted small">No follow-up contacts yet.</li>@endforelse
      </ul>
    </div></div>
  </div>
</div>
@if($canBook && $e->status === 'open')
<div class="modal" id="croLostModal"><div class="modal-box"><form method="post" action="{{ route('cro.enquiry.lost', $e) }}">@csrf
  <div class="modal-head"><h3>Close {{ $e->number }} as lost</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid one">
    <div class="field"><label>Reason</label><select name="loss_reason" required><option value="">—</option><optgroup label="Denial – we could not offer">@foreach($E::DENIALS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</optgroup><optgroup label="Regret – the guest declined">@foreach($E::REGRETS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</optgroup></select></div>
    <div class="field"><label>Booked with (if "Booked another hotel")</label><select name="competitor_id"><option value="">—</option>@foreach($competitors as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
    <div class="field"><label>Note</label><input name="loss_note" maxlength="255"></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Close as lost</button></div>
</form></div></div>
@endif
@endsection
