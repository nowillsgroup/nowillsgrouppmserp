@extends('layouts.app')
@section('title', 'CRO Desk')
@section('crumbs') Central Reservations / Desk @endsection
@php $E = \App\Models\CroEnquiry::class; $mgr = \App\Services\CroService::canManage(auth()->user()); @endphp
@section('actions')
  <a class="btn" href="{{ route('cro.guests') }}"><x-icon name="search"/> Guest lookup</a>
  @if($canBook)<button class="btn btn-primary" data-modal="croLogModal" data-fasked_hotel_id="{{ hotel()->id }}" data-foutcome="open" id="logEnquiryBtn"><x-icon name="phone"/> Log enquiry</button>@endif
@endsection
@section('content')
@if($booked)
<div class="alert alert-success" id="croBooked"><x-icon name="check"/>
  <span>{{ $booked['referral'] ? 'Referral booked at' : 'Booked at' }} <b>{{ $booked['hotel'] }}</b>:
  @if($booked['hotel_id'] === hotel()->id)<a href="{{ route('reservations.show', $booked['id']) }}"><b>{{ $booked['no'] }}</b></a>@else<b>{{ $booked['no'] }}</b>@endif
  · {{ money($booked['value']) }} · enquiry {{ $booked['enquiry'] }}{{ $booked['referral'] ? ' – kept in the group' : '' }}</span>
</div>
@endif
<div class="stats" id="croStats">
  <x-stat label="Enquiries {{ bdate()->format('M') }}" :value="$kpi['total']" icon="phone" tone="blue" :sub="$kpi['open'].' open · '.$kpi['booked'].' booked · '.$kpi['referred'].' referred'" :href="route('cro.enquiries')"/>
  <x-stat label="Conversion" :value="$kpi['conversion'] !== null ? $kpi['conversion'].'%' : '—'" icon="trend" :tone="$kpi['conversion'] === null ? 'slate' : ($kpi['conversion'] >= $kpi['target'] ? 'green' : 'amber')" :sub="'target '.rtrim(rtrim(number_format($kpi['target'], 1), '0'), '.').'% · group retention '.($kpi['retention'] !== null ? $kpi['retention'].'%' : '—')" :href="route('cro.performance')"/>
  <x-stat label="Booked by CRO" :value="money_short($kpi['booked_revenue'])" icon="money" tone="green" :sub="number_format($kpi['booked_nights']).' room nights'.($kpi['referred_revenue'] ? ' · '.money_short($kpi['referred_revenue']).' to sisters' : '')"/>
  <x-stat label="Lost business" :value="money_short($kpi['lost_revenue'])" icon="alert" tone="red" :sub="$kpi['denials'].' denials · '.$kpi['regrets'].' regrets'" :href="route('cro.lost')"/>
  <x-stat label="Follow-ups due" :value="$alerts['follow_ups']" icon="clock" :tone="$alerts['follow_ups'] ? 'amber' : 'slate'" :sub="$alerts['arriving'].' open, arriving within 3 days'" :href="route('cro.enquiries', ['status' => 'open'])"/>
</div>

@if($enq)
<div class="alert alert-info mt-1" id="croEnqBanner"><x-icon name="search"/><span>Finding rooms for enquiry <a href="{{ route('cro.enquiry', $enq) }}"><b>{{ $enq->number }}</b></a> – {{ $enq->caller_name }}
  ({{ $enq->statusLabel() }}{{ $enq->reasonLabel() ? ': '.$enq->reasonLabel() : '' }}). Booking at {{ hotel()->name }} closes it as booked; booking at a sister hotel records a referral.</span></div>
@endif

<div class="card mt-1" id="croSearch"><div class="card-head"><h3><x-icon name="calendar"/> {{ $group ? 'Group availability – '.$hotels->count().' hotels' : 'Availability' }}</h3></div><div class="card-body">
  <form method="get" class="flex gap-1 wrap" style="align-items:flex-end" id="croSearchForm">@if($enq)<input type="hidden" name="enquiry" value="{{ $enq->id }}">@endif
    <div class="field"><label>Arrival</label><input type="date" name="arrival" value="{{ $q['arrival'] }}" required></div>
    <div class="field"><label>Departure</label><input type="date" name="departure" value="{{ $q['departure'] }}" required></div>
    <div class="field" style="max-width:6rem"><label>Rooms</label><input type="number" name="rooms" min="1" max="20" value="{{ $q['rooms'] }}"></div>
    <div class="field" style="max-width:6rem"><label>Adults</label><input type="number" name="adults" min="1" max="80" value="{{ $q['adults'] }}"></div>
    <div class="field" style="max-width:6rem"><label>Children</label><input type="number" name="children" min="0" max="40" value="{{ $q['children'] }}"></div>
    @if($group && ! $enq)<div class="field"><label>Caller asked for</label><select name="asked">@foreach($hotels as $h)<option value="{{ $h->id }}" @selected($q['asked'] === $h->id)>{{ $h->name }}</option>@endforeach</select></div>@endif
    <button class="btn btn-primary"><x-icon name="search"/> Search</button>
  </form>
  @if($error)<div class="alert alert-error mt-1">{{ $error }}</div>@endif
  @if($prefill)<p class="small mt-1" id="croPrefill"><x-icon name="user"/> Booking for <b>{{ $prefill['name'] }}</b>{{ $prefill['hotel'] ? ' (profile at '.$prefill['hotel'].')' : '' }}
    @if($prefill['vip'])<span class="badge badge-purple">VIP</span>@endif @if($prefill['blacklisted'])<span class="badge badge-red">Blacklisted</span>@endif</p>@endif
</div></div>

<div class="g-2-1 mt-1">
  <div id="croResults">
  @if($results)
    @php $askedId = $enq ? hotel()->id : $q['asked']; @endphp
    @foreach($results as $res)@php $h = $res['hotel']; $isAsked = $h->id === $askedId; $anyOk = $res['best'] !== null; @endphp
    <div class="card flush mb-1 cro-hotel{{ $isAsked ? ' cro-asked' : '' }}" data-hotel="{{ $h->code }}">
      <div class="card-head"><h3><x-icon name="hotel"/> {{ $h->name }} @if($isAsked)<span class="badge badge-blue">Asked for</span>@else<span class="badge badge-purple">Sister hotel</span>@endif</h3>
        <span class="small">@if($res['error'])<span class="text-red">{{ $res['error'] }}</span>@elseif($anyOk)from <b>{{ money($res['best']['average']) }}</b>/night · {{ $res['free'] }} rooms free @else<span class="badge badge-red">No availability</span>@endif</span></div>
      @if(! $res['error'])
      <div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Room type</th><th class="num">Free</th><th class="num">Avg / night</th><th class="num">Stay ({{ $nights }} n × {{ $q['rooms'] }})</th><th></th></tr></thead><tbody>
        @foreach($res['types'] as $t)<tr data-type="{{ $t['type']->code }}"><td><b>{{ $t['type']->name }}</b><div class="tiny muted">sleeps {{ $t['type']->max_adults ?: '—' }}</div></td>
          <td class="num">{{ $t['free'] }}</td><td class="num">{{ number_format($t['average'], 2) }}</td><td class="num">{{ number_format($t['total'], 2) }}</td>
          <td class="nowrap">@if($t['bookable'])@if($canBook)<button type="button" class="btn btn-sm {{ $isAsked ? 'btn-primary' : '' }}" data-modal="croBookModal" data-title="{{ ($isAsked ? 'Book ' : 'Refer to ').$h->name.' – '.$t['type']->name }}"
              data-fhotel_id="{{ $h->id }}" data-froom_type_id="{{ $t['type']->id }}" data-fquote="{{ number_format($t['average'], 2).' / night · '.money($t['total']).' total' }}">{{ $isAsked ? 'Book' : 'Refer & book' }}</button>@else<span class="badge badge-green">Available</span>@endif
            @else<span class="badge badge-slate">{{ $t['reason'] }}</span>@endif</td></tr>@endforeach
      </tbody></table>
      @if($canBook && $isAsked)<div class="pad-sm flex gap-1 wrap" style="padding:.5rem .9rem">@php $den = collect($res['types'])->pluck('denial')->filter()->first() ?? 'sold_out'; @endphp
        <button type="button" class="btn btn-sm btn-ghost" data-modal="croLogModal" data-fasked_hotel_id="{{ $h->id }}" data-foutcome="lost" data-floss_reason="{{ $anyOk ? 'price' : $den }}" id="logTurnaway{{ $h->id }}"><x-icon name="minus"/> Log {{ $anyOk ? 'regret' : 'denial' }}</button>
        @if(! $anyOk && $group)<span class="tiny muted" style="align-self:center">No rooms here – offer a sister hotel below to keep the guest in the group.</span>@endif
      </div>@endif
      </div>
      @endif
    </div>
    @endforeach
  @else
    <div class="card"><div class="card-body muted">Enter the stay and search{{ $group ? ' – every hotel of the group is checked at once, with live rates, stop-sell and minimum stay.' : '.' }}</div></div>
  @endif
  </div>
  <div>
    <div class="card flush" id="croDue"><div class="card-head"><h3><x-icon name="clock"/> Open enquiries</h3><a class="btn btn-sm btn-ghost" href="{{ route('cro.enquiries', ['status' => 'open']) }}">All →</a></div>
      <div class="card-body"><table class="table table-compact"><tbody>
      @forelse($due as $d)<tr class="{{ $d->isFollowUpDue() ? 'row-alert' : '' }}"><td><a href="{{ route('cro.enquiry', $d) }}"><b>{{ $d->number }}</b> {{ $d->caller_name }}</a>
        <div class="tiny muted">{{ $d->arrival_date->format('d M') }} – {{ $d->departure_date->format('d M') }} · {{ $d->rooms }} rm · {{ $d->agent?->name }}</div></td>
        <td class="tiny nowrap">{{ $d->follow_up_at ? ($d->isFollowUpDue() ? 'due ' : '').$d->follow_up_at->format('d M H:i') : '' }}</td></tr>
      @empty<tr><td class="muted">No open enquiries.</td></tr>@endforelse
      </tbody></table></div></div>
    <div class="card mt-1"><div class="card-body small muted">Every call that asks for rooms should end here: <b>booked</b>, <b>referred</b> to a sister hotel, or <b>lost</b> – a <b>denial</b> when we could not offer (sold out, restrictions) or a <b>regret</b> when the guest declined (price, location …). Denials feed the unconstrained demand on the <a href="{{ route('cro.lost') }}">Lost Business</a> page.</div></div>
  </div>
</div>

@if($canBook)
<div class="modal" id="croBookModal"><div class="modal-box" style="max-width:46rem"><form method="post" action="{{ route('cro.book') }}">@csrf
  <div class="modal-head"><h3 data-title>Book</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <input type="hidden" name="hotel_id" value=""><input type="hidden" name="room_type_id" value="">
    <input type="hidden" name="arrival" value="{{ $q['arrival'] }}"><input type="hidden" name="departure" value="{{ $q['departure'] }}">
    <input type="hidden" name="rooms" value="{{ $q['rooms'] }}"><input type="hidden" name="adults" value="{{ $q['adults'] }}"><input type="hidden" name="children" value="{{ $q['children'] }}">
    @if($enq)<input type="hidden" name="enquiry_id" value="{{ $enq->id }}">@else<input type="hidden" name="asked_hotel_id" value="{{ $q['asked'] }}">@endif
    <div class="field span-all"><label>Stay</label><input name="quote" readonly value="" class="muted"><span class="tiny muted">{{ \Carbon\Carbon::parse($q['arrival'])->format('D d M') }} → {{ \Carbon\Carbon::parse($q['departure'])->format('D d M Y') }} · {{ $q['rooms'] }} room(s) · {{ $q['adults'] }} adult(s){{ $q['children'] ? ' · '.$q['children'].' child(ren)' : '' }}</span></div>
    <div class="field"><label>First name</label><input name="first_name" required maxlength="80" value="{{ old('first_name', $prefill['first_name'] ?? '') }}"></div>
    <div class="field"><label>Last name</label><input name="last_name" maxlength="80" value="{{ old('last_name', $prefill['last_name'] ?? '') }}"></div>
    <div class="field"><label>Phone</label><input name="phone" maxlength="30" value="{{ old('phone', $prefill['phone'] ?? '') }}"></div>
    <div class="field"><label>E-mail</label><input type="email" name="email" maxlength="255" value="{{ old('email', $prefill['email'] ?? '') }}"></div>
    <div class="field"><label>Nationality</label><input name="nationality" maxlength="60" value="{{ old('nationality', $prefill['nationality'] ?? 'Ghanaian') }}"></div>
    <div class="field"><label>Status</label><select name="status"><option value="confirmed">Confirmed</option><option value="tentative">Tentative</option></select></div>
    <div class="field"><label>Channel</label><select name="channel">@foreach($E::CHANNELS as $k => $l)@continue($k === 'referral')<option value="{{ $k }}" @selected(($enq?->channel ?? 'phone') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Call length (min)</label><input type="number" name="duration_min" min="0" max="600" step="0.5"></div>
    @if($mgr)<div class="field"><label>Rate override / night</label><input type="number" name="rate" min="0" step="0.01" placeholder="hotel rate"></div>@endif
    @if($group)<div class="field span-all"><label>If this is a sister hotel: why could the asked hotel not keep the guest?</label><select name="referral_reason">@foreach($E::DENIALS as $k => $l)<option value="{{ $k }}">Denial – {{ $l }}</option>@endforeach @foreach(['price', 'location', 'product'] as $k)<option value="{{ $k }}">Regret – {{ $E::REGRETS[$k] }}</option>@endforeach</select></div>@endif
    <div class="field span-all"><label>Special request</label><textarea name="special_request" rows="2" maxlength="500"></textarea></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Confirm booking</button></div>
</form></div></div>
@include('cro._log_modal', ['q' => $q])
@endif
@endsection
