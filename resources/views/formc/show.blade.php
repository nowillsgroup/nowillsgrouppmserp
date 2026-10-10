@extends('layouts.app')
@section('title', 'Form C '.$f->form_no)
@section('crumbs') Foreign Guests / <a href="{{ route('fg.formc') }}">Form C</a> / {{ $f->form_no }} @endsection
@section('actions')
  <a class="btn" href="{{ route('fg.formc.print', $f) }}" target="_blank" id="formcPrint"><x-icon name="print"/> Print</a>
  @if($f->stay)<a class="btn" href="{{ route('reservations.show', $f->reservation_id) }}"><x-icon name="calendar"/> Reservation</a>@endif
@endsection
@section('content')
@php
  $st = $f->state();
  $manage = can_do('formc.manage');
  $editable = $manage && $f->status === 'pending';
  $v = fn ($k) => $f->{$k} instanceof \DateTimeInterface ? $f->{$k}->format('Y-m-d') : $f->{$k};
@endphp
@foreach(['formc', 'submit', 'departure', 'exempt', 'reopen'] as $e)@error($e)<div class="alert alert-danger" id="err_{{ $e }}"><x-icon name="alert"/><div>{{ $message }}</div></div>@enderror @endforeach

<div class="card" id="formcHead"><div class="card-body flex" style="gap:1.5rem;flex-wrap:wrap;align-items:center">
  <div><div class="tiny muted">Foreign national</div><div class="bold" style="font-size:1.15rem">{{ $f->name() }}</div>
    <div class="small">{{ $f->nationality ?: 'nationality not set' }}@if($f->is_companion) · <span class="badge badge-slate">companion</span>@endif @if($f->guest) · <a href="{{ route('guests.show', $f->guest) }}">profile</a>@endif</div></div>
  <div><div class="tiny muted">Room · stay</div><div class="bold">{{ $f->stay?->room?->room_no ?? '—' }}</div><div class="small">{{ $f->reservation?->reservation_no }} · till {{ $f->expected_checkout?->format('d M') }}</div></div>
  <div><div class="tiny muted">Checked in</div><div class="bold">{{ $f->checked_in_at?->format('d M Y H:i') }}</div>@if($f->departed_at)<div class="small">out {{ $f->departed_at->format('d M H:i') }}</div>@endif</div>
  <div><div class="tiny muted">File with FRRO by</div><div class="bold {{ $f->isOverdue() ? 'text-red' : '' }}" id="formcDeadline">{{ $f->deadline_at?->format('d M Y H:i') }}</div>
    @if($f->status === 'pending')<div class="small {{ $f->isOverdue() ? 'text-red' : 'muted' }}">{{ $f->isOverdue() ? 'overdue by '.$f->deadline_at->diffForHumans(null, true) : 'in '.now()->diffForHumans($f->deadline_at, true) }}</div>@endif</div>
  <div class="ml-auto" id="formcState">{!! formc_badge($st) !!}</div>
</div>
<div class="card-foot flex" style="gap:.5rem;flex-wrap:wrap">
  @if($manage)
    @if($f->status === 'pending')
      <button class="btn btn-success" data-modal="submitModal" id="submitBtn" @disabled(count($missing))><x-icon name="check"/> Record FRRO submission</button>
      <button class="btn btn-ghost" data-modal="exemptModal" id="exemptBtn">Mark exempt</button>
    @endif
    @if($f->departurePending())<button class="btn btn-primary" data-modal="departureModal" id="departureBtn"><x-icon name="plane"/> Record departure report</button>@endif
    @if(in_array($f->status, ['submitted', 'exempt']))<button class="btn btn-ghost" data-modal="reopenModal" id="reopenBtn"><x-icon name="refresh"/> Re-open to correct</button>@endif
  @endif
  @if($f->status === 'pending' && count($missing))<span class="small text-red" id="missingNote">{{ count($missing) }} item(s) missing: {{ implode(', ', array_slice($missing, 0, 6)) }}{{ count($missing) > 6 ? '…' : '' }}</span>@endif
  @if($f->status === 'pending' && ! count($missing))<span class="small text-green" id="readyNote">Complete – file it on indianfrro.gov.in and record the reference.</span>@endif
</div></div>

@foreach($warnings as $k => $w)<div class="alert alert-warn" data-warning="{{ $k }}"><x-icon name="alert"/><div>{{ $w }}</div></div>@endforeach

<div class="grid g-2-1">
<div>
<form method="post" action="{{ route('fg.formc.update', $f) }}" id="formcForm">@csrf @method('PUT')
<fieldset @disabled(! $editable) style="border:0;padding:0;margin:0">
  <div class="card"><div class="card-head"><h3>Personal</h3></div><div class="card-body form-grid">
    <x-field name="surname" label="Surname (as on passport)" :value="$v('surname')"/>
    <x-field name="given_name" label="Given name(s)" :value="$v('given_name')"/>
    <x-field name="gender" label="Gender" type="select" :options="\App\Models\FormCRecord::GENDERS" :value="$v('gender')"/>
    <x-field name="dob" label="Date of birth" type="date" :value="$v('dob')"/>
    <x-field name="nationality" label="Nationality" :value="$v('nationality')"/>
    <x-field name="phone" label="Phone" :value="$v('phone')"/>
    <x-field name="address_abroad" label="Permanent address abroad" :value="$v('address_abroad')" class="span-all"/>
  </div></div>
  <div class="card"><div class="card-head"><h3>Passport</h3></div><div class="card-body form-grid">
    <x-field name="passport_no" label="Passport no." :value="$v('passport_no')"/>
    <x-field name="passport_place" label="Place of issue" :value="$v('passport_place')"/>
    <x-field name="passport_issued_on" label="Date of issue" type="date" :value="$v('passport_issued_on')"/>
    <x-field name="passport_expires_on" label="Valid until" type="date" :value="$v('passport_expires_on')"/>
  </div></div>
  <div class="card"><div class="card-head"><h3>Visa</h3>@if($visaExempt)<span class="small muted">{{ $f->nationality }} nationals need no visa – only the visa type is asked.</span>@endif</div><div class="card-body form-grid">
    <x-field name="visa_type" label="Visa type" type="select" :options="\App\Models\FormCRecord::VISA_TYPES" :value="$v('visa_type')"/>
    <x-field name="visa_no" label="Visa / OCI no." :value="$v('visa_no')"/>
    <x-field name="visa_place" label="Place of issue" :value="$v('visa_place')"/>
    <x-field name="visa_issued_on" label="Date of issue" type="date" :value="$v('visa_issued_on')"/>
    <x-field name="visa_expires_on" label="Valid until" type="date" :value="$v('visa_expires_on')"/>
  </div></div>
  <div class="card"><div class="card-head"><h3>Travel</h3></div><div class="card-body form-grid">
    <x-field name="arrived_india_on" label="Date of arrival in India" type="date" :value="$v('arrived_india_on')"/>
    <x-field name="port_of_arrival" label="Port of arrival in India" :value="$v('port_of_arrival')" placeholder="e.g. Delhi (IGI) airport"/>
    <x-field name="arrived_from" label="Arrived at the hotel from (city, country)" :value="$v('arrived_from')"/>
    <x-field name="next_destination" label="Next destination" :value="$v('next_destination')"/>
    <x-field name="purpose" label="Purpose of visit" type="select" :options="\App\Models\FormCRecord::PURPOSES" :value="$v('purpose')"/>
    <x-field name="notes" label="Desk notes" type="textarea" :value="$v('notes')" class="span-all"/>
  </div></div>
</fieldset>
@if($editable)
  <div class="flex" style="gap:1rem;align-items:center;margin-bottom:1rem">
    <button class="btn btn-primary" id="saveFormc"><x-icon name="check"/> Save</button>
    @if($f->guest_id)<label class="check"><input type="checkbox" name="sync_profile" value="1" checked> Also update the guest profile (nationality, passport, visa)</label>@endif
  </div>
@endif
</form>
</div>

<div>
  <div class="card" id="scanCard"><div class="card-head"><h3>Scans</h3></div><div class="card-body">
    @if(! $f->guest_id)
      <p class="small muted">Companion without a guest profile – check the passport and visa on paper.</p>
    @else
      <div class="flex" style="gap:.5rem;margin-bottom:.6rem">
        <span class="badge badge-{{ $scans['passport'] ? 'green' : 'red' }}" id="scanPassport">Passport {{ $scans['passport'] ? '✓' : 'missing' }}</span>
        @unless($f->visa_type === 'none' || $visaExempt)<span class="badge badge-{{ $scans['visa'] ? 'green' : 'red' }}" id="scanVisa">Visa {{ $scans['visa'] ? '✓' : 'missing' }}</span>@endunless
      </div>
      @foreach($scans['docs']->whereIn('type', ['passport', 'visa', 'id_front', 'id_back']) as $d)
        <div class="small"><a href="{{ route('guests.documents.show', $d) }}" target="_blank">{{ \App\Models\GuestDocument::TYPES[$d->type] ?? $d->type }}</a> <span class="muted">· {{ $d->created_at?->format('d M Y') }}</span></div>
      @endforeach
      @if((can_do('frontdesk.checkin') || can_do('guests.manage')) && ! $f->guest?->isPrivacyLocked())
      <form method="post" action="{{ route('guests.documents.store', $f->guest_id) }}" enctype="multipart/form-data" class="mt-1" id="scanUpload">@csrf
        <input type="hidden" name="reservation_room_id" value="{{ $f->reservation_room_id }}">
        <div class="grid" style="gap:.45rem">
          <select class="input" name="type" aria-label="Scan type"><option value="passport">Passport – photo page</option><option value="visa" @selected($scans['passport'] && ! $scans['visa'])>Visa / permit</option></select>
          <input class="input" type="file" name="file" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
          <div><button class="btn btn-sm"><x-icon name="download" size="sm"/> Upload scan</button></div>
        </div></form>
      @endif
    @endif
  </div></div>

  <div class="card" id="filingCard"><div class="card-head"><h3>Filing</h3></div><div class="card-body small">
    <table class="table table-compact">
      <tr><th>Status</th><td>{{ \App\Models\FormCRecord::STATUSES[$f->status] ?? $f->status }}</td></tr>
      @if($f->submitted_at)<tr><th>Filed</th><td>{{ $f->submitted_at->format('d M Y H:i') }} · {{ $f->submitter?->name }}@if($f->isLate()) <span class="badge badge-amber">late</span>@endif</td></tr>
      <tr><th>FRRO reference</th><td class="bold" id="frroRef">{{ $f->frro_ref }}</td></tr>@endif
      @if($f->late_reason)<tr><th>Reason for delay</th><td>{{ $f->late_reason }}</td></tr>@endif
      @if($f->departure_submitted_at)<tr><th>Departure reported</th><td>{{ $f->departure_submitted_at->format('d M Y H:i') }} · {{ $f->departureUser?->name }} {{ $f->departure_ref ? '· '.$f->departure_ref : '' }}</td></tr>@endif
      @if($f->exempt_reason)<tr><th>Exempt because</th><td>{{ $f->exempt_reason }}</td></tr>@endif
    </table>
  </div></div>

  <div class="card" id="roomMates"><div class="card-head"><h3>Others in the room</h3></div><div class="card-body small">
    @forelse($others as $o)
      <div><a href="{{ route('fg.formc.show', $o) }}">{{ $o->form_no }}</a> {{ $o->name() }} · {{ $o->nationality }} {!! formc_badge($o->state()) !!}</div>
    @empty<p class="muted">No other Form C for this stay.</p>@endforelse
    @if($manage && $f->stay && $f->stay->status === 'checked_in')
      <form method="post" action="{{ route('fg.formc.create', $f->stay) }}" class="mt-1" id="companionForm">@csrf <input type="hidden" name="companion" value="1">
        <div class="tiny muted mb-1">Every foreign national needs their own Form C – add a companion sharing the room:</div>
        <div class="grid" style="grid-template-columns:1fr 1fr;gap:.45rem"><input class="input" name="given_name" placeholder="Given name" required><input class="input" name="surname" placeholder="Surname">
          <input class="input" name="nationality" placeholder="Nationality" required><button class="btn btn-sm"><x-icon name="plus" size="sm"/> Add companion</button></div>
      </form>
    @endif
  </div></div>
</div>
</div>

@if($manage)
<div class="modal" id="submitModal"><div class="modal-box"><form method="post" action="{{ route('fg.formc.submit', $f) }}">@csrf
  <div class="modal-head"><h3>Filed on the FRRO portal</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <x-field name="frro_ref" label="FRRO reference / application no." required placeholder="as shown by indianfrro.gov.in"/>
    <x-field name="submitted_at" label="Filed at" type="datetime-local" :value="now()->format('Y-m-d\TH:i')"/>
    @if($f->isOverdue())<x-field name="late_reason" label="Why was it late?" required hint="Required – the deadline passed at {{ $f->deadline_at->format('d M H:i') }}"/>
    @else<x-field name="late_reason" label="Reason for delay (only if filed after the deadline)" hint="Deadline {{ $f->deadline_at?->format('d M H:i') }}"/>@endif
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-success" id="confirmSubmit">Record submission</button></div>
</form></div></div>
<div class="modal" id="departureModal"><div class="modal-box"><form method="post" action="{{ route('fg.formc.departure', $f) }}">@csrf
  <div class="modal-head"><h3>Departure reported to FRRO</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <x-field name="departure_ref" label="Reference (optional)"/>
    <x-field name="departure_submitted_at" label="Reported at" type="datetime-local" :value="now()->format('Y-m-d\TH:i')"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="confirmDeparture">Record</button></div>
</form></div></div>
<div class="modal" id="exemptModal"><div class="modal-box"><form method="post" action="{{ route('fg.formc.exempt', $f) }}">@csrf
  <div class="modal-head"><h3>Mark exempt</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required placeholder="e.g. Indian national – nationality recorded wrongly / diplomat"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-danger" id="confirmExempt">Mark exempt</button></div>
</form></div></div>
<div class="modal" id="reopenModal"><div class="modal-box"><form method="post" action="{{ route('fg.formc.reopen', $f) }}">@csrf
  <div class="modal-head"><h3>Re-open {{ $f->form_no }}</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="What needs correcting?" required/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="confirmReopen">Re-open</button></div>
</form></div></div>
@endif
@endsection
