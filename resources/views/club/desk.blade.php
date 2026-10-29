@extends('layouts.app')
@section('title', 'Club Check-in Desk')
@section('crumbs') <a href="{{ route('club.index') }}">Health Club</a> / Check-in desk @endsection
@section('actions')
<a class="btn" href="{{ route('club.passes') }}#sell" id="goPass"><x-icon name="receipt"/> Sell day pass</a>
<a class="btn" href="{{ route('club.visits') }}"><x-icon name="book"/> Visit log</a>
@endsection
@section('content')
@php $F = \App\Models\ClubPlan::FACILITIES; @endphp
<div class="fs-split">
  <div>
    <div class="card" id="scanCard"><div class="card-body">
      <form method="get" action="{{ route('club.desk') }}" class="flex gap-1 items-end" id="scanForm" style="flex-wrap:wrap">
        <div class="field grow" style="flex:1;min-width:220px"><label for="q">Scan card / membership no. / name / phone</label><input class="club-scan" name="q" id="q" value="{{ $q }}" autofocus autocomplete="off" placeholder="8xxxxxxxxx"></div>
        <div class="field"><label for="facility">Facility</label><select name="facility" id="facility">@foreach($F as $k => $l)<option value="{{ $k }}" @selected($facility === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="field"><button class="btn btn-primary" id="scanGo"><x-icon name="search"/> Find</button></div>
      </form>

      @if($q !== '' && $found->isEmpty())<div class="club-verdict no mt-1" id="noMatch"><x-icon name="x"/> No member matches "{{ $q }}".</div>@endif
      @if($found->count() > 1 && ! $person)
        <table class="table mt-1" id="matches"><tbody>@foreach($found as $f)
          <tr><td>@include('club._photo', ['p' => $f, 'size' => 'sm'])</td><td><b>{{ $f->name }}</b><div class="tiny muted">{{ $f->card_no }} · {{ $f->membership->membership_no }} · {{ $f->membership->plan->name }}</div></td><td>@include('club._status', ['m' => $f->membership])</td>
            <td class="num"><a class="btn btn-sm" href="{{ route('club.desk', ['member' => $f->id, 'facility' => $facility]) }}">Select</a></td></tr>
        @endforeach</tbody></table>
      @endif

      @if($person)@php $m = $person->membership; @endphp
      <div class="club-person mt-1" id="personPanel" data-member="{{ $person->id }}">
        @include('club._photo', ['p' => $person])
        <div class="flex-1">
          <h2 class="m-0">{{ $person->name }}</h2>
          <div class="small muted">Card {{ $person->card_no }} · {{ \App\Models\ClubMember::RELATIONS[$person->relation] }}{{ $person->age() !== null ? ' · age '.$person->age() : '' }}</div>
          <div class="mt-1 small"><a href="{{ route('club.members.show', $m) }}" class="bold">{{ $m->membership_no }}</a> · {{ $m->plan->name }} · @include('club._status', ['m' => $m])</div>
          <div class="small muted">{{ dmy($m->start_date) }} – {{ dmy($m->end_date) }}{{ $m->plan->hoursLabel() ? ' · off-peak '.$m->plan->hoursLabel() : '' }} · includes {{ $m->plan->facilityNames() }}</div>
          <div class="small">Balance <b class="{{ $balance > 0 ? 'text-red' : 'text-green' }}">{{ money($balance) }}</b> · guest passes left this month <b>{{ $passesLeft }}</b></div>
        </div>
      </div>
      <div class="club-verdict {{ $check['ok'] ? 'ok' : 'no' }}" id="verdict"><x-icon :name="$check['ok'] ? 'check' : 'x'"/><div><span class="big">{{ $check['ok'] ? 'Entry OK – '.$F[$facility] : 'Entry refused' }}</span>
        @foreach($check['reasons'] as $r)<div class="small">• {{ $r }}</div>@endforeach</div></div>
      @foreach($check['warnings'] as $w)<div class="alert mt-0 small" style="margin-bottom:.4rem">⚠ {{ $w }}</div>@endforeach

      <div class="flex gap-1 mt-1" style="flex-wrap:wrap">
        <form method="post" action="{{ route('club.checkin') }}" id="checkinForm">@csrf<input type="hidden" name="club_member_id" value="{{ $person->id }}"><input type="hidden" name="facility" value="{{ $facility }}">
          @if($check['ok'])<button class="btn btn-success" id="checkinBtn"><x-icon name="login"/> Check in to {{ strtolower($F[$facility]) }}</button>
          @else<button class="btn btn-danger" id="logDenied" title="Records the refused attempt"><x-icon name="x"/> Log refusal</button>@endif
        </form>
        @if(! $check['ok'] && can_do('club.manage'))<button class="btn btn-warning" data-modal="overrideModal" id="overrideBtn"><x-icon name="key"/> Manager override</button>@endif
        @if($check['ok'] || collect($check['reasons'])->every(fn ($r) => str_starts_with($r, 'Already checked in')))<button class="btn" data-modal="guestModal" id="guestBtn"><x-icon name="user"/> Bring a guest</button>@endif
        @if(can_do('club.members') && $balance > 0)<a class="btn" href="{{ route('club.members.show', $m) }}#account" id="takePay"><x-icon name="money"/> Take payment</a>@endif
      </div>
      @endif
    </div></div>

    <div class="card flush mt-1" id="recentLog"><div class="card-head"><h3>Today's entries</h3><a class="small" href="{{ route('club.visits') }}">All →</a></div>
      <div class="card-body table-wrap"><table class="table">
        <thead><tr><th>Time</th><th>Who</th><th>Facility</th><th>Result</th></tr></thead>
        <tbody>@forelse($recent as $v)
          <tr class="{{ $v->denied ? 'row-alert' : '' }}"><td class="small">{{ $v->checked_in_at->format('H:i') }}</td><td>{{ $v->name }}<div class="tiny muted">{{ \App\Models\ClubVisit::TYPES[$v->visitor_type] }}</div></td><td class="small">{{ $F[$v->facility] ?? $v->facility }}</td>
            <td class="small">@if($v->denied)<span class="text-red">Refused – {{ $v->deny_reason }}</span>@elseif($v->override_reason)<span class="text-amber">Override by {{ $v->overrider?->name }}: {{ $v->override_reason }}</span>@else<span class="text-green">In</span>{{ $v->checked_out_at ? ' · out '.$v->checked_out_at->format('H:i') : '' }}@endif</td></tr>
        @empty <tr><td colspan="4"><x-empty text="No entries yet today." icon="book"/></td></tr> @endforelse</tbody></table></div></div>
  </div>

  <div>
    <div class="card" id="deskOcc"><div class="card-head"><h3>Occupancy</h3></div><div class="card-body"><div class="club-occ">
      @foreach($occ as $k => $o)@php $pct = $o['cap'] ? min(100, round($o['in'] * 100 / $o['cap'])) : 0; @endphp
        <a class="fac {{ $o['cap'] && $o['in'] >= $o['cap'] ? 'full' : ($pct >= 75 ? 'busy' : '') }} {{ $k === $facility ? 'sel' : '' }}" style="color:inherit;text-decoration:none" href="{{ route('club.desk', array_filter(['facility' => $k, 'member' => $person?->id])) }}"><div class="small muted">{{ $o['label'] }}</div><div class="n">{{ $o['in'] }}<span class="small muted"> / {{ $o['cap'] }}</span></div><div class="progress"><span style="width:{{ $pct }}%"></span></div></a>
      @endforeach
    </div></div></div>

    <div class="card mt-1" id="residentCard"><div class="card-head"><h3><x-icon name="bed"/> Hotel guest (in-house)</h3></div><div class="card-body">
      <form method="post" action="{{ route('club.resident') }}" id="residentForm">@csrf
        <div class="form-grid">
          <x-field name="room_no" label="Room no." required placeholder="101"/>
          <div class="field"><label for="r_fac">Facility</label><select name="facility" id="r_fac">@foreach(\App\Services\ClubService::RESIDENT_FACILITIES as $k)<option value="{{ $k }}" @selected($facility === $k)>{{ $F[$k] }}</option>@endforeach</select></div>
          <x-field name="pax" label="People" type="number" value="1" min="1" max="10"/>
          <div class="field" style="align-self:flex-end"><button class="btn btn-primary" id="residentGo">Check in</button></div>
        </div>
        <p class="tiny muted m-0">{{ (int) \App\Services\ClubService::cfg('club_resident_free') ? 'Gym, pool and sauna are free for in-house guests.' : 'Resident access is switched off – sell a day pass to the room.' }} Tennis, squash, kids club and classes need a day pass or class booking.</p>
      </form>
    </div></div>

    <div class="card mt-1" id="ticketCard"><div class="card-head"><h3><x-icon name="receipt"/> Walk-in ticket</h3></div><div class="card-body">
      <form method="post" action="{{ route('club.ticket') }}" id="ticketForm">@csrf
        <div class="form-grid">
          <x-field name="code" label="Ticket / pass code" required placeholder="CLUB_DPASS-000123" class="span-2" id="ticket_code"/>
          <div class="field"><label for="t_fac">Facility</label><select name="facility" id="t_fac">@foreach($F as $k => $l)<option value="{{ $k }}" @selected($facility === $k)>{{ $l }}</option>@endforeach</select></div>
          <div class="field" style="align-self:flex-end"><button class="btn btn-primary" id="ticketGo">Check in</button></div>
        </div>
        <p class="tiny muted m-0">Scan or type the code printed on the outlet receipt (or the Day Passes list). Members use their card above — tickets are for walk-ins and guest passes.</p>
      </form>
    </div></div>

    <div class="card flush mt-1" id="inNow"><div class="card-head"><h3>In the club now ({{ $inNow->sum('pax') }})</h3></div>
      <div class="card-body table-wrap"><table class="table">
        <tbody>@forelse($inNow as $v)
          <tr data-visit="{{ $v->id }}"><td>{{ $v->name }}<div class="tiny muted">{{ \App\Models\ClubVisit::TYPES[$v->visitor_type] }}{{ $v->pax > 1 ? ' · '.$v->pax.' people' : '' }}</div></td><td class="small">{{ $F[$v->facility] ?? $v->facility }}<div class="tiny muted">since {{ $v->checked_in_at->format('H:i') }}</div></td>
            <td class="num"><form method="post" action="{{ route('club.checkout', $v) }}">@csrf<button class="btn btn-sm">Out</button></form></td></tr>
        @empty <tr><td><x-empty text="Nobody is checked in." icon="users"/></td></tr> @endforelse</tbody></table></div></div>
  </div>
</div>

@if($person)
<div class="modal" id="guestModal"><div class="modal-box"><form method="post" action="{{ route('club.guest') }}" data-reset>@csrf
  <div class="modal-head"><h3>{{ $person->name }}'s guest</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><input type="hidden" name="club_member_id" value="{{ $person->id }}"><input type="hidden" name="facility" value="{{ $facility }}">
    <x-field name="name" label="Guest's name" required/>
    @if($passesLeft > 0)<p class="small text-green">Uses 1 of {{ $passesLeft }} free guest pass(es) left this month.</p>
    @else<p class="small">No free guest passes left this month – a guest pass of {{ money(\App\Services\ClubService::cfg('club_guestpass')) }} + tax is charged.</p>
      <div id="clubGuestSingleTender" class="form-grid">
        <div class="field span-all"><label for="g_mode">Pay now by</label><select name="payment_mode_id" id="g_mode"><option value="">Charge to the member's account</option>@foreach($modes as $pm)<option value="{{ $pm->id }}">{{ $pm->name }}</option>@endforeach</select></div>
        <x-field name="momo_network" label="MoMo network"/><x-field name="momo_phone" label="MoMo phone"/>
        <div class="field span-all"><label>Reference</label><input name="reference" maxlength="80" placeholder="optional"></div>
      </div>
      <x-payment-split-tender widget-id="clubGuestSplitTender" single-id="clubGuestSingleTender" :modes="$splitModes" label="guest-pass payment" :show-cheque="false"/>
    @endif
    <p class="tiny muted">The member must be present and is responsible for their guest.</p>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="guestSave">Check guest in</button></div>
</form></div></div>
@if(can_do('club.manage'))
<div class="modal" id="overrideModal"><div class="modal-box"><form method="post" action="{{ route('club.checkin') }}" data-reset>@csrf
  <div class="modal-head"><h3>Override the refusal</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><input type="hidden" name="club_member_id" value="{{ $person->id }}"><input type="hidden" name="facility" value="{{ $facility }}">
    <ul class="small">@foreach($check['reasons'] as $r)<li>{{ $r }}</li>@endforeach</ul>
    <x-field name="override" label="Reason for letting the member in" required placeholder="Paid by MoMo – receipt to follow"/>
    <p class="tiny muted">Overrides are recorded against your name in the visit log and the activity log.</p>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-warning" id="overrideSave">Let in</button></div>
</form></div></div>
@endif
@endif
@endsection
@push('scripts')
<script>document.getElementById('facility')?.addEventListener('change', e => { const q = document.getElementById('q'); if (q.value || {{ $person ? 'true' : 'false' }}) { const u = new URL(location.href); u.searchParams.set('facility', e.target.value); location.href = u; } });</script>
@endpush
